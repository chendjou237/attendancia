<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\Console\GracefulShutdown;
use App\Services\DatabaseReadiness;
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
 * Every exit path except a deliberate stop is non-zero, including a
 * clean stream end — a finished stream still means punches have stopped
 * being recorded, and the supervisor should restart the worker. The one
 * exception matters for how the supervisor stops it: without a clean 0
 * on a deliberate stop, Supervisor waits out the full stopwaitsecs and
 * SIGKILLs (and NSSM waits out AppStopMethodConsole and kills the
 * process) instead of a fast, clean shutdown.
 *
 * "A deliberate stop" is SIGTERM/SIGINT on Linux and Ctrl+C/Ctrl+Break on
 * Windows; GracefulShutdown picks whichever this PHP build supports.
 */
class StreamHikvisionEvents extends Command
{
    protected $signature = 'hikvision:stream
        {device : devices.serial}
        {--skip-backfill : skip the boot-time backfill catch-up}
        {--wait-for-db : block until the database accepts connections before starting}
        {--db-timeout= : seconds to wait for the database, defaults to 300}';

    protected $description = 'Long-running worker for the Hikvision alertStream live event feed (§7.2).';

    private bool $stopping = false;

    public function handle(
        StreamConsumer $consumer,
        EventNormalizer $normalizer,
        EventProcessor $processor,
        DeviceClock $clock,
        GracefulShutdown $shutdown,
        DatabaseReadiness $readiness,
    ): int {
        // Signals first, before anything that can block: --wait-for-db can
        // legitimately sit here for minutes on a post-power-cut boot, and a
        // stop requested during that wait has to be honoured rather than
        // needing the supervisor's hard kill.
        $this->registerSignalHandlers($shutdown);

        if ($this->option('wait-for-db') && ! $this->waitForDatabase($readiness)) {
            return $this->stopping ? self::SUCCESS : self::FAILURE;
        }

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
     * Which mechanism is available depends on the platform — see
     * GracefulShutdown. Neither being available is not fatal: it only
     * means a stop arrives as a hard kill, which dedup and the boot-time
     * backfill already absorb. It is still worth a log line, because
     * "the worker never logs a clean exit" is otherwise indistinguishable
     * from a crash when you are reading the log after the fact.
     */
    private function registerSignalHandlers(GracefulShutdown $shutdown): void
    {
        $mechanism = $shutdown->register(function (): void {
            $this->stopping = true;
        });

        if ($mechanism === GracefulShutdown::NONE) {
            Log::channel('attendance')->warning(
                'hikvision:stream: no signal handling available on this PHP build — '
                .'stops will be a hard kill, not a clean exit. Ingestion is unaffected '
                .'(raw_events is deduped and the boot backfill re-reads the window).'
            );
        }
    }

    /**
     * docs/setup.md §1's boot order (database up -> backfill -> live
     * stream) folded into the worker itself, so the supervisor can point
     * straight at the PHP binary instead of wrapping it in a shell that
     * runs a readiness script first. That wrapper was also what stood
     * between the supervisor's stop signal and this process.
     */
    private function waitForDatabase(DatabaseReadiness $readiness): bool
    {
        $timeout = (int) ($this->option('db-timeout') ?: DatabaseReadiness::DEFAULT_TIMEOUT_SECONDS);

        $this->info("Waiting for the database, timeout {$timeout}s...");

        $ready = $readiness->waitUntilReady(
            timeoutSeconds: $timeout,
            onHeartbeat: fn (int $elapsed) => $this->line("Still waiting for the database... {$elapsed}s elapsed"),
            shouldStop: fn () => $this->stopping,
        );

        if ($ready) {
            $this->info('The database is up.');

            return true;
        }

        if ($this->stopping) {
            $this->info('Received termination signal while waiting for the database, exiting cleanly.');

            return false;
        }

        $this->error("Gave up after {$timeout}s — the database never became reachable.");

        return false;
    }
}
