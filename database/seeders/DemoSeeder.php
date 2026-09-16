<?php

namespace Database\Seeders;

use App\Enums\EmploymentType;
use App\Enums\TimetableState;
use App\Models\ClassCode;
use App\Models\Corridor;
use App\Models\Device;
use App\Models\PeriodSlot;
use App\Models\Room;
use App\Models\RuleVersion;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Services\Attendance\TeacherBiometricIdAssigner;
use Illuminate\Database\Seeder;

/**
 * Reference data for a presentation demo — a school shape that's
 * internally coherent (real bell schedule, real timetables) so the
 * simulated attendance history that gets layered on top by
 * demo:seed produces believable results through the actual engine,
 * not fabricated numbers.
 */
class DemoSeeder extends Seeder
{
    /** @var array<int, Teacher> */
    public array $teachers = [];

    public array $classCodes = [];

    public array $rooms = [];

    public Device $mainDevice;

    public RuleVersion $ruleVersion;

    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->seedDemoUsers();

        $corridorA = Corridor::create(['code' => 'COR-A', 'name' => 'A Block']);
        $corridorB = Corridor::create(['code' => 'COR-B', 'name' => 'B Block']);

        $roomsA = collect(['A101', 'A102', 'A103', 'A104'])
            ->map(fn ($code) => Room::create(['corridor_id' => $corridorA->id, 'code' => $code, 'name' => "Room {$code}"]));
        $roomsB = collect(['B101', 'B102', 'B103'])
            ->map(fn ($code) => Room::create(['corridor_id' => $corridorB->id, 'code' => $code, 'name' => "Room {$code}"]));
        $this->rooms = $roomsA->concat($roomsB)->all();

        // Beta: one physical device. A second corridor exists in the demo
        // data so multi-corridor timetables can be shown even though only
        // one terminal is actually deployed — a scan from either corridor
        // now pairs the same way (corridor enforcement is retired, see
        // config/attendance.php).
        $this->mainDevice = Device::create([
            'corridor_id' => $corridorA->id,
            'serial' => 'DS-K1T804-DEMO01',
            'ip' => '192.168.1.64',
            'is_active' => true,
            'firmware' => 'V2.3.5 (demo)',
        ]);

        $this->classCodes = collect([
            ['code' => 'F1A', 'name' => 'Form 1 A'],
            ['code' => 'F2A', 'name' => 'Form 2 A'],
            ['code' => 'F3A', 'name' => 'Form 3 A'],
            ['code' => 'F4A', 'name' => 'Form 4 A'],
            ['code' => 'F4B', 'name' => 'Form 4 B'],
            ['code' => 'F5A', 'name' => 'Form 5 A'],
            ['code' => 'LSA', 'name' => 'Lower Sixth Arts'],
            ['code' => 'LSS', 'name' => 'Lower Sixth Science'],
            ['code' => 'USA', 'name' => 'Upper Sixth Arts'],
            ['code' => 'USS', 'name' => 'Upper Sixth Science'],
        ])->map(fn ($c) => ClassCode::create($c))->all();

        $this->seedPeriodSlots();

        $this->ruleVersion = RuleVersion::create([
            'grace_late_minutes' => 10,
            'grace_early_minutes' => 15,
            'pair_window_before_minutes' => 15,
            'pair_window_after_minutes' => 15,
            'min_scan_gap_seconds' => 30,
            'min_session_minutes' => 10,
            'hours_per_period' => 1.00,
            'valid_from' => now()->subMonths(6)->toDateString(),
            'note' => 'Demo rule set — §5 default values.',
        ]);

        $this->seedTeachers();
    }

    private function seedDemoUsers(): void
    {
        $roles = [
            'admin@attendancia.test' => ['name' => 'Admin Demo', 'role' => 'admin'],
            'officer@attendancia.test' => ['name' => 'Officer Demo', 'role' => 'officer'],
            'principal@attendancia.test' => ['name' => 'Principal Demo', 'role' => 'principal'],
            'hr@attendancia.test' => ['name' => 'HR Demo', 'role' => 'hr'],
        ];

        foreach ($roles as $email => $info) {
            $user = User::firstOrCreate(['email' => $email], ['name' => $info['name'], 'password' => bcrypt('password')]);
            $user->syncRoles([$info['role']]);
        }
    }

    /**
     * A plausible anglophone-Cameroonian secondary school bell
     * schedule, Monday–Friday: 8 teaching periods with a mid-morning
     * break and a lunch break. Not derived from a real school's grid —
     * §3's own numbers didn't reconcile (13 slots at ~55 min don't fit
     * 07:30–18:20), so this is a coherent stand-in for demo purposes,
     * not a confirmed schedule.
     */
    private function seedPeriodSlots(): void
    {
        $validFrom = now()->subMonths(6)->toDateString();

        $template = [
            [1, '07:30:00', '08:25:00', false],
            [2, '08:25:00', '09:20:00', false],
            [3, '09:20:00', '10:15:00', false],
            [4, '10:15:00', '10:30:00', true], // morning break
            [5, '10:30:00', '11:25:00', false],
            [6, '11:25:00', '12:20:00', false],
            [7, '12:20:00', '13:15:00', false],
            [8, '13:15:00', '13:45:00', true], // lunch
            [9, '13:45:00', '14:40:00', false],
            [10, '14:40:00', '15:35:00', false],
        ];

        foreach ([1, 2, 3, 4, 5] as $day) { // Monday..Friday
            foreach ($template as [$seq, $start, $end, $isBreak]) {
                PeriodSlot::create([
                    'day_of_week' => $day,
                    'seq' => $seq,
                    'start_time' => $start,
                    'end_time' => $end,
                    'is_break' => $isBreak,
                    'valid_from' => $validFrom,
                ]);
            }
        }
    }

    private function seedTeachers(): void
    {
        $names = [
            ['T-0001', 'Ngwa Fon Peter', EmploymentType::Hourly],
            ['T-0002', 'Achu Rebecca Manka', EmploymentType::Hourly],
            ['T-0003', 'Fonkeng Divine Ateh', EmploymentType::Salaried],
            ['T-0004', 'Tabe Comfort Ngum', EmploymentType::Hourly],
            ['T-0005', 'Ekema Samuel Njie', EmploymentType::Hourly],
            ['T-0006', 'Bih Grace Fru', EmploymentType::Salaried],
            ['T-0007', 'Nkeng Patrick Ade', EmploymentType::Hourly],
            ['T-0008', 'Mbah Justine Awa', EmploymentType::Hourly],
        ];

        $assigner = new TeacherBiometricIdAssigner;
        $nonBreakSlots = PeriodSlot::where('is_break', false)->get()->groupBy('day_of_week');
        $rooms = collect($this->rooms);

        foreach ($names as $i => [$staffNo, $fullName, $employmentType]) {
            $teacher = Teacher::create([
                'staff_no' => $staffNo,
                'full_name' => $fullName,
                'employment_type' => $employmentType,
                'active_from' => now()->subYear()->toDateString(),
            ]);

            $assigner->assign($teacher, (string) (1000 + $i), now()->subMonths(6));

            $version = TimetableVersion::create([
                'teacher_id' => $teacher->id,
                'valid_from' => now()->subMonths(6)->toDateString(),
                'state' => TimetableState::Approved,
            ]);

            $this->assignTimetable($version, $nonBreakSlots, $rooms, $i);

            $this->teachers[] = $teacher;
        }
    }

    /**
     * Gives each teacher a partial week: a double period, a
     * break-separated same-class run, and a couple of single periods
     * across different classes/rooms — enough variety that the session
     * builder actually exercises its splitting rules during the demo,
     * not just one session per day.
     */
    private function assignTimetable(TimetableVersion $version, $nonBreakSlots, $rooms, int $teacherIndex): void
    {
        $classCodes = collect($this->classCodes);
        $room = $rooms[$teacherIndex % $rooms->count()];

        foreach ([1, 2, 3, 4, 5] as $day) {
            $slots = $nonBreakSlots->get($day, collect())->sortBy('seq')->values();

            if ($slots->count() < 4) {
                continue;
            }

            // A double period on the first two slots of the day.
            $classA = $classCodes[$teacherIndex % $classCodes->count()];
            foreach ([0, 1] as $idx) {
                TimetableEntry::create([
                    'version_id' => $version->id,
                    'day_of_week' => $day,
                    'slot_id' => $slots[$idx]->id,
                    'class_code_id' => $classA->id,
                    'room_id' => $room->id,
                ]);
            }

            // A single period, different class, later in the day.
            if ($slots->count() > 5) {
                $classB = $classCodes[($teacherIndex + 1) % $classCodes->count()];
                TimetableEntry::create([
                    'version_id' => $version->id,
                    'day_of_week' => $day,
                    'slot_id' => $slots[5]->id,
                    'class_code_id' => $classB->id,
                    'room_id' => $room->id,
                ]);
            }
        }
    }
}
