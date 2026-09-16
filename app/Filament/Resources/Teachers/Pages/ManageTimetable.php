<?php

namespace App\Filament\Resources\Teachers\Pages;

use App\Enums\TimetableState;
use App\Filament\Resources\Teachers\TeacherResource;
use App\Models\AuditLog;
use App\Models\ClassCode;
use App\Models\PeriodSlot;
use App\Models\Room;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * §10: "Build an entry screen shaped like the paper grid — day columns,
 * slot rows, class code in the cell. Not a generic form." This is that
 * screen: not a Filament Schema/Form, because a dynamic day x period
 * grid of paired selects doesn't map cleanly onto one.
 */
class ManageTimetable extends Page
{
    use InteractsWithRecord;

    protected static string $resource = TeacherResource::class;

    protected string $view = 'filament.resources.teachers.pages.manage-timetable';

    /** Monday .. Saturday. Carbon convention: 0 = Sunday .. 6 = Saturday. */
    public const DAYS = [1, 2, 3, 4, 5, 6];

    /**
     * How far back a timetable change recomputes already-stored
     * period_results. A version backdated to the start of term would
     * otherwise fire hundreds of attendance:compute runs inside one web
     * request; anything older than that is a deliberate command-line
     * job, and the notification says so.
     */
    public const RECOMPUTE_WINDOW_DAYS = 14;

    /** Exposed for the Blade view — `self::` doesn't resolve there. */
    public array $days = self::DAYS;

    public ?int $versionId = null;

    /** @var array<int, TimetableVersion> */
    public Collection $versions;

    /** [day_of_week][seq] => ['class_code_id' => ?int, 'room_id' => ?int] */
    public array $cells = [];

    /** [day_of_week][seq] => PeriodSlot|null, for rendering labels/breaks */
    public array $slotGrid = [];

    public int $maxSeq = 0;

    public string $newVersionValidFrom = '';

    public string $closeValidTo = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->versions = $this->loadVersions();
        $this->versionId = $this->versions->first()?->id;
        $this->newVersionValidFrom = now()->toDateString();
        $this->closeValidTo = now()->toDateString();

        $this->loadGrid();
    }

    public function getTitle(): string
    {
        return __('panel.resources.teachers.timetable_title', ['name' => $this->record->full_name]);
    }

    public function updatedVersionId(): void
    {
        $this->loadGrid();
    }

    /**
     * A new version starts as a copy of whichever version is currently
     * selected (if any) — matches how timetable changes actually happen
     * (a handful of cells change mid-year, not the whole grid).
     */
    public function createVersion(): void
    {
        $this->validate(['newVersionValidFrom' => ['required', 'date']]);

        $sourceVersionId = $this->versionId;

        $version = TimetableVersion::create([
            'teacher_id' => $this->record->id,
            'valid_from' => $this->newVersionValidFrom,
            'state' => TimetableState::Draft,
            'entered_by' => auth()->id(),
        ]);

        if ($sourceVersionId !== null) {
            $source = TimetableVersion::find($sourceVersionId);
            foreach ($source?->entries ?? [] as $entry) {
                TimetableEntry::create([
                    'version_id' => $version->id,
                    'day_of_week' => $entry->day_of_week,
                    'slot_id' => $entry->slot_id,
                    'class_code_id' => $entry->class_code_id,
                    'room_id' => $entry->room_id,
                ]);
            }
        }

        $this->versions = $this->loadVersions();
        $this->versionId = $version->id;
        $this->loadGrid();

        $this->notifyRecomputed(
            __('panel.resources.teachers.notification_version_created'),
            $this->recomputeFor($version),
        );
    }

    private function loadGrid(): void
    {
        $this->cells = [];
        $this->slotGrid = [];
        $this->maxSeq = 0;

        $referenceDate = $this->versionId
            ? TimetableVersion::find($this->versionId)?->valid_from ?? now()
            : now();

        // Not PeriodSlot::forDate() — that resolves one date's own
        // day-of-week; here every day of the week needs its own slots for
        // the same reference date, so the day is a loop variable instead.
        foreach (self::DAYS as $day) {
            $slots = PeriodSlot::query()
                ->where('day_of_week', $day)
                ->where('valid_from', '<=', $referenceDate->toDateString())
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $referenceDate->toDateString()))
                ->orderBy('seq')
                ->get();

            foreach ($slots as $slot) {
                $this->slotGrid[$day][$slot->seq] = $slot;
                $this->maxSeq = max($this->maxSeq, $slot->seq);
                $this->cells[$day][$slot->seq] = ['class_code_id' => null, 'room_id' => null];
            }
        }

        if ($this->versionId === null) {
            return;
        }

        $entries = TimetableEntry::query()->where('version_id', $this->versionId)->with('slot')->get();

        foreach ($entries as $entry) {
            $this->cells[$entry->day_of_week][$entry->slot->seq] = [
                'class_code_id' => $entry->class_code_id,
                'room_id' => $entry->room_id,
            ];
        }
    }

    public function save(): void
    {
        if ($this->versionId === null) {
            return;
        }

        DB::transaction(function () {
            foreach (self::DAYS as $day) {
                foreach ($this->slotGrid[$day] ?? [] as $seq => $slot) {
                    if ($slot->is_break) {
                        continue;
                    }

                    $cell = $this->cells[$day][$seq] ?? null;
                    $classCodeId = $cell['class_code_id'] ?? null;
                    $roomId = $cell['room_id'] ?? null;

                    $existing = TimetableEntry::query()
                        ->where('version_id', $this->versionId)
                        ->where('day_of_week', $day)
                        ->where('slot_id', $slot->id)
                        ->first();

                    if (blank($classCodeId) || blank($roomId)) {
                        $existing?->delete();

                        continue;
                    }

                    if ($existing) {
                        $existing->update(['class_code_id' => $classCodeId, 'room_id' => $roomId]);
                    } else {
                        TimetableEntry::create([
                            'version_id' => $this->versionId,
                            'day_of_week' => $day,
                            'slot_id' => $slot->id,
                            'class_code_id' => $classCodeId,
                            'room_id' => $roomId,
                        ]);
                    }
                }
            }
        });

        $this->notifyRecomputed(
            __('panel.resources.teachers.notification_timetable_saved'),
            $this->recomputeFor(TimetableVersion::find($this->versionId)),
        );
    }

    /**
     * Ends a timetable without deleting it: valid_to closes the version's
     * window, so timetableVersionFor() falls through to whatever version
     * covers the dates after it (§14 — a superseded grid is never
     * destroyed, only closed). Deliberately a separate button rather than
     * something createVersion() does silently, because closing a grid
     * changes which periods a teacher was expected to teach, and that is
     * a decision someone makes on purpose.
     */
    public function closeVersion(): void
    {
        $version = TimetableVersion::find($this->versionId);

        if ($version === null) {
            return;
        }

        $this->validate([
            'closeValidTo' => ['required', 'date', 'after_or_equal:'.$version->valid_from->toDateString()],
        ]);

        $before = ['valid_to' => $version->valid_to?->toDateString()];

        $version->valid_to = $this->closeValidTo;
        $version->save();

        AuditLog::record(
            'timetable_versions',
            $version->id,
            'closed',
            $before,
            ['valid_to' => $version->valid_to->toDateString()],
        );

        $this->versions = $this->loadVersions();
        $this->loadGrid();

        $this->notifyRecomputed(
            __('panel.resources.teachers.notification_version_closed'),
            $this->recomputeFor($version, throughToday: true),
        );
    }

    /** @return Collection<int, TimetableVersion> */
    private function loadVersions(): Collection
    {
        // Same ordering as Teacher::timetableVersionFor(), id included:
        // the version this screen shows first must be the one attendance
        // actually computes against, even when two share a valid_from.
        return $this->record->timetableVersions()
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Recomputes already-stored attendance over the dates a changed
     * version governs, so Teacher Attendance reflects the new grid
     * without anyone running the command by hand — the same
     * Artisan::call pattern the calendar-day pages use, scoped to this
     * teacher.
     *
     * Only rewrites results for slots the grid still contains: a slot
     * dropped from the timetable keeps its old row, because
     * SessionBuilder::persist() never deletes sessions and
     * PeriodResultWriter only supersedes what it rewrites.
     *
     * $throughToday widens the range past the version's own valid_to,
     * which is what closing a grid needs: the days after the closing
     * date are handed back to whichever version covers them, so they are
     * every bit as stale as the days inside the window.
     *
     * @return int number of dates recomputed
     */
    private function recomputeFor(?TimetableVersion $version, bool $throughToday = false): int
    {
        if ($version === null) {
            return 0;
        }

        $today = Carbon::today();
        $from = Carbon::parse($version->valid_from->toDateString())
            ->max($today->clone()->subDays(self::RECOMPUTE_WINDOW_DAYS));
        $to = ($version->valid_to !== null && ! $throughToday)
            ? Carbon::parse($version->valid_to->toDateString())->min($today)
            : $today->clone();

        $recomputed = 0;

        for ($date = $from->clone(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            Artisan::call('attendance:compute', [
                'date' => $date->toDateString(),
                '--teacher' => [$this->record->staff_no],
            ]);

            $recomputed++;
        }

        return $recomputed;
    }

    private function notifyRecomputed(string $title, int $recomputed): void
    {
        Notification::make()
            ->title($title)
            ->body(__('panel.resources.teachers.notification_recomputed', [
                'count' => $recomputed,
                'days' => self::RECOMPUTE_WINDOW_DAYS,
                'staff_no' => $this->record->staff_no,
            ]))
            ->success()
            ->send();
    }

    /** @return Collection<int, ClassCode> */
    public function getClassCodeOptionsProperty(): Collection
    {
        return ClassCode::query()->orderBy('code')->get();
    }

    /** @return Collection<int, Room> */
    public function getRoomOptionsProperty(): Collection
    {
        return Room::query()->with('corridor')->orderBy('code')->get();
    }

    public function dayLabel(int $day): string
    {
        return __('panel.days.'.$day);
    }
}
