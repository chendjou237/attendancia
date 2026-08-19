<?php

namespace App\Console\Commands;

use App\Enums\DayType;
use App\Models\CalendarDay;
use App\Services\Demo\AttendanceSimulator;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Seeds a full, coherent demo dataset for presentations when a real
 * device isn't available: reference data (corridors, rooms, teachers,
 * a real bell schedule, timetables) plus simulated attendance history
 * run through the actual ingestion pipeline, so the exception queue,
 * calendar behaviour, and reports all have real, engine-computed
 * content rather than fabricated numbers.
 */
class DemoSeed extends Command
{
    protected $signature = 'demo:seed
        {--fresh : wipe the database first}
        {--days=15 : how many recent weekdays to simulate}';

    protected $description = 'Seed a realistic demo dataset for presentations without a live device.';

    public function handle(AttendanceSimulator $simulator): int
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

        $dates = $this->recentWeekdays((int) $this->option('days'));
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
     * @return array<int, \Carbon\CarbonInterface>
     */
    private function recentWeekdays(int $count): array
    {
        $dates = [];
        $cursor = now()->subDay(); // start from yesterday — today may still be "in progress"

        while (count($dates) < $count) {
            if ($cursor->isWeekday()) {
                $dates[] = $cursor->clone();
            }

            $cursor->subDay();
        }

        return array_reverse($dates);
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
}
