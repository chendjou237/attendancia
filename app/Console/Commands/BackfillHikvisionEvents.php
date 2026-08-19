<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\Hikvision\AcsEventFetchException;
use App\Services\Hikvision\AcsEventFetcher;
use App\Services\Hikvision\DeviceCredentials;
use App\Services\Hikvision\EventNormalizer;
use App\Services\Hikvision\EventProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * §7.2: the recovery path. Given the device has backup power and the
 * server doesn't (see the plan's power-model note), this is not a
 * secondary safety net — it is the primary way the system recovers
 * from the outage that actually happens here: mains fails, the server
 * dies, the device keeps recording, mains returns, the server boots
 * and this runs before the live stream resumes.
 *
 * Feeds every record through the same EventProcessor as the live
 * stream (§7.2: "do not fork the filtering logic between the two
 * paths"); the unique (device_serial, device_event_serial) index makes
 * re-running over an already-ingested window a no-op.
 */
class BackfillHikvisionEvents extends Command
{
    protected $signature = 'hikvision:backfill {device : devices.serial} {--hours= : defaults to config(attendance.backfill_hours)}';

    protected $description = 'Replay historical AcsEvent records through the event pipeline to fill gaps left by downtime.';

    private const RESULTS_PER_PAGE = 30;

    // Safety net against a misunderstood pagination contract looping
    // forever — see AcsEventFetcher's response shape note.
    private const MAX_PAGES = 2000;

    public function handle(AcsEventFetcher $fetcher, EventNormalizer $normalizer, EventProcessor $processor): int
    {
        $device = Device::where('serial', $this->argument('device'))->first();

        if ($device === null) {
            $this->error("No device with serial [{$this->argument('device')}] found in the devices table.");

            return self::FAILURE;
        }

        ['user' => $user, 'pass' => $pass] = DeviceCredentials::for($device);

        if (blank($user) || blank($pass)) {
            $this->error('No ISAPI credentials configured for this device (see config/attendance.php and .env).');

            return self::FAILURE;
        }

        $hours = (int) ($this->option('hours') ?: config('attendance.backfill_hours'));
        $endTime = now();
        $startTime = $endTime->clone()->subHours($hours);

        $this->info("Backfilling {$device->serial}: {$startTime->toIso8601String()} .. {$endTime->toIso8601String()}");

        $searchId = (string) Str::uuid();
        $position = 0;
        $stored = 0;
        $page = 0;

        do {
            $page++;

            if ($page > self::MAX_PAGES) {
                Log::channel('attendance')->error('hikvision:backfill: exceeded max page count without exhausting results, aborting', [
                    'device_id' => $device->id,
                    'position' => $position,
                ]);

                return self::FAILURE;
            }

            try {
                $result = $fetcher->fetch($device, $user, $pass, [
                    'searchID' => $searchId,
                    'searchResultPosition' => $position,
                    'maxResults' => self::RESULTS_PER_PAGE,
                    'major' => 0,
                    'minor' => 0,
                    'startTime' => $startTime->toIso8601String(),
                    'endTime' => $endTime->toIso8601String(),
                ]);
            } catch (AcsEventFetchException $e) {
                Log::channel('attendance')->error('hikvision:backfill: '.$e->getMessage(), ['device_id' => $device->id]);

                return self::FAILURE;
            }

            foreach ($result['InfoList'] as $record) {
                $processor->handle($normalizer->fromAcsEventRecord($record), $device, 'backfill');
                $stored++;
            }

            $position += count($result['InfoList']);
            $hasMore = $result['responseStatusStrg'] === 'MORE' && count($result['InfoList']) > 0;

            $this->line("Page {$page}: ".count($result['InfoList'])." record(s), status=".($result['responseStatusStrg'] ?? 'null'));
        } while ($hasMore);

        $this->info("Backfill complete: {$stored} record(s) across {$page} page(s).");

        return self::SUCCESS;
    }
}
