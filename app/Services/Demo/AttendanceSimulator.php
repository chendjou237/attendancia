<?php

namespace App\Services\Demo;

use App\Models\Device;
use App\Models\Teacher;
use App\Services\Attendance\SessionBuilder;
use App\Services\Attendance\SessionGrouping;
use App\Services\Hikvision\EventNormalizer;
use App\Services\Hikvision\EventProcessor;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Generates synthetic scans and runs them through the real ingestion
 * pipeline (EventNormalizer::fromStreamPayload -> EventProcessor),
 * exactly as a live device would — a presentation demo is much more
 * convincing showing the actual engine compute real results from
 * simulated input than showing fabricated period_results directly.
 *
 * Session boundaries come from the real SessionBuilder, not
 * reimplemented here, so the simulator's scan timing always lines up
 * with what PairingEngine will actually look for.
 */
class AttendanceSimulator
{
    private int $serialCounter = 1;

    public function __construct(
        private readonly SessionBuilder $sessionBuilder,
        private readonly EventNormalizer $normalizer,
        private readonly EventProcessor $processor,
    ) {}

    public function simulateDay(Teacher $teacher, Device $device, CarbonInterface $date, string $biometricId): void
    {
        $groupings = $this->sessionBuilder->group($teacher, $date);

        foreach ($groupings as $grouping) {
            $this->simulateSession($teacher, $device, $date, $grouping, $biometricId);
        }
    }

    private function simulateSession(Teacher $teacher, Device $device, CarbonInterface $date, SessionGrouping $grouping, string $biometricId): void
    {
        $tz = config('attendance.timezone');
        $dateString = $date->toDateString();
        $start = Carbon::parse($dateString.' '.$grouping->firstSlot->start_time, $tz);
        $end = Carbon::parse($dateString.' '.$grouping->lastSlot->end_time, $tz);

        $roll = mt_rand(1, 100);

        match (true) {
            $roll <= 65 => $this->emitPair($teacher, $device, $biometricId, // on time
                $start->clone()->addMinutes(mt_rand(1, 4)),
                $end->clone()->subMinutes(mt_rand(0, 2)),
            ),
            $roll <= 78 => $this->emitPair($teacher, $device, $biometricId, // late but inside grace
                $start->clone()->addMinutes(mt_rand(5, 9)),
                $end->clone(),
            ),
            $roll <= 88 => $this->emitPair($teacher, $device, $biometricId, // late beyond grace — some periods present, some absent
                $start->clone()->addMinutes(mt_rand(18, 35)),
                $end->clone(),
            ),
            $roll <= 94 => $this->emitScan($teacher, $device, $biometricId, $start->clone()->addMinutes(mt_rand(1, 4))), // scan-in only
            default => null, // absent — no scans at all
        };
    }

    private function emitPair(Teacher $teacher, Device $device, string $biometricId, Carbon $in, Carbon $out): void
    {
        $this->emitScan($teacher, $device, $biometricId, $in);
        $this->emitScan($teacher, $device, $biometricId, $out);
    }

    private function emitScan(Teacher $teacher, Device $device, string $biometricId, Carbon $localTime): void
    {
        $payload = [
            'ipAddress' => $device->ip,
            'dateTime' => $localTime->toIso8601String(),
            'eventType' => 'AccessControllerEvent',
            'eventState' => 'active',
            'AccessControllerEvent' => [
                'deviceName' => $device->serial,
                'majorEventType' => 5,
                'subEventType' => 38,
                'serialNo' => $this->serialCounter++,
                'employeeNoString' => $biometricId,
                'currentVerifyMode' => 'fp',
            ],
        ];

        $this->processor->handle($this->normalizer->fromStreamPayload($payload), $device, 'demo');
    }
}
