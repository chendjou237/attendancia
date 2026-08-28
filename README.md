# Attendancia

Teacher attendance and payroll-hours tracking for a secondary school, built
around Hikvision fingerprint terminals.

A terminal sits in each corridor. A teacher scans at the start and end of a
lesson; the system pairs those two scans against the teacher's timetable and
decides, period by period, whether the lesson was actually taught. At month end
the totals become payable hours for hourly-paid staff and an oversight view of
everyone else.

**The system never guesses.** A scan that doesn't resolve cleanly to Present or
Absent becomes a *pending* result and goes to a human in the Exception queue. It
is a payroll input, so an ambiguous case is escalated, never quietly decided.

## How it fits together

```
Hikvision terminal
  │  live alertStream (hikvision:stream, kept up by a service supervisor)
  │  AcsEvent replay  (hikvision:backfill, on worker boot + nightly)
  ▼
raw_events ──────────── append-only; no updates, no deletes, ever
  │
  │  attendance:compute
  ▼
DayResolver → SessionBuilder → PairingEngine → RuleEngine → PeriodResultWriter
  │           group periods    match scans     grace-window   supersede, never
  │           into sessions    to a session    verdict        overwrite
  ▼
period_results ──────── one row per teacher, per date, per period
  │
  ▼
Monthly report → Officer review → Principal approval → HR
```

Both ingestion paths converge on a single `EventProcessor`, so the live stream
and a backfill cannot drift apart. Everything downstream is idempotent:
recomputing a date whose inputs haven't changed reproduces identical rows.

### The rules that shape the schema

- **Nothing is overwritten.** Rule versions, timetables, bell schedules and
  biometric enrolments are all versioned by `valid_from`; period results
  supersede via `is_current` rather than being updated in place. An October
  correction never rewrites September's pay.
- **`raw_events` is append-only.** It is the evidence trail behind every number.
- **Approved reports are frozen.** Once a month is Principal-approved,
  regenerating it is refused rather than silently redone.
- **Every human override is audited** — actor, timestamp, before, after, and a
  mandatory reason.

## Running it locally

Requires PHP 8.3+, MySQL 8 (or MariaDB 10.6+), and Node.

```bash
composer setup
```

Then seed a full, coherent demo dataset — reference data, two months of
simulated attendance run through the real ingestion pipeline, and generated
reports — so every screen has real engine-computed content without a device:

```bash
php artisan demo:seed --fresh
```

Start the dev server and sign in at `/admin`:

```bash
composer dev
```

The panel is in **French** by default; switch to English from the user menu.

## Tests

```bash
php artisan test
```

The suite runs against in-memory SQLite. Production is MySQL — see
`app/Casts/DateOnly.php` for the one place that difference is deliberately
neutralised, and `tests/Feature/DateRangeBoundaryTest.php` for the regressions
that hold it down.

## Commands

| Command | What it does |
|---|---|
| `attendance:compute {date?}` | Compute period attendance for one date across active teachers. Idempotent. |
| `hikvision:stream {device}` | Long-running worker for the live alertStream. Backfills first, then connects. |
| `hikvision:backfill {device}` | Replay historical AcsEvent records to fill gaps left by downtime. |
| `hikvision:dump {device}` | Reconnaissance: dump raw device events to confirm field names. |
| `teachers:import {file}` | Bulk create/update teachers from CSV. |
| `timetables:import {file}` | Bulk create/replace timetable versions from CSV. |
| `demo:seed` | Seed a realistic demo dataset with no device present. |

Both importers validate the **whole file** before writing anything: one bad row
means nothing is saved, and you get a line-by-line list of what to fix.

## Documentation

- **[docs/setup.md](docs/setup.md)** — the runbook for the school server and the
  fingerprint terminal. Read the power-model section first; it explains the boot
  order that makes every outage self-healing.
- **[docs/onboarding.md](docs/onboarding.md)** — what each role (Admin, Officer,
  Principal, HR) actually does day to day.
- **[CLAUDE.md](CLAUDE.md)** — conventions and invariants for anyone, human or
  agent, changing this codebase.

## Stack

Laravel 13 · Filament 5 · MySQL 8 · Spatie Permission · dompdf · Pest
