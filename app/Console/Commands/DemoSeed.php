<?php

namespace App\Console\Commands;

use App\Enums\DayType;
use App\Enums\ReportState;
use App\Models\AuditLog;
use App\Models\CalendarDay;
use App\Models\MonthlyReport;
use App\Models\User;
use App\Services\Demo\AttendanceSimulator;
use App\Services\Reporting\MonthlyReportGenerator;
use Carbon\Carbon;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Seeds a full, coherent demo dataset for presentations when a real
 * device isn't available: reference data (corridors, rooms, teachers,
 * a real bell schedule, timetables) plus simulated attendance history
 * run through the actual ingestion pipeline, so the exception queue,
 * calendar behaviour, dashboard, and monthly reports all have real,
 * engine-computed content rather than fabricated numbers.
 */
class DemoSeed extends Command
{
    protected $signature = 'demo:seed
        {--fresh : wipe the database first}
        {--months=2 : how many calendar months to simulate, counting the current (partial, up to today) month}';

    protected $description = 'Seed a realistic demo dataset for presentations without a live device.';

    public function handle(AttendanceSimulator $simulator, MonthlyReportGenerator $reportGenerator): int
    {
        if ($this->option('fresh')) {
            if (! $this->confirm('This will WIPE the current database and reseed it. Continue?', true)) {
                return self::FAILURE;
            }

            $this->call('migrate:fresh');
        }

        $this->info('Seeding reference data...');
        $seeder = new DemoSeeder;
        $seeder->run();

        $dates = $this->calendarWeekdays((int) $this->option('months'));
        $featuredDates = $this->seedFeaturedCalendarDays($dates, $seeder->classCodes);

        $this->info('Simulating '.count($dates).' weekdays of attendance for '.count($seeder->teachers).' teachers...');
        $bar = $this->output->createProgressBar(count($dates) * count($seeder->teachers));
        $bar->start();

        foreach ($dates as $date) {
            if (in_array($date->toDateString(), $featuredDates, true)) {
                // A holiday/suspension day is demonstrated through
                // DayResolver alone — no scans needed, and generating
                // them would just be noise the engine ignores anyway.
                $bar->advance(count($seeder->teachers));

                continue;
            }

            foreach ($seeder->teachers as $i => $teacher) {
                $simulator->simulateDay($teacher, $seeder->mainDevice, $date, (string) (1000 + $i));
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();

        $this->info('Computing attendance for each date...');
        foreach ($dates as $date) {
            Artisan::call('attendance:compute', ['date' => $date->toDateString()]);
        }

        $this->newLine();
        $this->info('Generating monthly reports...');
        $this->generateMonthlyReports($dates, $reportGenerator);

        $this->newLine();
        $this->info('Demo ready. Sign in at /admin with:');
        $this->table(['Role', 'Email', 'Password'], [
            ['Admin', 'admin@attendancia.test', 'password'],
            ['Officer', 'officer@attendancia.test', 'password'],
            ['Principal', 'principal@attendancia.test', 'password'],
            ['HR', 'hr@attendancia.test', 'password'],
        ]);

        return self::SUCCESS;
    }

    /**
     * Every weekday from the start of the month $months-1 months ago
     * through today (inclusive) — anchored to real calendar months,
     * not a rolling weekday count, so "2 months" always means one
     * genuinely complete past month plus the current one in progress.
     * Including today (unlike a live device, which wouldn't have
     * today's later scans yet) is deliberate: this is demo data for a
     * presentation, and a presenter wants today's numbers on the
     * dashboard immediately after seeding, not a blank "today."
     *
     * @return array<int, \Carbon\CarbonInterface>
     */
    private function calendarWeekdays(int $months): array
    {
        $end = now()->startOfDay();
        $cursor = $end->clone()->subMonthsNoOverflow(max(1, $months) - 1)->startOfMonth();

        $dates = [];
        while ($cursor->lte($end)) {
            if ($cursor->isWeekday()) {
                $dates[] = $cursor->clone();
            }

            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * Two deliberately "interesting" calendar days near the recent end
     * of the range, so the demo can show DayResolver's holiday and
     * scoped-suspension behaviour, not just ordinary teaching days.
     *
     * @return array<int, string> the date strings marked, so the caller
     *                            can skip simulating scans for them
     */
    private function seedFeaturedCalendarDays(array $dates, array $classCodes): array
    {
        $marked = [];
        $count = count($dates);

        if ($count >= 5) {
            $holiday = $dates[$count - 5];
            CalendarDay::create([
                'date' => $holiday->toDateString(),
                'day_type' => DayType::PublicHoliday,
                'note' => 'Demo: national holiday',
            ]);
            $marked[] = $holiday->toDateString();
        }

        if ($count >= 3) {
            $suspended = $dates[$count - 3];
            $day = CalendarDay::create([
                'date' => $suspended->toDateString(),
                'day_type' => DayType::ClassesSuspended,
                'note' => 'Demo: sequence exam (scoped to two classes)',
            ]);
            $day->suspendedClassCodes()->attach(collect($classCodes)->take(2)->pluck('id'));
            $marked[] = $suspended->toDateString();
        }

        return $marked;
    }

    /**
     * A report per calendar month touched by the simulated range.
     * Every month strictly before the current one is walked all the
     * way to SentToHr — a finished example to look at — since its
     * data is complete and won't change. The current, still-in-
     * progress month is left as a fresh Draft so a live demo can walk
     * the approval workflow itself rather than finding it already done.
     */
    private function generateMonthlyReports(array $dates, MonthlyReportGenerator $reportGenerator): void
    {
        $currentMonthStart = now()->startOfMonth();
        $officer = User::where('email', 'officer@attendancia.test')->first();
        $principal = User::where('email', 'principal@attendancia.test')->first();

        $months = collect($dates)
            ->map(fn (Carbon $d) => $d->copy()->startOfMonth()->toDateString())
            ->unique()
            ->sort();

        foreach ($months as $monthString) {
            $month = Carbon::parse($monthString);
            $report = $reportGenerator->generate($month);

            if ($month->lt($currentMonthStart)) {
                $this->driveToSentToHr($report, $officer, $principal);
            }
        }
    }

    private function driveToSentToHr(MonthlyReport $report, ?User $officer, ?User $principal): void
    {
        $this->transition($report, ReportState::OfficerReviewed, 'officer_reviewed', $officer);

        $report->update(['approved_by' => $principal?->id, 'approved_at' => now()->subDays(2)]);
        $this->transition($report, ReportState::PrincipalApproved, 'principal_approved', $principal);

        $report->update(['sent_to_hr_at' => now()->subDay()]);
        $this->transition($report, ReportState::SentToHr, 'sent_to_hr', $officer);
    }

    private function transition(MonthlyReport $report, ReportState $to, string $action, ?User $actor): void
    {
        $before = $report->only(['state']);
        $report->update(['state' => $to]);

        AuditLog::create([
            'entity' => 'monthly_reports',
            'entity_id' => $report->id,
            'action' => $action,
            'actor_id' => $actor?->id,
            'before_json' => $before,
            'after_json' => $report->only(['state']),
            'at' => now(),
        ]);
    }
}
