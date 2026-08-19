<?php

namespace App\Services\Hikvision;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The live stream and the AcsEvent search/backfill endpoint describe
 * the same event with different field names — Hikvision's ISAPI is
 * not internally consistent about this. Normalizing both into one
 * shape here is what lets EventProcessor stay a single, unforked
 * pipeline (§7.2).
 *
 * The stream shape is taken directly from the confirmed sample payload
 * in the spec. The AcsEvent search shape below follows Hikvision's
 * documented ISAPI convention (`time`/`major`/`minor` instead of
 * `dateTime`/`majorEventType`/`subEventType`) but is UNVERIFIED against
 * this specific unit's firmware — §13.5 requires confirming exact field
 * spellings against a real device before trusting them. fromAcsEventRecord()
 * therefore tries the documented name first, falls back to the stream's
 * naming in case this firmware is more consistent than most, and logs a
 * warning rather than silently guessing when a field is missing
 * entirely — the same "stop rather than guess" principle applied to
 * code that still has to run without a live device in front of it.
 */
class EventNormalizer
{
    public function fromStreamPayload(array $payload): NormalizedEvent
    {
        $acs = $payload['AccessControllerEvent'] ?? null;

        return new NormalizedEvent(
            deviceEventSerial: isset($acs['serialNo']) ? (int) $acs['serialNo'] : null,
            biometricId: $this->normalizeBiometricId($acs['employeeNoString'] ?? null),
            eventTimeDevice: $this->parseTime($payload['dateTime'] ?? null),
            majorEventType: isset($acs['majorEventType']) ? (int) $acs['majorEventType'] : null,
            subEventType: isset($acs['subEventType']) ? (int) $acs['subEventType'] : null,
            eventType: $payload['eventType'] ?? null,
            raw: $payload,
        );
    }

    public function fromAcsEventRecord(array $record): NormalizedEvent
    {
        $serial = $record['serialNo'] ?? null;
        $major = $record['major'] ?? $record['majorEventType'] ?? null;
        $minor = $record['minor'] ?? $record['subEventType'] ?? null;
        $time = $record['time'] ?? $record['dateTime'] ?? null;
        $employeeNo = $record['employeeNoString'] ?? $record['employeeNo'] ?? null;

        if ($major === null || $minor === null || $time === null) {
            Log::channel('attendance')->warning('EventNormalizer: AcsEvent record missing an expected field — unverified field names (§13.5)', [
                'record' => $record,
            ]);
        }

        return new NormalizedEvent(
            deviceEventSerial: $serial !== null ? (int) $serial : null,
            biometricId: $this->normalizeBiometricId($employeeNo),
            eventTimeDevice: $this->parseTime($time),
            majorEventType: $major !== null ? (int) $major : null,
            subEventType: $minor !== null ? (int) $minor : null,
            eventType: $record['eventType'] ?? null,
            raw: $record,
        );
    }

    private function normalizeBiometricId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * The device sends its own UTC offset; parse it, never assume it
     * matches the app timezone (§14). Falls back to null (not now())
     * on a parse failure so EventProcessor can decide how to handle a
     * genuinely unparseable timestamp rather than silently substituting
     * the wrong instant.
     */
    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable $e) {
            Log::channel('attendance')->warning('EventNormalizer: could not parse event timestamp', [
                'value' => $value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
