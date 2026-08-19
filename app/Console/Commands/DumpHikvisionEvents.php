<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\Hikvision\DeviceCredentials;
use App\Services\Hikvision\JsonStreamParser;
use App\Services\Hikvision\StreamConsumer;
use App\Services\Hikvision\StreamIdleTimeoutException;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Phase C1 reconnaissance (§13.5): dump raw stream events plus a few
 * device info endpoints to fixture files, so field spellings
 * (serialNo, employeeNoString, dateTime — and card/face sub-event
 * codes, open question §13 #2) can be confirmed against this specific
 * firmware before EventNormalizer's guesses for the AcsEvent search
 * endpoint are trusted. Run this before hikvision:stream or
 * hikvision:backfill see production use.
 */
class DumpHikvisionEvents extends Command
{
    protected $signature = 'hikvision:dump
        {device : devices.serial}
        {--seconds=120 : how long to capture the live stream}';

    protected $description = 'Reconnaissance: dump raw device events and info to confirm field names before trusting them (§13.5).';

    public function handle(StreamConsumer $consumer): int
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

        $dir = storage_path('app/hikvision-dump/'.$device->serial.'/'.now()->format('Y-m-d_His'));
        File::ensureDirectoryExists($dir);
        $this->info("Writing fixtures to {$dir}");

        $this->dumpJsonEndpoint($device, $user, $pass, '/ISAPI/System/deviceInfo?format=json', "{$dir}/device_info.json");
        $this->dumpJsonEndpoint($device, $user, $pass, '/ISAPI/System/time?format=json', "{$dir}/system_time.json");
        $this->dumpJsonEndpoint($device, $user, $pass, '/ISAPI/AccessControl/AcsEvent/capabilities?format=json', "{$dir}/acs_event_capabilities.json");

        $this->streamEvents($device, $user, $pass, $dir, $consumer);

        $this->info('Dump complete. Compare field names against §7.3 / EventNormalizer before trusting the backfill path.');

        return self::SUCCESS;
    }

    private function dumpJsonEndpoint(Device $device, string $user, string $pass, string $path, string $writeTo): void
    {
        try {
            $response = Http::withDigestAuth($user, $pass)
                ->connectTimeout(10)
                ->get("http://{$device->ip}{$path}");

            File::put($writeTo, $response->body());
            $this->line("{$path} -> HTTP {$response->status()} (".basename($writeTo).')');
        } catch (\Throwable $e) {
            $this->warn("{$path} -> failed: {$e->getMessage()}");
        }
    }

    private function streamEvents(Device $device, string $user, string $pass, string $dir, StreamConsumer $consumer): void
    {
        $seconds = (int) $this->option('seconds');
        $this->info("Streaming for up to {$seconds}s — scan a finger now if you want a real event captured.");

        // §7's stream worker notes: no HTTP timeout on the stream
        // connection, or the default 30s kills it long before this
        // command's own --seconds bound is reached.
        //
        // connectTimeout() only bounds establishing the TCP connection —
        // an unreachable host fails before that with ConnectionException
        // rather than a Response, so this must be a try/catch (see
        // StreamHikvisionEvents for where this was first caught missing).
        try {
            $response = Http::withDigestAuth($user, $pass)
                ->connectTimeout(10)
                ->timeout(0)
                ->withOptions(['stream' => true])
                ->get("http://{$device->ip}/ISAPI/Event/notification/alertStream");
        } catch (ConnectionException $e) {
            $this->error("Stream connection failed: {$e->getMessage()}");

            return;
        }

        if (! $response->successful()) {
            $this->error("Stream request failed: HTTP {$response->status()}");

            return;
        }

        $deadline = now()->addSeconds($seconds);
        $count = 0;

        try {
            $consumer->consume(
                $response->toPsrResponse()->getBody(),
                function (string $json) use (&$count, $dir) {
                    $count++;
                    $decoded = json_decode($json, true);
                    File::put(
                        sprintf('%s/event_%03d.json', $dir, $count),
                        json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: $json,
                    );
                    $this->line("[{$count}] ".Str::limit($json, 150));
                },
                idleTimeoutSeconds: (int) config('attendance.idle_timeout'),
                shouldStop: fn () => now()->greaterThanOrEqualTo($deadline),
            );
        } catch (StreamIdleTimeoutException $e) {
            $this->warn($e->getMessage());
        }

        $this->info("Captured {$count} event(s).");
    }
}
