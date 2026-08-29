<?php

use App\Services\Console\GracefulShutdown;

/**
 * The point of this class is that the production target (Windows) cannot
 * run the branch the development machine (Linux) runs, and vice versa.
 * Every test here that is not about the local platform forces its branch
 * through a subclass, because there is no host on which all three are
 * reachable for real.
 */
function fakeShutdown(bool $pcntl, bool $windows, bool $windowsInstalls = true): GracefulShutdown
{
    return new class($pcntl, $windows, $windowsInstalls) extends GracefulShutdown
    {
        /** @var list<string> */
        public array $calls = [];

        public function __construct(
            private readonly bool $pcntl,
            private readonly bool $windows,
            private readonly bool $windowsInstalls,
        ) {}

        protected function supportsPcntl(): bool
        {
            return $this->pcntl;
        }

        protected function supportsWindowsCtrlHandler(): bool
        {
            return $this->windows;
        }

        protected function registerPcntl(callable $onStop): void
        {
            $this->calls[] = 'pcntl';
        }

        protected function registerWindowsCtrlHandler(callable $onStop): bool
        {
            $this->calls[] = 'windows';

            return $this->windowsInstalls;
        }
    };
}

it('prefers pcntl when the build has it', function () {
    $shutdown = fakeShutdown(pcntl: true, windows: true);

    expect($shutdown->register(fn () => null))->toBe(GracefulShutdown::PCNTL);
    expect($shutdown->calls)->toBe(['pcntl']);
});

it('falls back to the Windows console handler when pcntl is absent', function () {
    $shutdown = fakeShutdown(pcntl: false, windows: true);

    expect($shutdown->register(fn () => null))->toBe(GracefulShutdown::WINDOWS);
    expect($shutdown->calls)->toBe(['windows']);
});

// The regression this whole class exists for: on a Windows PHP build
// there is no ext-pcntl, and the previous unguarded pcntl_async_signals()
// call was a fatal error — the worker died on startup rather than running
// without graceful shutdown.
it('reports that no mechanism is available instead of fataling', function () {
    $shutdown = fakeShutdown(pcntl: false, windows: false);

    expect($shutdown->register(fn () => null))->toBe(GracefulShutdown::NONE);
    expect($shutdown->calls)->toBe([]);
});

// sapi_windows_set_ctrl_handler exists but returns false when the process
// has no console to deliver Ctrl+C to — a service wrapper configured not to
// allocate one. Reporting WINDOWS there would claim a shutdown path that can
// never fire.
it('treats a console handler that refuses to install as no mechanism at all', function () {
    $shutdown = fakeShutdown(pcntl: false, windows: true, windowsInstalls: false);

    expect($shutdown->register(fn () => null))->toBe(GracefulShutdown::NONE);
    expect($shutdown->calls)->toBe(['windows']);
});

it('actually flips the flag when a real signal arrives', function () {
    $stopping = false;

    expect((new GracefulShutdown)->register(function () use (&$stopping) {
        $stopping = true;
    }))->toBe(GracefulShutdown::PCNTL);

    // pcntl_async_signals(true) is the load-bearing half: without it the
    // handler would not fire until the process next hit a tick, which
    // during a blocking stream read is never.
    posix_kill(posix_getpid(), SIGTERM);
    pcntl_signal_dispatch();

    expect($stopping)->toBeTrue();
})->skip(
    ! function_exists('pcntl_async_signals') || ! function_exists('posix_kill'),
    'needs ext-pcntl and ext-posix — by design absent on the Windows target',
);

// Signal handlers and async dispatch are process-wide, and the suite runs
// in one process — leaving either set would follow every test after this one.
afterEach(function () {
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
        pcntl_async_signals(false);
    }
});
