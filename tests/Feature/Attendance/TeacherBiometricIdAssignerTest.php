<?php

use App\Models\Teacher;
use App\Models\TeacherBiometricId;
use App\Services\Attendance\TeacherBiometricIdAssigner;
use Carbon\Carbon;

it('assigns a biometric id to a teacher', function () {
    $teacher = Teacher::factory()->create();

    $mapping = (new TeacherBiometricIdAssigner)->assign($teacher, '123456', Carbon::parse('2026-01-01'));

    expect($mapping->teacher_id)->toBe($teacher->id);
    expect($mapping->biometric_id)->toBe('123456');
    expect($mapping->valid_to)->toBeNull();
});

it('resolves who held a biometric id at a given historical date', function () {
    $teacher = Teacher::factory()->create();
    TeacherBiometricId::factory()->for($teacher)->create([
        'biometric_id' => '999',
        'valid_from' => '2026-01-01',
        'valid_to' => null,
    ]);

    $resolved = TeacherBiometricId::resolve('999', Carbon::parse('2026-06-01'));

    expect($resolved?->id)->toBe($teacher->id);
});

// Finding 1.8: re-enrolment after a worn fingerprint must not orphan
// the first teacher's past raw_events by silently letting two teachers
// share one biometric_id.
it('closes out the previous holder when a biometric id is re-assigned to someone else', function () {
    $oldHolder = Teacher::factory()->create();
    $newHolder = Teacher::factory()->create();
    $assigner = new TeacherBiometricIdAssigner;

    $assigner->assign($oldHolder, '555', Carbon::parse('2026-01-01'));
    $assigner->assign($newHolder, '555', Carbon::parse('2026-06-01'));

    // Historical scans still resolve to whoever held the id at the time.
    expect(TeacherBiometricId::resolve('555', Carbon::parse('2026-03-01'))?->id)->toBe($oldHolder->id);
    expect(TeacherBiometricId::resolve('555', Carbon::parse('2026-07-01'))?->id)->toBe($newHolder->id);

    // Never two teachers active on the same id at once.
    expect(TeacherBiometricId::where('biometric_id', '555')->whereNull('valid_to')->count())->toBe(1);
});

it('re-assigning the same teacher to the same id is a no-op, not a duplicate row', function () {
    $teacher = Teacher::factory()->create();
    $assigner = new TeacherBiometricIdAssigner;

    $first = $assigner->assign($teacher, '777', Carbon::parse('2026-01-01'));
    $second = $assigner->assign($teacher, '777', Carbon::parse('2026-01-01'));

    expect($second->id)->toBe($first->id);
    expect(TeacherBiometricId::count())->toBe(1);
});
