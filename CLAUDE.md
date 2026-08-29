# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

**PHP version matters.** On the macOS dev machine, `php` on PATH is 8.2, below
this project's `^8.3` requirement. Use Herd's 8.4 binary for anything that
boots the app:

```bash
"/Users/saint/Library/Application Support/Herd/bin/php84" artisan test
```

On the Windows server it's `C:\php\php.exe` (the Non-Thread-Safe build — see
`docs/setup.md` §3). Wherever you are, check `php -v` before assuming the `php`
on PATH is the right one; a wrong binary is the most common cause of a confusing
failure here.

| Task | Command |
|---|---|
| Full test suite | `php artisan test` |
| One file | `php artisan test tests/Feature/Attendance/PairingEngineTest.php` |
| One test by name | `php artisan test --filter="pairs a scan-in"` |
| Format | `vendor/bin/pint` (check only: `--test`) |
| Demo dataset | `php artisan demo:seed --fresh` |
| Dev server | `composer dev` |

Tests run on in-memory SQLite; production is MySQL 8. The suite needs more than
PHP's default 128M (dompdf), so `phpunit.xml` raises `memory_limit` — if you run
`vendor/bin/pest` directly, pass `-d memory_limit=512M`.

**Local MySQL is on port 3307, not 3306.** Herd runs its own MySQL on 3306 that
will reject this project's credentials with a confusing "Access denied" — check
`DB_PORT` in `.env` before concluding the database is unreachable. `attendancia_test`
is a disposable database granted to the same user; use it for anything
destructive (`migrate:fresh`), never the `attendancia` database, which holds
working demo data.

```bash
mysql -u attendancia -h 127.0.0.1 -P 3307 --protocol=TCP attendancia
```

**Verify migrations against MySQL, not just SQLite.** They diverge on foreign
keys: MySQL auto-creates a backing index for every FK and refuses to drop the
last index supporting one (errno 1553), while SQLite doesn't index FKs at all.
A `down()` that rolls back cleanly on SQLite can still fail on MySQL — this has
already happened once, see the comment in
`database/migrations/2026_08_21_000000_add_pairing_index_to_raw_events_table.php`.

`vendor/bin/pint --test` currently reports ~27 pre-existing failures in files
untouched by recent work. Format only what you change; a repo-wide `pint` run
would bury real edits in an unrelated diff.

## Architecture

Attendance is computed by a pipeline, not by a single service. Understanding any
one stage requires knowing its neighbours:

```
raw_events → DayResolver → SessionBuilder → PairingEngine → RuleEngine → PeriodResultWriter
```

- **`DayResolver`** decides what the day even *is* before anything is built.
  A public holiday or closure produces no expected sessions; a half-day caps
  which slots count (`$maxSeq`, threaded through the whole chain); a
  classes-suspended day writes `PRESENT_ADMIN` with no scan required for
  in-scope class codes only.
- **`SessionBuilder`** groups consecutive timetable periods with the same class
  code in the same room into one session. Breaks never split a session; a free
  period always does.
- **`PairingEngine`** matches `raw_events` to a session's scan-in/scan-out.
- **`RuleEngine`** applies one grace-window rule per period slot. A session that
  never paired is never evaluated — its slots become pending, never a silent
  `ABSENT`.
- **`PeriodResultWriter`** is the single write path for `period_results` and owns
  the versioning contract described below.

Ingestion has two paths — the live `hikvision:stream` worker and the
`hikvision:backfill` replay — that **converge on `EventProcessor`**. Do not add
filtering or normalisation logic to either path; it belongs in `EventProcessor`
or `EventNormalizer` so the two cannot drift apart.

The Filament admin panel at `/admin` is the only UI. Three custom pages
(`ExceptionQueue`, `TeacherAttendance`, `DeviceMonitor`) sit alongside the
generated resources.

## Invariants

These are load-bearing. Changes that break them corrupt a payroll input.

- **Never overwrite; supersede.** `rule_versions`, `timetable_versions`,
  `period_slots` and `teacher_biometric_ids` are versioned by `valid_from`.
  `period_results` recomputed under a *different* rule version inserts a new row
  and flips the old to `is_current = false`. Always read through the `current()`
  scope.
- **`raw_events` is append-only.** No updates, no deletes. It has no
  `updated_at`, deliberately.
- **`effectiveStatus()` is `override_status ?? status`.** Never read `status`
  directly when producing a figure anyone acts on.
- **Approved reports are frozen.** `MonthlyReportGenerator::generate()` refuses
  to touch a report past `OfficerReviewed`.
- **Every mutation of a computed record calls `AuditLog::record()`** with actor,
  before, after, and a reason.
- **Attendance requires a fingerprint.** `EventProcessor` recognises
  `majorEventType 5` sub-types `38` (fingerprint passed), `1` (card passed) and
  `49` (failed). Only `38` resolves a `teacher_id`. Card support is a
  *deliberate refusal*, not a gap: `PairingEngine` selects candidates by
  `teacher_id`, so leaving it null is the only thing keeping a lendable
  proximity card out of payroll. Do not widen `resolveTeacherId()`;
  `tests/Feature/Attendance/CardVerificationTest.php` will fail if you do.
- **Times are UTC; period slots are wall-clock.** Convert via
  `config('attendance.timezone')`. Always compare on
  `RawEvent::effectiveTime()` (device time, falling back to server time) — a
  backfilled event's `event_time_server` is when ingestion ran, not when the
  scan happened.
- **Dates use `App\Casts\DateOnly`**, which stores a bare `Y-m-d` on every
  engine. This is why plain `where('date', ...)` comparisons are correct *and*
  index-using; do not reintroduce `whereDate()`, which is not sargable and
  defeats `period_results_current_lookup`. If a comparison's **value** side is a
  Carbon rather than a date string, normalise it with `->toDateString()`.

## Roles

Four roles — `admin`, `officer`, `principal`, `hr` — enforced via
`canViewAny()` / `canAccess()` on each resource and page, not policies. The
per-role boundaries are documented in `docs/onboarding.md` and pinned by
`tests/Feature/Filament/AuthorizationTest.php`; keep all three in sync.

## Localisation

The panel defaults to **French**. `lang/en` and `lang/fr` must stay at full
parity — every user-facing string goes through `__('panel....')` or
`__('attendance....')`. Never hardcode display text in a resource, page, or
Blade view.

## Platform support

Development is on macOS/Linux; **production is Windows Server**. The
application code has to run on both, and the ways that breaks are quiet:

- **No unguarded `pcntl_*` or `posix_*`.** Neither extension exists on
  Windows, so an unguarded call is a fatal error, not a degraded feature.
  Graceful shutdown goes through `App\Services\Console\GracefulShutdown`,
  which picks `pcntl` or `sapi_windows_set_ctrl_handler()` at runtime; add
  new long-running commands through it rather than calling either directly.
- **No shell-outs.** No `exec`, `shell_exec`, `proc_open`, or backticks, and
  no assuming a Unix binary is on PATH. The readiness check that used to be
  `wait-for-mysql.sh` is `App\Services\DatabaseReadiness` for exactly this
  reason.
- **Build paths with `storage_path()`/`base_path()`**, never by concatenating
  a leading `/`. Forward slashes inside a path are fine on Windows; a `:` in
  a filename is not (see `DumpHikvisionEvents`' `His` timestamp format).
- **Deploy configs come in pairs.** `deploy/supervisor/` + `deploy/windows/`,
  `public/.htaccess` + `public/web.config`. Changing one means changing the
  other, and `docs/setup.md` documents both.

## Known unfixed issues

Found in review, deliberately deferred — don't rediscover them, and prefer
fixing over working around:

1. **`DeviceMonitor::simulateScan()` is not environment-gated.** Any admin or
   officer on production can inject a fabricated `raw_events` row that becomes
   payable hours, and it writes no audit entry.
2. **`EventProcessor` duplicate check is check-then-insert**, not atomic. The
   nightly backfill and the live stream worker write concurrently, so an
   overlapping window can throw an uncaught unique-constraint violation.
3. **`ViewMonthlyReport`'s `regenerate`, `markReviewed` and `sendToHr` actions
   check state but not role**, so an `hr` user can advance the report workflow.
4. **No CI.** ~4,000 lines of tests that nothing runs automatically.

Hardware note: supported terminals are `DS-K1A8603` and `DS-K1T8005EFX`. They
are identical over ISAPI; only the latter has a card reader. `devices.model` is
free text (the fleet will grow models this code has not heard of) and nothing in
the ingestion path branches on it.
