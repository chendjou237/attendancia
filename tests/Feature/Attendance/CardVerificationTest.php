<?php

use App\Enums\PeriodStatus;
use App\Enums\SessionAnomaly;
use App\Enums\SessionState;
use App\Models\RawEvent;
use App\Models\TeacherBiometricId;
use App\Services\Attendance\PairingEngine;
use App\Services\Attendance\PeriodResultWriter;
use App\Services\Attendance\RuleEngine;
use App\Services\Attendance\SessionBuilder;
use App\Services\Hikvision\EventNormalizer;
use App\Services\Hikvision\EventProcessor;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\Support\AttendanceFixture;

/**
 * The DS-K1T8005EFX can read a 125kHz EM proximity card as well as a
 * fingerprint. A card can be lent to a colleague or cloned, and these
 * records become payable hours, so attendance deliberately does not
 * accept one.
 *
 * These tests go through the real ingestion pipeline
 * (EventNormalizer -> EventProcessor) rather than creating RawEvents
 * directly the way AttendanceFixture::scanAt() does — the whole point
 * is what EventProcessor decides about teacher_id, which a
 * hand-built row would bypass.
 */
function ingestCardTestScan(AttendanceFixture $f, string $time, int $subEventType, int $serial): RawEvent
{
    $at = Carbon::parse($f->date->toDateString().' '.$time, config('attendance.timezone'));

    (new EventProcessor)->handle(
        (new EventNormalizer)->fromStreamPayload([
            'dateTime' => $at->toIso8601String(),
            'eventType' => 'AccessControllerEvent',
            'AccessControllerEvent' => [
                'majorEventType' => 5,
                'subEventType' => $subEventType,
                'serialNo' => $serial,
                'employeeNoString' => '4242',
            ],
        ]),
        $f->device,
        'stream',
    );

    return RawEvent::where('device_event_serial', $serial)->firstOrFail();
}

function enrolForCardTest(AttendanceFixture $f): void
{
    TeacherBiometricId::factory()->for($f->teacher)->create([
        'biometric_id' => '4242',
        'valid_from' => $f->date->copy()->subYear()->toDateString(),
        'valid_to' => null,
    ]);
}

function computeCardSession(AttendanceFixture $f): Collection
{
    $builder = new SessionBuilder;
    $session = $builder->persist($f->teacher, $f->date, $builder->group($f->teacher, $f->date))->first()
        ->fresh(['firstSlot', 'lastSlot', 'teacher', 'classCode']);

    (new PairingEngine)->pair($session, $f->rule);
    $session->refresh()->load('scanInEvent', 'scanOutEvent', 'firstSlot', 'lastSlot', 'teacher', 'classCode');

    return (new RuleEngine($builder, new PeriodResultWriter))->computeForSession($session, $f->rule);
}

// The control. Without this, the test below could pass because the
// scenario never worked in the first place rather than because cards
// are refused.
it('marks the period Present when the same two scans are fingerprints', function () {
    $f = AttendanceFixture::make();
    enrolForCardTest($f);

    ingestCardTestScan($f, '07:32:00', 38, 5001);
    ingestCardTestScan($f, '08:24:00', 38, 5002);

    $results = computeCardSession($f);

    expect($results)->toHaveCount(1)
        ->and($results->first()->status)->toBe(PeriodStatus::Present);
});

// The guard. Identical timing, identical teacher, identical device —
// only the credential differs.
it('never counts a card scan as attendance, however well it fits the session', function () {
    $f = AttendanceFixture::make();
    enrolForCardTest($f);

    ingestCardTestScan($f, '07:32:00', 1, 5001);
    ingestCardTestScan($f, '08:24:00', 1, 5002);

    $results = computeCardSession($f);

    expect($results)->toHaveCount(1)
        ->and($results->first()->status)->toBe(PeriodStatus::Unpaired);
});

// Card events are still stored. raw_events is the evidence trail, and
// "a teacher badged at 07:32 and it did not count" is exactly what a
// pay dispute needs to be able to establish.
it('stores the card scan as evidence but leaves it unattributed', function () {
    $f = AttendanceFixture::make();
    enrolForCardTest($f);

    $raw = ingestCardTestScan($f, '07:32:00', 1, 5001);

    expect($raw->sub_event_type)->toBe(1)
        ->and($raw->teacher_id)->toBeNull()
        // The employee number is preserved in the payload even though
        // it was deliberately not resolved, so who badged is still
        // recoverable after the fact.
        ->and($raw->payload_json['AccessControllerEvent']['employeeNoString'])->toBe('4242');
});

it('leaves the session unpaired with a no-scan-in anomaly when only cards were used', function () {
    $f = AttendanceFixture::make();
    enrolForCardTest($f);

    ingestCardTestScan($f, '07:32:00', 1, 5001);
    ingestCardTestScan($f, '08:24:00', 1, 5002);

    computeCardSession($f);

    $session = $f->teacher->sessions()->first();

    expect($session->state)->toBe(SessionState::Unpaired)
        ->and($session->anomaly_code)->toBe(SessionAnomaly::NoScanIn->value);
});
