<?php

use App\Enums\PeriodStatus;
use App\Enums\SessionAnomaly;
use App\Enums\SessionState;
use App\Models\ClassCode;
use App\Models\PeriodResult;
use App\Models\PeriodSlot;
use App\Models\RawEvent;
use App\Models\RuleVersion;
use App\Models\TimetableEntry;
use App\Services\Attendance\PairingEngine;
use App\Services\Attendance\PeriodResultWriter;
use App\Services\Attendance\RuleEngine;
use App\Services\Attendance\SessionBuilder;
use Tests\Support\AttendanceFixture;

/**
 * Answers to the six real-world "what actually happens" QA questions
 * asked about teacher scan behaviour. Two of the six already had exact
 * coverage before this file existed — noted below rather than
 * duplicated:
 *
 *   - "scans twice, thinking the first didn't work" (double-tap):
 *     tests/Feature/Attendance/PairingEngineTest.php
 *     "debounces a double-tap into one scan and reports no_scan_out,
 *     never a false pairing"
 *   - "doesn't scan out of lesson 1, scans in for lesson 2" (transition
 *     scan): PairingEngineTest.php "lets one boundary scan serve as
 *     scan-out of one session and scan-in of the next"
 *
 * This file covers the other four, plus the risk case buried inside
 * the sixth question.
 */
function runEngine(AttendanceFixture $f, ?RuleVersion $rule = null): \Illuminate\Support\Collection
{
    $rule ??= $f->rule;
    $builder = new SessionBuilder;
    $session = $builder->persist($f->teacher, $f->date, $builder->group($f->teacher, $f->date))->first()
        ->fresh(['firstSlot', 'lastSlot', 'teacher', 'classCode']);

    (new PairingEngine)->pair($session, $rule);
    $session->refresh()->load('scanInEvent', 'scanOutEvent', 'firstSlot', 'lastSlot', 'teacher', 'classCode');

    return (new RuleEngine($builder, new PeriodResultWriter))->computeForSession($session, $rule);
}

// Q3: scans in more than 30 minutes late.
it('marks the period absent for a scan-in 30+ minutes late, even though the session itself pairs fine', function () {
    $f = AttendanceFixture::make([['07:30:00', '08:25:00']]); // grace_late_minutes: 10 (default)
    $f->scanAt('08:05:00'); // 35 minutes late
    $f->scanAt('08:25:00'); // scans out normally

    $results = runEngine($f);

    // Pairing succeeded — this is a confident ABSENT, not a pending
    // exception. 20 minutes of scan-in-to-scan-out coverage clears
    // min_session_minutes, so nothing about this looks like a
    // double-tap or a bogus interval; it's a real, if late, lesson.
    expect($results->first()->status)->toBe(PeriodStatus::Absent);
});

it('is still present if late but inside the grace window', function () {
    $f = AttendanceFixture::make([['07:30:00', '08:25:00']]);
    $f->scanAt('07:38:00'); // 8 min late — inside the 10 min default grace
    $f->scanAt('08:25:00');

    $results = runEngine($f);

    expect($results->first()->status)->toBe(PeriodStatus::Present);
});

// Q4: scans out more than 30 minutes early.
it('marks the period absent for a scan-out 30+ minutes early', function () {
    $f = AttendanceFixture::make([['07:30:00', '08:25:00']]); // grace_early_minutes: 15 (default)
    $f->scanAt('07:28:00'); // on time
    $f->scanAt('07:55:00'); // leaves 30 minutes before the period ends

    $results = runEngine($f);

    expect($results->first()->status)->toBe(PeriodStatus::Absent);
});

it('is still present if early but inside the grace window', function () {
    $f = AttendanceFixture::make([['07:30:00', '08:25:00']]);
    $f->scanAt('07:28:00');
    $f->scanAt('08:12:00'); // 13 min early — inside the 15 min default grace

    $results = runEngine($f);

    expect($results->first()->status)->toBe(PeriodStatus::Present);
});

// Q5: scans with no class actually scheduled (a random/idle tap).
it('records a raw event for a scan with no scheduled session nearby, but it produces no period_result at all', function () {
    $f = AttendanceFixture::make([['07:30:00', '08:25:00']]);
    $f->scanAt('07:28:00');
    $f->scanAt('08:20:00');

    // Nowhere near the one scheduled period's pairing window
    // (07:15-08:40 by default) — an afternoon tap with nothing
    // scheduled at all.
    $f->scanAt('14:00:00');

    $results = runEngine($f);

    // The real lesson is unaffected...
    expect($results)->toHaveCount(1);
    expect($results->first()->status)->toBe(PeriodStatus::Present);
    // ...and the stray tap exists (it's not lost — visible in
    // raw_events / Device Monitor's live feed) but never enters a
    // session's query window, so it produces no period_result,
    // doesn't appear in the Exception Queue, and isn't on any
    // teacher's report. This is a known visibility gap, not a
    // correctness bug — see ExceptionQueue.php's bottom comment.
    expect(RawEvent::count())->toBe(3);
    expect(PeriodResult::count())->toBe(1);
});

// Q6: forgets to scan out, comes back later.
it('still counts as present if the late scan-out lands inside the pairing window', function () {
    $f = AttendanceFixture::make([['07:30:00', '08:25:00']]); // pair_window_after_minutes: 15 (default)
    $f->scanAt('07:28:00');
    $f->scanAt('08:35:00'); // 10 minutes after the period ended, "forgot, came back"

    $results = runEngine($f);

    // Lateness on the scan-OUT side only has to clear the pairing
    // window to be picked up at all — once picked up, scanning any
    // time at or after the period's real end always satisfies
    // grace_early (scanOut >= periodEnd - grace_early is true for any
    // scanOut >= periodEnd).
    expect($results->first()->status)->toBe(PeriodStatus::Present);
});

it('leaves the session pending (not a false absent) if the comeback misses the pairing window entirely', function () {
    $f = AttendanceFixture::make([['07:30:00', '08:25:00']]); // window closes at 08:40
    $session = (function () use ($f) {
        $builder = new SessionBuilder;

        return $builder->persist($f->teacher, $f->date, $builder->group($f->teacher, $f->date))->first()
            ->fresh(['firstSlot', 'lastSlot']);
    })();
    $f->scanAt('07:28:00');
    $f->scanAt('10:00:00'); // over an hour later, well past the window and no other session nearby

    (new PairingEngine)->pair($session, $f->rule);
    $session->refresh();

    // Not ABSENT — the system never guesses. This lands in the
    // Exception Queue for a human to resolve with the real story
    // ("taught the lesson, terminal was down until break").
    expect($session->state)->toBe(SessionState::Unpaired);
    expect($session->anomaly_code)->toBe(SessionAnomaly::NoScanOut->value);
});

// Q6, the sharp edge: a very late "forgot, came back" scan can miss
// its own session's window but land inside the *next* different
// lesson's window instead, since nothing marks an event "reserved"
// for one session over another — same mechanism that makes the
// transition-scan case work, but here it's a coincidence, not a
// planned handoff.
it('a too-late comeback scan can be mistakenly claimed as the next (different) lesson\'s scan-in', function () {
    $f = AttendanceFixture::make([
        ['07:30:00', '08:25:00'], // lesson A: pair window closes 08:40
        ['08:25:00', '09:20:00'], // lesson B: pair window opens 08:10 (different class -> its own session)
    ]);
    $otherClass = ClassCode::factory()->create();
    $secondSlot = PeriodSlot::where('seq', 2)->first();
    TimetableEntry::where('slot_id', $secondSlot->id)->update(['class_code_id' => $otherClass->id]);

    $builder = new SessionBuilder;
    $sessions = $builder->persist($f->teacher, $f->date, $builder->group($f->teacher, $f->date))
        ->sortBy('first_slot_id')->values();
    $sessionA = $sessions[0]->fresh(['firstSlot', 'lastSlot']);
    $sessionB = $sessions[1]->fresh(['firstSlot', 'lastSlot']);

    $f->scanAt('07:28:00'); // scans in for lesson A...
    // ...forgets to scan out, and taught lesson B for real too but
    // never scanned for it either. Remembers at 09:00 and taps once.
    $f->scanAt('09:00:00');

    $engine = new PairingEngine;
    $engine->pair($sessionA, $f->rule);
    $engine->pair($sessionB, $f->rule);
    $sessionA->refresh();
    $sessionB->refresh();

    // A: nothing fell inside its own window (07:15-08:40) after the
    // scan-in, so it correctly goes pending rather than guessing.
    expect($sessionA->state)->toBe(SessionState::Unpaired);
    expect($sessionA->anomaly_code)->toBe(SessionAnomaly::NoScanOut->value);

    // B: the 09:00 tap is the *first* event in B's own window
    // (08:10-09:20), so PairingEngine reads it as B's scan-in — a
    // real but coincidental side effect, not a bug in the pairing
    // logic itself.
    expect($sessionB->scan_in_event_id)->not->toBeNull();
});
