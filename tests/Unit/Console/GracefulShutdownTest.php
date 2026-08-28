<?php

use App\Services\Console\GracefulShutdown;

/**
 * The point of this class is that the production target (Windows) cannot
 * run the branch the development machine (Linux) runs, and vice versa.
 * Every test here that is not about the local platform forces its branch
 * through a subclass, because there is no host on which all three are
 * reachable for real.
 */
function fakeShutdown(bool $pcntl, bool $windows): GracefulShutdown
{
    return new class($pcntl, $windows) extends GracefulShutdown
    {
        /** @var list<string> */
        public array $calls = [];

        public function __construct(
            private readonly bool $pcntl,
            private readonly bool $windows,
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

        protected function registerWindowsCtrlHandler(callable $onStop): void
        {
            $this->calls[] = 'windows';
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

it('actually flips the flag when a real signal arrives', function () {
    $stopping = false;

    expect((new GracefulShutdown)->register(function () use (&$stopping) {
        $stopping = true;
    }))->toBe(GracefulShutdown::PCNTL);

    // pcntl_async_signals(true) is the load-bearing half: without it the
    // handler would not fire until the process next hit a tick, which
    // during a blocking stream read is never.
    posix_kill(posix_getpid(), SIGTERM);

    expect($stopping)->toBeTrue();
})->skip(
    ! function_exists('pcntl_async_signals') || ! function_exists('posix_kill'),
    'needs ext-pcntl and ext-posix — by design absent on the Windows target',
);

afterEach(function () {
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
    }
});
