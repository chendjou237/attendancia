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

// The behaviour production actually gets: teachers check in and out on
// whichever terminal is nearest, so a scan from another corridor pairs
// exactly like one from the session's own corridor. No config() call
// here on purpose — this asserts the shipped default, which is now a
// hard false in config/attendance.php rather than an env lookup.
it('pairs a scan taken on the nearest device, in another corridor, as a normal attendance', function () {
    $f = AttendanceFixture::make();
    $session = pairedSession($f);
    $f->scanAt('07:28:00', device: $f->wrongDevice);
    $f->scanAt('08:20:00', device: $f->wrongDevice);

    (new PairingEngine)->pair($session, $f->rule);
    $session->refresh();

    expect($session->state)->toBe(SessionState::Paired);
    expect($session->anomaly_code)->toBeNull();
});

// §11.1: a cover teacher's scan lands in a corridor they have no
// scheduled lesson in — once a distinct anomaly, now unreachable in
// production: enforce_location is a hard false, so only a runtime
// config() override like this one still exercises the branch. Kept
// because period_results written under the old rule still carry
// location_mismatch and must keep reading back.
it('marks location_mismatch when the only scan is at the wrong corridor and enforce_location is on', function () {
    config(['attendance.enforce_location' => true]);
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
    $boundaryAt = \Carbon\Carbon::parse($f->date->toDateString().' 08:25:00', config('attendance.timezone'))->utc();
    $boundary = \App\Models\RawEvent::factory()->create([
        'device_id' => $f->device->id,
        'teacher_id' => $f->teacher->id,
        'event_time_device' => $boundaryAt,
        'event_time_server' => $boundaryAt,
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

// §7.4: off by default for the single-device pilot.
it('pairs successfully across corridors when enforce_location is off (the default)', function () {
    config(['attendance.enforce_location' => false]);
    $f = AttendanceFixture::make();
    $session = pairedSession($f);
    $f->scanAt('07:28:00', device: $f->wrongDevice);
    $f->scanAt('08:20:00', device: $f->wrongDevice);

    (new PairingEngine)->pair($session, $f->rule);
    $session->refresh();

    expect($session->state)->toBe(SessionState::Paired);
    expect($session->anomaly_code)->toBeNull();
});

// Regression: PairingEngine originally queried event_time_server (when
// the event was ingested), not event_time_device (when the scan
// actually happened). The two are seconds apart for a live event, but
// a backfilled event's event_time_server is whenever the backfill
// command happened to run — for real downtime recovery (§7.2's primary
// path, not a fallback) that can be hours or days after the scan.
// Caught via the demo data generator simulating historical dates,
// where the gap is large enough to be unmissable.
it('pairs using event_time_device, not event_time_server, so backfilled events with a stale ingestion time still pair', function () {
    $f = AttendanceFixture::make();
    $session = pairedSession($f);

    $scanInAt = \Carbon\Carbon::parse($f->date->toDateString().' 07:28:00', config('attendance.timezone'))->utc();
    $scanOutAt = \Carbon\Carbon::parse($f->date->toDateString().' 08:20:00', config('attendance.timezone'))->utc();

    // event_time_server left at "now" — days after the simulated scan,
    // exactly like a real backfill run.
    \App\Models\RawEvent::factory()->create([
        'device_id' => $f->device->id, 'teacher_id' => $f->teacher->id,
        'event_time_device' => $scanInAt, 'event_time_server' => now(),
    ]);
    \App\Models\RawEvent::factory()->create([
        'device_id' => $f->device->id, 'teacher_id' => $f->teacher->id,
        'event_time_device' => $scanOutAt, 'event_time_server' => now(),
    ]);

    (new PairingEngine)->pair($session, $f->rule);
    $session->refresh();

    expect($session->state)->toBe(SessionState::Paired);
});
