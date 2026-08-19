<?php

use App\Enums\PeriodSource;
use App\Enums\PeriodStatus;
use App\Enums\SessionAnomaly;
use App\Enums\SessionState;
use App\Filament\Pages\ExceptionQueue;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\ClassCode;
use App\Models\PeriodResult;
use App\Models\PeriodSlot;
use App\Models\RuleVersion;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);
});

function unpairedPeriodResult(): PeriodResult
{
    $slot = PeriodSlot::factory()->create();
    $session = AttendanceSession::factory()->create(['state' => SessionState::Unpaired, 'anomaly_code' => SessionAnomaly::NoScanIn->value]);

    return PeriodResult::factory()->create([
        'teacher_id' => $session->teacher_id,
        'slot_id' => $slot->id,
        'class_code_id' => $session->class_code_id,
        'session_id' => $session->id,
        'status' => PeriodStatus::Unpaired,
        'source' => PeriodSource::Scan,
        'is_current' => true,
    ]);
}

it('lists current pending period results (unpaired and location_mismatch)', function () {
    $unpaired = unpairedPeriodResult();

    $mismatchSlot = PeriodSlot::factory()->create();
    $mismatchSession = AttendanceSession::factory()->create(['state' => SessionState::Unpaired, 'anomaly_code' => SessionAnomaly::LocationMismatch->value]);
    $mismatch = PeriodResult::factory()->create([
        'teacher_id' => $mismatchSession->teacher_id,
        'slot_id' => $mismatchSlot->id,
        'class_code_id' => $mismatchSession->class_code_id,
        'session_id' => $mismatchSession->id,
        'status' => PeriodStatus::LocationMismatch,
        'is_current' => true,
    ]);

    $present = PeriodResult::factory()->create(['status' => PeriodStatus::Present, 'is_current' => true]);

    Livewire::test(ExceptionQueue::class)
        ->assertCanSeeTableRecords([$unpaired, $mismatch])
        ->assertCanNotSeeTableRecords([$present]);
});

it('excludes a period result that already has an override', function () {
    $resolved = unpairedPeriodResult();
    $resolved->update(['override_status' => PeriodStatus::Present, 'override_reason' => 'Confirmed by CCTV.']);

    $stillPending = unpairedPeriodResult();

    Livewire::test(ExceptionQueue::class)
        ->assertCanSeeTableRecords([$stillPending])
        ->assertCanNotSeeTableRecords([$resolved]);
});

it('excludes a period result that is no longer current', function () {
    $stale = unpairedPeriodResult();
    $stale->update(['is_current' => false]);

    Livewire::test(ExceptionQueue::class)
        ->assertCanNotSeeTableRecords([$stale]);
});

it('records an override with a mandatory reason and writes an audit log entry', function () {
    $record = unpairedPeriodResult();
    $officer = auth()->user();

    Livewire::test(ExceptionQueue::class)
        ->callAction(
            \Filament\Actions\Testing\TestAction::make('override')->table($record),
            data: [
                'override_status' => PeriodStatus::Present->value,
                'override_reason' => 'Teacher confirmed present; device fault that period.',
            ],
        );

    $record->refresh();
    expect($record->override_status)->toBe(PeriodStatus::Present);
    expect($record->override_reason)->toBe('Teacher confirmed present; device fault that period.');
    expect($record->override_by)->toBe($officer->id);
    expect($record->override_at)->not->toBeNull();
    expect($record->effectiveStatus())->toBe(PeriodStatus::Present);

    $audit = AuditLog::where('entity', 'period_results')->where('entity_id', $record->id)->first();
    expect($audit)->not->toBeNull();
    expect($audit->action)->toBe('override');
    expect($audit->actor_id)->toBe($officer->id);
    expect($audit->after_json['override_status'])->toBe('present');
});

it('a resolved override no longer appears in the queue', function () {
    $record = unpairedPeriodResult();

    $component = Livewire::test(ExceptionQueue::class)
        ->assertCanSeeTableRecords([$record])
        ->callAction(
            \Filament\Actions\Testing\TestAction::make('override')->table($record),
            data: ['override_status' => PeriodStatus::AbsentJustified->value, 'override_reason' => 'Sick leave, notice on file.'],
        );

    $component->assertCanNotSeeTableRecords([$record->fresh()]);
});
