<?php

namespace App\Services\Hikvision;

use Carbon\CarbonImmutable;

/**
 * The canonical shape both ingestion paths converge on before reaching
 * EventProcessor (§7.2: "Do not fork the filtering logic between the
 * two paths") — device identity is deliberately not part of this: the
 * caller (stream worker or backfill command) already knows which
 * Device it's talking to, which is more reliable than trusting a
 * device to self-report its own identity inside the payload.
 */
final class NormalizedEvent
{
    public function __construct(
        // Nullable: a videoloss heartbeat carries no AccessControllerEvent
        // payload at all, so there is no event-level serial to read.
        public readonly ?int $deviceEventSerial,
        public readonly ?string $biometricId,
        public readonly ?CarbonImmutable $eventTimeDevice,
        public readonly ?int $majorEventType,
        public readonly ?int $subEventType,
        public readonly ?string $eventType,
        public readonly array $raw,
    ) {}

    public function isHeartbeat(): bool
    {
        return $this->eventType === 'videoloss' || $this->majorEventType === null;
    }
}
