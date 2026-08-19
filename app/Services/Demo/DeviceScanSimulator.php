<?php

namespace App\Services\Demo;

use App\Models\Device;
use App\Models\RawEvent;
use App\Services\Hikvision\EventNormalizer;
use App\Services\Hikvision\EventProcessor;
use Carbon\CarbonInterface;

/**
 * A single, immediate scan — the on-demand counterpart to
 * AttendanceSimulator, which simulates a whole day's sessions in one
 * batch. This is for a human clicking "Simulate scan" right now and
 * expecting to see it land, so a device serial can't be pre-planned
 * the way AttendanceSimulator's per-run counter is: it's read fresh
 * from the highest serial this device has ever produced.
 *
 * Goes through the same EventNormalizer -> EventProcessor pipeline as
 * a real stream payload — this is what makes the mock button prove
 * the live view actually works, not just that a button was clicked.
 */
class DeviceScanSimulator
{
    private int $serialCounter = 0;

    public function __construct(
        private readonly EventNormalizer $normalizer,
        private readonly EventProcessor $processor,
    ) {}

    public function scan(Device $device, string $biometricId, ?CarbonInterface $at = null): RawEvent
    {
        $at ??= now();
        $serial = $this->nextSerial($device);

        $payload = [
            'ipAddress' => $device->ip,
            'dateTime' => $at->toIso8601String(),
            'eventType' => 'AccessControllerEvent',
            'eventState' => 'active',
            'AccessControllerEvent' => [
                'deviceName' => $device->serial,
                'majorEventType' => 5,
                'subEventType' => 38,
                'serialNo' => $serial,
                'employeeNoString' => $biometricId,
                'currentVerifyMode' => 'fp',
            ],
        ];

        $this->processor->handle($this->normalizer->fromStreamPayload($payload), $device, 'mock');

        return RawEvent::query()
            ->where('device_serial', $device->serial)
            ->where('device_event_serial', $serial)
            ->firstOrFail();
    }

    private function nextSerial(Device $device): int
    {
        $this->serialCounter = max(
            $this->serialCounter,
            (int) (RawEvent::query()->where('device_serial', $device->serial)->max('device_event_serial') ?? 0),
        );

        return ++$this->serialCounter;
    }
}
