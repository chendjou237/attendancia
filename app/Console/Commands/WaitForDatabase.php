<?php

namespace App\Console\Commands;

use App\Services\DatabaseReadiness;
use Illuminate\Console\Command;

/**
 * The standalone half of the boot-order guard (docs/setup.md §1): blocks
 * until the database is genuinely accepting connections, so nothing that
 * needs it starts against a server still doing InnoDB crash recovery
 * after a power cut.
 *
 * `hikvision:stream --wait-for-db` does the same thing in-process, which
 * is what the supervised worker uses. This command exists for the manual
 * cases — checking by hand whether the database is actually up, or
 * guarding some other scheduled job — and is the portable replacement for
 * what used to be deploy/scripts/wait-for-mysql.sh.
 */
class WaitForDatabase extends Command
{
    protected $signature = 'db:wait
        {--timeout= : seconds before giving up, defaults to 300}
        {--connection= : a specific connection name, defaults to the configured one}';

    protected $description = 'Block until the database accepts connections, or give up after a timeout.';

    public function handle(DatabaseReadiness $readiness): int
    {
        $timeout = (int) ($this->option('timeout') ?: DatabaseReadiness::DEFAULT_TIMEOUT_SECONDS);
        $connection = $this->option('connection') ?: null;

        $name = $connection ?? config('database.default');
        $this->info("Waiting for the [{$name}] connection, timeout {$timeout}s...");

        $ready = $readiness->waitUntilReady(
            timeoutSeconds: $timeout,
            onHeartbeat: fn (int $elapsed) => $this->line("Still waiting for the database... {$elapsed}s elapsed"),
            connection: $connection,
        );

        if (! $ready) {
            $this->error("Gave up after {$timeout}s — the database never became reachable.");

            return self::FAILURE;
        }

        $this->info('The database is up.');

        return self::SUCCESS;
    }
}
