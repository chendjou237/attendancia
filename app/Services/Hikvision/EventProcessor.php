<?php

namespace App\Services\Hikvision;

use App\Models\Device;
use App\Models\RawEvent;
use App\Models\TeacherBiometricId;
use Illuminate\Support\Facades\Log;

/**
 * §7.2: the single point both the live stream and the AcsEvent backfill
 * converge on — "do not fork the filtering logic between the two
 * paths." Both call handle() with the same NormalizedEvent shape and
 * this class can't tell which path it came from except via $source,
 * which exists only for log messages, not to branch behaviour on.
 *
 * §7.3 event codes:
 *   majorEventType 5           -> process
 *   majorEventType 2, 3        -> ignore (device/network system events)
 *   subEventType 38 (passed)   -> store, resolve teacher_id
 *   subEventType 1  (card)     -> store, log only, NO teacher resolution
 *   subEventType 49 (failed)   -> store, log only, no teacher resolution
 *   eventType videoloss        -> heartbeat, no ACS payload, liveness only
 *
 * Card verification (subEventType 1) arrives from the DS-K1T8005EFX,
 * whose "E" designation adds a 125kHz EM proximity card reader
 * alongside the fingerprint sensor. This answers what the code used to
 * call open question #2 — the sub-type is now known, and the answer is
 * that attendance deliberately does not accept it. A proximity card can
 * be lent to a colleague or cloned; a fingerprint cannot, and these
 * records become payable hours. So a card event is stored as evidence
 * that someone badged in, and stops there.
 */
class EventProcessor
{
    private const SUB_EVENT_PASSED = 38;

    /**
     * Card verification passed. Recognised so it can be refused
     * explicitly and logged for what it is — not accepted.
     */
    private const SUB_EVENT_CARD_PASSED = 1;

    private const SUB_EVENT_FAILED = 49;

    public function handle(NormalizedEvent $event, Device $device, string $source): void
    {
        // Every event — including a heartbeat with no ACS payload —
        // counts as liveness (§7.3).
        $device->forceFill(['last_seen_at' => now()])->save();

        if ($event->isHeartbeat()) {
            return;
        }

        if ($event->majorEventType !== 5) {
            return;
        }

        if ($event->deviceEventSerial === null) {
            Log::channel('attendance')->warning('EventProcessor: majorEventType 5 event with no serialNo, cannot store', [
                'device_id' => $device->id,
                'source' => $source,
                'raw' => $event->raw,
            ]);

            return;
        }

        // §7.2: "the unique index makes it idempotent" — a live event
        // re-delivered after a reconnect, or a backfill window that
        // overlaps one already ingested, is a silent no-op rather than
        // a duplicate row.
        $alreadyStored = RawEvent::query()
            ->where('device_serial', $device->serial)
            ->where('device_event_serial', $event->deviceEventSerial)
            ->exists();

        if ($alreadyStored) {
            return;
        }

        // Resolved before the row is created, not after: raw_events is
        // append-only (§14, "no updates, no deletes, ever") and
        // teacher_id must be right in the single insert rather than
        // corrected via a later update() call.
        $teacherId = $this->resolveTeacherId($event, $device);

        $raw = RawEvent::create([
            'device_id' => $device->id,
            'device_serial' => $device->serial,
            'device_event_serial' => $event->deviceEventSerial,
            'biometric_id' => $event->biometricId,
            'event_time_device' => $event->eventTimeDevice,
            'event_time_server' => now(),
            'major_event_type' => $event->majorEventType,
            'sub_event_type' => $event->subEventType,
            'payload_json' => $event->raw,
            'teacher_id' => $teacherId,
        ]);

        if ($event->subEventType === self::SUB_EVENT_FAILED) {
            Log::channel('attendance')->info('Fingerprint failed / unenrolled', [
                'raw_event_id' => $raw->id,
                'device_id' => $device->id,
                'source' => $source,
                'biometric_id' => $event->biometricId,
            ]);

            return;
        }

        if ($event->subEventType === self::SUB_EVENT_CARD_PASSED) {
            // Info, not debug, and on the attendance channel: this is a
            // real person who believes they just checked in. When a
            // teacher disputes an absence, this line is the evidence
            // that they badged rather than scanned.
            Log::channel('attendance')->info('Card scan ignored — attendance requires a fingerprint', [
                'raw_event_id' => $raw->id,
                'device_id' => $device->id,
                'source' => $source,
                'biometric_id' => $event->biometricId,
            ]);

            return;
        }

        if ($event->subEventType !== self::SUB_EVENT_PASSED) {
            // Face credentials, and anything else this firmware emits
            // under majorEventType 5, still land here: stored, logged
            // with the full payload so the code can be identified, and
            // never resolved to a teacher on a guess.
            Log::debug('EventProcessor: unrecognised sub_event_type under majorEventType 5', [
                'raw_event_id' => $raw->id,
                'sub_event_type' => $event->subEventType,
                'raw' => $event->raw,
            ]);
        }
    }

    /**
     * Only a passed FINGERPRINT scan (subEventType 38) ever resolves to
     * a teacher — a failed, card, or unrecognised event must never end
     * up attributed to one just because its biometric_id happens to
     * match.
     *
     * This single condition is what keeps card scans out of payroll.
     * PairingEngine::pair() selects candidate events by teacher_id, so
     * an event with teacher_id null can never be paired into a session
     * and can never become a Present. Widening this to accept
     * SUB_EVENT_CARD_PASSED would silently make every card swipe count
     * as taught time — which is the decision this class exists to
     * refuse. tests/Feature/Hikvision/EventProcessorTest.php holds it
     * down end to end.
     */
    private function resolveTeacherId(NormalizedEvent $event, Device $device): ?int
    {
        if ($event->subEventType !== self::SUB_EVENT_PASSED) {
            return null;
        }

        if ($event->biometricId === null) {
            Log::channel('attendance')->warning('EventProcessor: subEventType 38 (passed) with no employeeNoString', [
                'device_id' => $device->id,
                'device_event_serial' => $event->deviceEventSerial,
            ]);

            return null;
        }

        $teacher = TeacherBiometricId::resolve($event->biometricId, $event->eventTimeDevice ?? now());

        return $teacher?->id;
    }
}
