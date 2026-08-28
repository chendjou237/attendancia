<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Polls the app's own database connection until it actually serves a
 * query, or a bounded timeout elapses.
 *
 * Why this exists: docs/setup.md §1 requires "MySQL up -> backfill runs ->
 * live stream starts" on every boot, but the database server and whatever
 * supervises the stream worker are two independently-started services with
 * no ordering between them, and a service reporting "running" does not mean
 * InnoDB crash recovery has actually finished after an unclean shutdown
 * (this server has no UPS — see §1). This is the real readiness check.
 *
 * This was a bash script (deploy/scripts/wait-for-mysql.sh) shelling out to
 * mysqladmin. It is PHP now because the production server is Windows, where
 * neither bash nor a mysqladmin on PATH can be assumed — and because reusing
 * Laravel's own configured connection means there is no second set of
 * credentials to keep in step with .env.
 *
 * On timeout this reports failure rather than looping forever, and hands off
 * to the supervisor's own restart loop (already relied on for the
 * device-unreachable case): one retry mechanism, one log location.
 */
class DatabaseReadiness
{
    public const DEFAULT_TIMEOUT_SECONDS = 300;

    private const POLL_INTERVAL_SECONDS = 2;

    private const HEARTBEAT_EVERY_SECONDS = 15;

    /**
     * @param  callable(int): void|null  $onHeartbeat  called with elapsed seconds
     * @param  callable(): bool|null  $shouldStop  lets a termination signal
     *                                             cut a long wait short
     * @return bool true once the connection is usable; false on timeout or
     *              on an early stop
     */
    public function waitUntilReady(
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?callable $onHeartbeat = null,
        ?callable $shouldStop = null,
        ?string $connection = null,
    ): bool {
        $elapsed = 0;
        $nextHeartbeat = self::HEARTBEAT_EVERY_SECONDS;

        while (true) {
            if ($shouldStop !== null && $shouldStop()) {
                return false;
            }

            if ($this->canConnect($connection)) {
                return true;
            }

            if ($elapsed >= $timeoutSeconds) {
                return false;
            }

            if ($onHeartbeat !== null && $elapsed >= $nextHeartbeat) {
                $onHeartbeat($elapsed);
                $nextHeartbeat += self::HEARTBEAT_EVERY_SECONDS;
            }

            Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();
            $elapsed += self::POLL_INTERVAL_SECONDS;
        }
    }

    /**
     * A real round trip, not just getPdo(): the case this whole class
     * exists for is a server that is accepting TCP connections while
     * InnoDB is still replaying its redo log, where connecting can
     * succeed and querying still fails. `select 1` is the portable way to
     * ask "are you actually serving?" and is what makes the answer mean
     * something.
     *
     * Throwable, not PDOException: a misconfigured driver, a missing
     * extension or a bad DSN all surface differently, and none of them
     * should crash a readiness probe — they should read as "not ready yet"
     * and let the timeout be the thing that gives up.
     */
    public function canConnect(?string $connection = null): bool
    {
        try {
            DB::connection($connection)->select('select 1');

            return true;
        } catch (Throwable) {
            // purge(), not disconnect(). disconnect() nulls the PDO but
            // leaves the Connection cached, and getPdo() then hands back
            // that null without reconnecting — so the *next* poll sees no
            // exception and reports a dead server as ready. purge() drops
            // the Connection entirely, so the next attempt builds a fresh
            // one and genuinely retries.
            try {
                DB::purge($connection);
            } catch (Throwable) {
                // No such connection to purge — it was never configured.
            }

            return false;
        }
    }
}
