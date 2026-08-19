<?php

use App\Enums\SessionAnomaly;
use App\Enums\SessionState;
use App\Models\ClassCode;
use App\Models\TimetableEntry;
use App\Services\Attendance\PairingEngine;
use App\Services\Attendance\SessionBuilder;
use Tests\Support\AttendanceFixture;

function pairedSession(AttendanceFixture $f): \App\Models\AttendanceSession
{
    $builder = new SessionBuilder;
    $session = $builder->persist($f->teacher, $f->date, $builder->group($f->teacher, $f->date))->first();

    return $session->fresh(['firstSlot', 'lastSlot']);
}

it('pairs a normal scan-in and scan-out', function () {
    $f = AttendanceFixture::make();
    $session = pairedSession($f);
    $f->scanAt('07:28:00');
    $f->scanAt('08:20:00');

    (new PairingEngine)->pair($session, $f->rule);
    $session->refresh();

    expect($session->state)->toBe(SessionState::Paired);
    expect($session->anomaly_code)->toBeNull();
    expect($session->scan_in_event_id)->not->toBe($session->scan_out_event_id);
});

it('marks no_scan_in when the teacher never scans', function () {
    $f = AttendanceFixture::make();
    $session = pairedSession($f);

    (new PairingEngine)->pair($session, $f->rule);
    $session->refresh();

    expect($session->state)->toBe(SessionState::Unpaired);
    expect($session->anomaly_code)->toBe(SessionAnomaly::NoScanIn->value);
});

it('marks no_scan_out for a lone scan-in, without self-pairing it against itself', function () {
    $f = AttendanceFixture::make();
    $session = pairedSession($f);
    $f->scanAt('07:28:00');

    (new PairingEngine)->pair($session, $f->rule);
    $session->refresh();

    expect($session->state)->toBe(SessionState::Unpaired);
    expect($session->anomaly_code)->toBe(SessionAnomaly::NoScanOut->value);
    expect($session->scan_in_event_id)->not->toBeNull();
    expect($session->scan_out_event_id)->toBeNull();
});

// Finding 1.4: a double-tap must never produce a suspiciously short
// PAIRED session that the rule engine would then silently mark ABSENT.
it('debounces a double-tap into one scan and reports no_scan_out, never a false pairing', function () {
    $f = AttendanceFixture::make();
    $session = pairedSession($f);
    $f->scanAt('07:28:00');
    $f->scanAt('07:28:05'); // 5s later: same physical tap, unsure the first registered

    (new PairingEngine)->pair($session, $f->rule);
    $session->refresh();

    expect($session->state)->toBe(SessionState::Unpaired);
    expect($session->anomaly_code)->toBe(SessionAnomaly::NoScanOut->value);
});

it('marks too_short for two genuinely distinct scans that are not a double-tap', function () {
    $f = AttendanceFixture::make(ruleOverrides: ['min_scan_gap_seconds' => 30, 'min_session_minutes' => 10]);
    $session = pairedSession($f);
    $f->scanAt('07:28:00');
    $f->scanAt('07:29:00'); // 60s apart: not debounced, but only 1 minute of coverage

    (new PairingEngine)->pair($session, $f->rule);
    $session->refresh();

    expect($session->state)->toBe(SessionState::Unpaired);
    expect($session->anomaly_code)->toBe(SessionAnomaly::TooShort->value);
});

// §11.1: a cover teacher's scan lands in a corridor they have no
// scheduled lesson in — this must surface as a distinct anomaly, not a
// plain "didn't scan".
it('marks location_mismatch when the only scan is at the wrong corridor', function () {
    $f = AttendanceFixture::make();
    $session = pairedSession($f);
    $f->scanAt('07:28:00', device: $f->wrongDevice);

    (new PairingEngine)->pair($session, $f->rule);
    $session->refresh();

    expect($session->state)->toBe(SessionState::Unpaired);
    expect($session->anomaly_code)->toBe(SessionAnomaly::LocationMismatch->value);
});

// Decision: one raw_event may serve as both the scan-out of session A
// and the scan-in of session B — no "consumption".
it('lets one boundary scan serve as scan-out of one session and scan-in of the next', function () {
    $f = AttendanceFixture::make([
        ['07:30:00', '08:25:00'],
        ['08:25:00', '09:20:00'],
    ]);
    $otherClass = ClassCode::factory()->create();
    $secondSlot = \App\Models\PeriodSlot::where('seq', 2)->first();
    TimetableEntry::where('slot_id', $secondSlot->id)->update(['class_code_id' => $otherClass->id]);

    $builder = new SessionBuilder;
    $sessions = $builder->persist($f->teacher, $f->date, $builder->group($f->teacher, $f->date))
        ->sortBy('first_slot_id')->values();
    $sessionA = $sessions[0]->fresh(['firstSlot', 'lastSlot']);
    $sessionB = $sessions[1]->fresh(['firstSlot', 'lastSlot']);

    $f->scanAt('07:28:00');
    $boundary = \App\Models\RawEvent::factory()->create([
        'device_id' => $f->device->id,
        'teacher_id' => $f->teacher->id,
        'event_time_server' => \Carbon\Carbon::parse($f->date->toDateString().' 08:25:00', config('attendance.timezone'))->utc(),
    ]);
    $f->scanAt('09:15:00');

    $engine = new PairingEngine;
    $engine->pair($sessionA, $f->rule);
    $engine->pair($sessionB, $f->rule);
    $sessionA->refresh();
    $sessionB->refresh();

    expect($sessionA->state)->toBe(SessionState::Paired);
    expect($sessionB->state)->toBe(SessionState::Paired);
    expect($sessionA->scan_out_event_id)->toBe($boundary->id);
    expect($sessionB->scan_in_event_id)->toBe($boundary->id);
});
