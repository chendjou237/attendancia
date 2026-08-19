<?php

namespace App\Filament\Resources\Teachers\Pages;

use App\Enums\TimetableState;
use App\Filament\Resources\Teachers\TeacherResource;
use App\Models\ClassCode;
use App\Models\PeriodSlot;
use App\Models\Room;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
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

    private const DAY_NAMES = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

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

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->versions = $this->record->timetableVersions()->orderByDesc('valid_from')->get();
        $this->versionId = $this->versions->first()?->id;
        $this->newVersionValidFrom = now()->toDateString();

        $this->loadGrid();
    }

    public function getTitle(): string
    {
        return "Timetable — {$this->record->full_name}";
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

        $this->versions = $this->record->timetableVersions()->orderByDesc('valid_from')->get();
        $this->versionId = $version->id;
        $this->loadGrid();

        Notification::make()->title('New timetable version created')->success()->send();
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
        // whereDate(), not a plain <=/>= comparison — see
        // Teacher::timetableVersionFor() for why the latter would
        // silently exclude a slot whose valid_from is the reference day.
        foreach (self::DAYS as $day) {
            $slots = PeriodSlot::query()
                ->where('day_of_week', $day)
                ->whereDate('valid_from', '<=', $referenceDate->toDateString())
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $referenceDate->toDateString()))
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

        Notification::make()->title('Timetable saved')->success()->send();
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
        return self::DAY_NAMES[$day];
    }
}
