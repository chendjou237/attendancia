# Onboarding guide

What each role actually does in Attendancia, day to day. If you're setting up
the server or the fingerprint terminal itself rather than using the app,
see [docs/setup.md](setup.md) instead.

## What the system is for

Fingerprint terminals sit one per corridor. A teacher scans at the start and
end of a lesson; the system matches those two scans against the timetable and
decides, period by period, whether they actually taught it. At month end, the
totals feed payroll for hourly-paid staff and give the principal an oversight
view for everyone else.

The system never guesses silently. If a scan doesn't clearly resolve to
`Present` or `Absent`, it lands in the **Exception queue** for a human to
decide — see §3 below.

## Logging in

Go to `http://<server-address>/admin` and sign in with the account your
admin created for you (see [docs/setup.md](setup.md) §9 if you're the one
setting up the first accounts).

**One thing to know now**: the admin panel doesn't yet restrict which screens
each role can see — logging in shows you the full menu regardless of your
role. The sections below describe what your role is actually *responsible*
for, not what's technically hidden from you. Please stick to your own lane
even though the door isn't locked — this is a known gap, not a design
decision.

## Status glossary

Every period a teacher was scheduled to teach ends up with one of these:

| Status | Meaning | Counts as taught? |
|---|---|---|
| **Present** | Scanned in and out, inside the rules, for this exact period | Yes |
| **Present (administrative)** | Marked present for a non-scan reason (e.g. an approved administrative duty) | Yes |
| **Absent** | Expected to teach, no valid matching scans | No |
| **Absent (justified)** | Confirmed absent, but for an excused reason | No |
| **Unpaired** | A scan exists but doesn't cleanly close into a session (missing scan-out, or too short to be plausible) — needs a human decision | Pending |
| **Location mismatch** | The teacher scanned on a different corridor's terminal than their scheduled session | Pending |

**Present** and **Absent** are the engine's own conclusion — don't touch
those unless something is genuinely wrong. **Unpaired** and **Location
mismatch** are *not* a verdict; they mean the system couldn't tell, and it
refuses to guess. Those are what the Officer resolves every day in the
Exception queue.

---

## 1. Admin

You own the reference data everyone else's work depends on: corridors,
rooms, class codes, devices, the bell schedule, calendar, rule settings,
teachers, and timetables. Get this right once per term and the rest of the
system runs itself.

**Screens you'll use:**

- **Corridors / Rooms / Class codes / Devices** — straightforward CRUD forms.
  Enter these once at the start of term; they rarely change mid-year.
- **Calendar** — mark public holidays, school closures, half-days, and
  suspended-classes days (e.g. Form 5 sitting a sequence exam — you can scope
  a suspension to just the affected class codes rather than cancelling the
  whole school day). Do this as far ahead as you know about it; a day marked
  after the fact still recomputes correctly, it's just better to be ahead of
  it.
- **Rule versions** — the grace windows and pairing rules the engine applies
  (how late is still "on time," how short a gap can't be a real lesson,
  etc.). You will rarely touch this after initial setup. When you do,
  changes only apply from their `valid_from` date forward — an October
  correction never rewrites September's already-computed results, so past
  pay periods stay reproducible.
- **Teachers** — add, edit, deactivate. Each teacher has an
  `employment_type` (hourly-paid or salaried) — this is what determines
  whether their hours drive payroll or are tracked for oversight only.
- **Manage timetable** (from a teacher's page) — the grid entry screen,
  shaped like the paper timetable: day columns, period rows, class code per
  cell. Good for one-off corrections to a single teacher's grid.
- **Teacher Biometric IDs** — maps a device-side fingerprint ID to a
  teacher. Most teachers only ever need one row. If someone's fingerprint
  wears out and they get re-enrolled on the device with a new ID, add a
  *new* row for them rather than editing the old one — assigning a new ID
  automatically closes out the previous mapping, so historic scans still
  resolve correctly to the same teacher.
- **Audit log** — every override anywhere in the system, with who did it,
  when, and why. Useful when a number is questioned months later.

**Bulk import — use this instead of the forms for anything beyond a handful
of teachers.** Entering 60–100 teachers and their grids by hand in the admin
UI is days of work; two commands do it from a spreadsheet export instead.
These currently run from the server's command line (ask whoever manages the
server, or see [docs/setup.md](setup.md) if that's you):

**Teachers** (`teachers.csv`):

```csv
staff_no,full_name,employment_type,active_from,biometric_id
T-0001,Ngwa Fon Peter,hourly,2026-09-01,
T-0002,Achu Rebecca Manka,salaried,2026-09-01,1002
```

- `employment_type` is `hourly` or `salaried` (case-insensitive).
- `biometric_id` is optional — leave it blank if the fingerprint hasn't been
  enrolled yet and add it later via Teacher Biometric IDs once it has.

```bash
php artisan teachers:import teachers.csv
```

**Timetables** (`timetables.csv`) — one row per cell of the paper grid:

```csv
staff_no,valid_from,day,period,class_code,room_code
T-0001,2026-09-01,Monday,1,F4A,A101
T-0001,2026-09-01,Monday,2,F4A,A101
T-0001,2026-09-01,Tuesday,3,F5B,B203
```

- `day` is a day name (`Monday`..`Saturday`), `period` is that day's period
  number from the bell schedule.
- Import teachers *before* timetables — a `staff_no` the system doesn't know
  yet will be rejected with a clear message telling you to import teachers
  first.

```bash
php artisan timetables:import timetables.csv --entered-by=<your user id>
```

Both commands check the **whole file** before saving anything — one bad row
means nothing is written, and you get a line-by-line list of exactly what to
fix. Re-running a corrected file is always safe: teacher rows update in
place rather than duplicating, and a timetable import **replaces** that
teacher's whole grid for that `valid_from` to match the file exactly — so if
you drop a row from the file and re-import, that cell is removed too. That
also means a timetable file should always contain the *complete* grid for
that teacher/date, not just the rows that changed.

**Not yet self-service, still needs the command line:**

- The **bell schedule** (period start/end times) has no admin screen yet.
- **Creating logins** for other staff also has no admin screen yet.

Both are documented with copy-pasteable commands in
[docs/setup.md](setup.md) §8–9. If you're doing either of these often, it's
worth asking for a proper screen to be built — but for now, one-time setup
per term is the expected frequency.

---

## 2. Officer

You're the one who lives in this system daily. Your core job is the
**Exception queue** — everything the engine couldn't resolve on its own,
waiting for a human call.

**Exception queue** (main menu): every currently-pending `Unpaired` or
`Location mismatch` period, most recent first. For each one you can see the
teacher, period, class, and *why* it's pending (no scan-in, no scan-out, too
short an interval, or wrong corridor). Click **Override** to resolve it:

1. Pick the actual status — `Present`, `Absent`, or `Absent (justified)`.
2. Write a reason. **This is required, not optional** — it becomes a
   permanent, timestamped entry in the audit log under your name. "Confirmed
   by CCTV," "Teacher confirmed verbally, terminal was down," "Approved sick
   leave" — whatever actually happened, in enough detail that someone
   reading it in three months understands the call.

Once resolved, that record drops off the queue. It stays fully reversible in
principle (another override can supersede it later), but the goal is to
clear the queue daily so nothing goes stale.

**Manage timetable**, under a teacher's record: use this for one-off
corrections — a teacher's schedule changed for one class, someone's covering
for another teacher this week, etc. For a whole-term data load or a full
grid replacement, use the bulk timetable import instead (ask your Admin —
§1 above has the file format).

**Teacher Biometric IDs**: when a teacher's fingerprint needs re-enrolment
on the device (worn print, new device, whatever the reason), you or the
Admin adds a new mapping row here for them rather than editing the old one.

**A day-to-day habit worth building**: check `devices` isn't showing a stale
`last_seen_at` for the corridor's terminal — a device that's been silent for
hours either lost power or lost network, and every scan during that window
is queued on the device itself, not lost, but the sooner it's noticed the
sooner it's fixed.

---

## 3. Principal

Your view into the system today is mainly oversight, through two screens:

- **Exception queue** — see what the Officer is resolving and how (read
  along, or override something yourself if you disagree with a call already
  made — your override simply supersedes theirs and both are on record).
- **Audit log** — every override across the system, who made it, when, and
  the reason given. This is your record for "why does this teacher's month
  look like this."

**Be aware**: automated monthly reports (draft → officer-reviewed →
principal-approved → sent to HR) are planned but **not built yet** — that's
the next phase of this project, expected before the first payroll run needs
it. Until then, a monthly total has to be pulled by whoever manages the
server, directly from the computed period results. If you need a number
before the reporting screens exist, ask them.

---

## 4. HR

Honestly, right now: **there isn't a dedicated HR screen yet.** The
monthly-report workflow described in the original project scope (a report
that moves from draft through officer review and principal approval before
being frozen and sent to you) is built at the database level but the
screens and the generation logic haven't been written — that's explicitly
the next phase of work, timed to land before the first payroll cycle that
needs it.

Until it exists, the numbers you need for hourly-paid staff's hours can be
pulled directly by whoever manages the server — ask them for a specific
teacher or date range. Every period result is stamped with which rule
version computed it and when, so a number pulled today and the same number
re-pulled later will always match unless a human explicitly overrode it (and
if one did, the audit log has the reason).

---

## Appendix: no device yet? Use demo mode

If you're demonstrating the system, training people, or just want to poke
around without a working fingerprint terminal, there's a seeded demo dataset
that runs real synthetic scans through the actual ingestion → pairing →
rule-engine pipeline — it's not fake numbers typed into the database, it's
the real pipeline processing believable data.

```bash
php artisan demo:seed --fresh
```

This wipes and reseeds the database with 8 teachers, a full bell schedule,
and about three weeks of simulated attendance (some clean, some with genuine
exceptions to look at). It also creates one login per role:

| Role | Email | Password |
|---|---|---|
| Admin | `admin@attendancia.test` | `password` |
| Officer | `officer@attendancia.test` | `password` |
| Principal | `principal@attendancia.test` | `password` |
| HR | `hr@attendancia.test` | `password` |

Only ever run `--fresh` against a demo/training environment — it wipes
whatever's currently in the database first and will ask you to confirm
before doing so.
