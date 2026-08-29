<?php

namespace App\Services\Console;

/**
 * Registers "please stop" handlers for a long-running artisan command,
 * picking whichever mechanism the current PHP build actually has.
 *
 * This exists because the deployment target is a Windows machine and
 * ext-pcntl does not exist on Windows at all — not "is usually missing",
 * it is not buildable there. An unguarded pcntl_async_signals() call is
 * a fatal error, so the worker would die on startup rather than run
 * without graceful shutdown.
 *
 * The three branches, in preference order:
 *
 *  - PCNTL   — SIGTERM/SIGINT. What Supervisor sends (stopsignal=TERM).
 *  - WINDOWS — sapi_windows_set_ctrl_handler(), which catches Ctrl+C and
 *              Ctrl+Break. That is what NSSM's AppStopMethodConsole
 *              delivers on `nssm stop`, so it is the true analogue of
 *              the Supervisor path, not a degraded fallback. Note the
 *              handler is invoked on a *separate thread*, so it must do
 *              nothing but flip a flag — which is all $onStop does.
 *  - NONE    — neither available (or the Windows handler refused to
 *              install). The caller is expected to say so out
 *              loud and carry on: the worker still ingests correctly,
 *              it just gets killed mid-read instead of exiting cleanly.
 *              That is survivable here because raw_events is deduped by
 *              UNIQUE(device_serial, device_event_serial) and the
 *              boot-time backfill re-reads the window, so a hard kill
 *              costs a few ignored duplicate-key hits and nothing else.
 *
 * The detection and registration methods are protected rather than
 * inlined so tests can force a branch the host platform cannot reach —
 * a Linux CI box can never execute the Windows path for real.
 */
class GracefulShutdown
{
    public const PCNTL = 'pcntl';

    public const WINDOWS = 'windows';

    public const NONE = 'none';

    /**
     * @param  callable(): void  $onStop  invoked once a stop is requested
     * @return string one of the class constants — the mechanism actually used
     */
    public function register(callable $onStop): string
    {
        if ($this->supportsPcntl()) {
            $this->registerPcntl($onStop);

            return self::PCNTL;
        }

        // Unlike pcntl, this one can be present and still refuse to
        // install: the handler needs a console, and a service wrapper
        // configured not to allocate one leaves PHP with nowhere to
        // deliver Ctrl+C. Treat that as "no mechanism" so the caller says
        // so, rather than reporting a shutdown path that will never fire.
        if ($this->supportsWindowsCtrlHandler() && $this->registerWindowsCtrlHandler($onStop)) {
            return self::WINDOWS;
        }

        return self::NONE;
    }

    protected function supportsPcntl(): bool
    {
        return function_exists('pcntl_async_signals') && function_exists('pcntl_signal');
    }

    protected function supportsWindowsCtrlHandler(): bool
    {
        return function_exists('sapi_windows_set_ctrl_handler');
    }

    /**
     * pcntl_async_signals(true) must come before pcntl_signal() — without
     * it, handlers never fire during a blocking stream read, and
     * Supervisor's TERM does nothing until stopwaitsecs forces a SIGKILL.
     */
    protected function registerPcntl(callable $onStop): void
    {
        pcntl_async_signals(true);

        $handler = static function () use ($onStop): void {
            $onStop();
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    /**
     * The handler receives the event id (PHP_WINDOWS_EVENT_CTRL_C or
     * PHP_WINDOWS_EVENT_CTRL_BREAK); both mean the same thing to us. Once
     * a handler is set, PHP stops terminating the process itself on
     * Ctrl+C, which is exactly what lets the read loop notice the flag
     * and unwind through the command's own clean-exit path.
     *
     * @return bool whether the handler was actually installed
     */
    protected function registerWindowsCtrlHandler(callable $onStop): bool
    {
        return sapi_windows_set_ctrl_handler(static function (int $event) use ($onStop): void {
            $onStop();
        });
    }
}
