<?php

use App\Models\Corridor;
use App\Models\Device;
use App\Models\RawEvent;
use App\Models\Teacher;
use App\Models\TeacherBiometricId;
use App\Services\Hikvision\AcsEventFetchException;
use App\Services\Hikvision\AcsEventFetcher;

// .env's HIKVISION_USER/PASS are genuinely blank (never committed —
// see .env.example), so every test needs its own valid credentials to
// get past the command's own guard; only the "no credentials" test
// wants the real blank default.
beforeEach(function () {
    config(['attendance.default_user' => 'admin', 'attendance.default_pass' => 'secret']);
});

function backfillDevice(): Device
{
    return Device::factory()->for(Corridor::factory())->create(['serial' => 'DEV0001234']);
}

function acsRecord(int $serial, string $time = '2026-08-18T09:14:02+01:00', int $major = 5, int $minor = 38, string $employeeNo = '7'): array
{
    return ['serialNo' => $serial, 'time' => $time, 'major' => $major, 'minor' => $minor, 'employeeNoString' => $employeeNo];
}

/**
 * Binds a scripted fake in place of the real HTTP-backed fetcher.
 * Guzzle's digest-auth middleware runs its real challenge/response
 * handshake even against Http::fake(), and a POST body doesn't survive
 * that handshake in the fake handler — so this is the layer this
 * project's own pagination/dedup logic can actually be tested at,
 * rather than the HTTP call itself (see AcsEventFetcher's docblock).
 *
 * @param  array<int, array{InfoList: array, responseStatusStrg: ?string}>  $pages
 */
function fakeAcsEventFetcher(array $pages): void
{
    $fake = new class($pages) extends AcsEventFetcher
    {
        private int $call = 0;

        public function __construct(private array $pages) {}

        public function fetch($device, $user, $pass, $condition): array
        {
            return $this->pages[$this->call++] ?? ['InfoList' => [], 'responseStatusStrg' => null];
        }
    };

    app()->instance(AcsEventFetcher::class, $fake);
}

it('fails fast when no device with that serial exists', function () {
    $this->artisan('hikvision:backfill', ['device' => 'NOPE'])
        ->expectsOutputToContain('No device with serial')
        ->assertFailed();
});

it('fails fast when no credentials are configured', function () {
    config(['attendance.default_user' => null, 'attendance.default_pass' => null]);
    $d = backfillDevice();

    $this->artisan('hikvision:backfill', ['device' => $d->serial])
        ->expectsOutputToContain('No ISAPI credentials configured')
        ->assertFailed();
});

it('processes a single page of results through the event pipeline', function () {
    $d = backfillDevice();
    $teacher = Teacher::factory()->create();
    TeacherBiometricId::factory()->for($teacher)->create(['biometric_id' => '7', 'valid_from' => '2020-01-01']);

    fakeAcsEventFetcher([
        ['InfoList' => [acsRecord(1), acsRecord(2)], 'responseStatusStrg' => 'OK'],
    ]);

    $this->artisan('hikvision:backfill', ['device' => $d->serial])->assertSuccessful();

    expect(RawEvent::count())->toBe(2);
    expect(RawEvent::where('teacher_id', $teacher->id)->count())->toBe(2);
});

it('follows pagination across multiple pages until responseStatusStrg is no longer MORE', function () {
    $d = backfillDevice();

    fakeAcsEventFetcher([
        ['InfoList' => [acsRecord(1), acsRecord(2)], 'responseStatusStrg' => 'MORE'],
        ['InfoList' => [acsRecord(3), acsRecord(4)], 'responseStatusStrg' => 'MORE'],
        ['InfoList' => [acsRecord(5)], 'responseStatusStrg' => 'OK'],
    ]);

    $this->artisan('hikvision:backfill', ['device' => $d->serial])
        ->expectsOutputToContain('Backfill complete: 5 record(s) across 3 page(s).')
        ->assertSuccessful();

    expect(RawEvent::count())->toBe(5);
});

it('stops when a page returns an empty InfoList even if the status still says MORE', function () {
    $d = backfillDevice();

    fakeAcsEventFetcher([
        ['InfoList' => [acsRecord(1)], 'responseStatusStrg' => 'MORE'],
        ['InfoList' => [], 'responseStatusStrg' => 'MORE'],
    ]);

    $this->artisan('hikvision:backfill', ['device' => $d->serial])->assertSuccessful();

    expect(RawEvent::count())->toBe(1);
});

it('is idempotent: backfilling the same window twice does not duplicate rows', function () {
    $d = backfillDevice();

    fakeAcsEventFetcher([
        ['InfoList' => [acsRecord(1), acsRecord(2)], 'responseStatusStrg' => 'OK'],
    ]);
    $this->artisan('hikvision:backfill', ['device' => $d->serial])->assertSuccessful();

    fakeAcsEventFetcher([
        ['InfoList' => [acsRecord(1), acsRecord(2)], 'responseStatusStrg' => 'OK'],
    ]);
    $this->artisan('hikvision:backfill', ['device' => $d->serial])->assertSuccessful();

    expect(RawEvent::count())->toBe(2);
});

it('fails when the AcsEvent request itself fails', function () {
    $d = backfillDevice();

    $fake = new class extends AcsEventFetcher
    {
        public function fetch($device, $user, $pass, $condition): array
        {
            throw new AcsEventFetchException('AcsEvent request failed: HTTP 500');
        }
    };
    app()->instance(AcsEventFetcher::class, $fake);

    $this->artisan('hikvision:backfill', ['device' => $d->serial])->assertFailed();

    expect(RawEvent::count())->toBe(0);
});

it('respects the --hours option over the config default', function () {
    $d = backfillDevice();

    $fake = new class extends AcsEventFetcher
    {
        public ?array $captured = null;

        public function fetch($device, $user, $pass, $condition): array
        {
            $this->captured = $condition;

            return ['InfoList' => [], 'responseStatusStrg' => null];
        }
    };
    app()->instance(AcsEventFetcher::class, $fake);

    $this->artisan('hikvision:backfill', ['device' => $d->serial, '--hours' => 5])->assertSuccessful();

    $start = \Carbon\Carbon::parse($fake->captured['startTime']);
    $end = \Carbon\Carbon::parse($fake->captured['endTime']);
    expect((int) $start->diffInHours($end))->toBe(5);
});
