<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\Hikvision\DeviceClock;
use App\Services\Hikvision\DeviceCredentials;
use App\Services\Hikvision\EventNormalizer;
use App\Services\Hikvision\EventProcessor;
use App\Services\Hikvision\StreamConsumer;
use App\Services\Hikvision\StreamIdleTimeoutException;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * §7.2: the live path. Holds the alertStream connection open for days,
 * parsing JSON objects out of the byte stream as they arrive.
 *
 * Every exit path except a clean SIGTERM/SIGINT is non-zero, including
 * a clean stream end — a finished stream still means punches have
 * stopped being recorded, and Supervisor should restart the worker.
 * The one exception matters for Supervisor's own stopwaitsecs: without
 * a clean 0 on a deliberate stop, Supervisor waits out the full
 * stopwaitsecs timeout and SIGKILLs instead of a fast, clean shutdown.
 */
class StreamHikvisionEvents extends Command
{
    protected $signature = 'hikvision:stream {device : devices.serial} {--skip-backfill : skip the boot-time backfill catch-up}';

    protected $description = 'Long-running worker for the Hikvision alertStream live event feed (§7.2).';

    private bool $stopping = false;

    public function handle(
        StreamConsumer $consumer,
        EventNormalizer $normalizer,
        EventProcessor $processor,
        DeviceClock $clock,
    ): int {
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

        $this->registerSignalHandlers();

        $clock->poll($device, $user, $pass);

        if (! $this->option('skip-backfill')) {
            $this->info('Running boot-time backfill catch-up...');
            $this->call('hikvision:backfill', ['device' => $device->serial]);
        }

        if ($this->stopping) {
            return self::SUCCESS;
        }

        $this->info("Connecting to alertStream on {$device->ip}...");

        // §7's stream worker notes: no HTTP timeout on the stream
        // connection at all, or the default 30s silently kills it and
        // presents as "the device randomly stopped sending events."
        //
        // connectTimeout() only bounds establishing the TCP connection.
        // An unreachable host (no route, wrong IP) fails before that —
        // Laravel's HTTP client throws ConnectionException rather than
        // returning a Response, so this must be a try/catch, not a
        // ->successful() check, or the command crashes with an uncaught
        // exception instead of a controlled non-zero exit.
        try {
            $response = Http::withDigestAuth($user, $pass)
                ->connectTimeout(10)
                ->timeout(0)
                ->withOptions(['stream' => true])
                ->get("http://{$device->ip}/ISAPI/Event/notification/alertStream");
        } catch (ConnectionException $e) {
            Log::channel('attendance')->error('hikvision:stream: could not connect', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        if (! $response->successful()) {
            Log::channel('attendance')->error('hikvision:stream: connection failed', [
                'device_id' => $device->id,
                'status' => $response->status(),
            ]);

            return self::FAILURE;
        }

        $this->info('Connected. Streaming events...');

        try {
            $consumer->consume(
                $response->toPsrResponse()->getBody(),
                function (string $json) use ($device, $normalizer, $processor) {
                    $decoded = json_decode($json, true);

                    if (! is_array($decoded)) {
                        Log::channel('attendance')->warning('hikvision:stream: discarding a captured object that was not valid JSON', [
                            'device_id' => $device->id,
                        ]);

                        return;
                    }

                    $processor->handle($normalizer->fromStreamPayload($decoded), $device, 'stream');
                },
                idleTimeoutSeconds: (int) config('attendance.idle_timeout'),
                shouldStop: fn () => $this->stopping,
            );
        } catch (StreamIdleTimeoutException $e) {
            Log::channel('attendance')->warning('hikvision:stream: '.$e->getMessage(), ['device_id' => $device->id]);

            return self::FAILURE;
        }

        if ($this->stopping) {
            $this->info('Received termination signal, exiting cleanly.');

            return self::SUCCESS;
        }

        // The stream ended on its own (EOF) without us asking it to —
        // still not the deliberate-stop case, still needs a restart.
        Log::channel('attendance')->warning('hikvision:stream: stream ended unexpectedly (EOF, no termination signal)', [
            'device_id' => $device->id,
        ]);

        return self::FAILURE;
    }

    /**
     * pcntl_async_signals(true) must come before pcntl_signal() — without
     * it, handlers never fire during a blocking stream read, and
     * Supervisor's TERM does nothing until stopwaitsecs forces a SIGKILL.
     */
    private function registerSignalHandlers(): void
    {
        pcntl_async_signals(true);

        $handler = function (): void {
            $this->stopping = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }
}
