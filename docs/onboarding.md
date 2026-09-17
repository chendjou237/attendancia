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

**Fingerprints only.** Some terminals (the `DS-K1T8005EFX`) can also read a
proximity card. Attendancia does not accept card scans for attendance — a card
can be lent to a colleague or copied, and these figures feed pay. A card swipe
is recorded but never counts as a taught period, so teachers must scan their
fingerprint. Ask whoever set up the terminal to switch card authentication off
on the device itself (see [docs/setup.md](setup.md) §6), so a card is refused
at the door rather than appearing to work.

## Logging in

Go to `http://<server-address>/admin` and sign in with the account your
admin created for you (see [docs/setup.md](setup.md) §9 if you're the one
setting up the first accounts).

**One thing to know now**: the admin panel's menu only shows the screens
relevant to your role — the sections below describe what each role actually
sees and is responsible for.

**Language**: the panel defaults to French. Click your name in the top
right to switch to English for this session, or ask an Admin to set your
account's language permanently (Users → your record → Language) so it
sticks across logins without needing the switcher again.

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
- **Period Slots** — the bell schedule: each teaching/break period's day,
  sequence, and start/end time. This is the single most important reference
  table in the system — every session boundary and every grace window is
  computed from it — so get the official grid from the school before
  entering it. It's versioned by `valid_from`/`valid_to` like a timetable,
  so a mid-year bell-schedule change never rewrites how a past date was
  resolved; the list defaults to showing only the version active today.
- **Teacher Biometric IDs** — maps a device-side fingerprint ID to a
  teacher. Most teachers only ever need one row. If someone's fingerprint
  wears out and they get re-enrolled on the device with a new ID, add a
  *new* row for them rather than editing the old one — assigning a new ID
  automatically closes out the previous mapping, so historic scans still
  resolve correctly to the same teacher.
- **Users** — create logins for the other three roles (and any additional
  admins): name, email, password, and one of the four roles.
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

**Teacher Attendance** (main menu): search or pick a teacher, set a date or
date range, and see every period in that range with its actual status,
source, and — where one exists — the override that superseded the computed
status (hover the pencil icon for who, when, and why). Defaults to today
with no teacher picked, so it also works as a live "who's teaching right
now" roster across everyone. This screen is read-only; to resolve a
pending period, use the Exception queue above.

**Manage timetable**, under a teacher's record: use this for one-off
corrections — a teacher's schedule changed for one class, someone's covering
for another teacher this week, etc. For a whole-term data load or a full
grid replacement, use the bulk timetable import instead (ask your Admin —
§1 above has the file format).

**Teacher Biometric IDs**: when a teacher's fingerprint needs re-enrolment
on the device (worn print, new device, whatever the reason), you or the
Admin adds a new mapping row here for them rather than editing the old one.

**Device Monitor** — a day-to-day habit worth building: glance at this
screen and check the corridor's terminal shows **Online** (green), not
**Silent** (amber, no signal for a while) or **Offline** (red, an hour or
more, or never connected). A device that's gone quiet either lost power or
lost network — every scan during that window is queued on the device
itself, not lost, but the sooner it's noticed the sooner it's fixed. The
page also shows a live feed of every scan as it lands (device, teacher,
passed/failed), refreshing on its own every few seconds — no need to
reload. If there's no device on site yet, or you want to see the feed move
without walking to the corridor, **Simulate a scan** fires one real scan
through the same pipeline a live terminal uses, for any teacher who
already has a biometric ID enrolled.

**Monthly Reports** — your part of month-end close. Use **Generate report**
(pick the month) once the month is done and the exception queue for it is
clear or close to it. Open the generated report and check the per-teacher
breakdown and the pending-exceptions warning at the top — if there's still a
meaningful number pending, go clear those in the Exception queue first and
use **Regenerate from latest data** rather than proceeding with stale
numbers. Once it looks right, **Mark reviewed** — that's your sign-off before
it goes to the Principal for approval (§3). You can regenerate as many times
as needed up to that point; nothing is final until the Principal approves it.

---

## 3. Principal

You're the approval gate between the Officer's day-to-day work and HR's
payroll numbers. Your recurring screens:

- **Exception queue** — see what the Officer is resolving and how (read
  along, or override something yourself if you disagree with a call already
  made — your override simply supersedes theirs and both are on record).
- **Teacher Attendance** — the same search-a-teacher, filter-by-date view
  the Officer uses, useful when a specific teacher's month is being
  questioned and you want to see the day-by-day picture yourself rather
  than only the monthly total.
- **Audit log** — every override across the system, who made it, when, and
  the reason given. This is your record for "why does this teacher's month
  look like this."

**Monthly Reports** is where your actual approval happens. Each month
(once the Officer has generated and reviewed it — see §2 and §4 below) shows
up here in **Officer reviewed** state with a **Principal approve** button.
Open the report first — it shows the full per-teacher breakdown (present,
absent, hours) and, prominently, whether any period that month is still
`Unpaired` or `Location mismatch` and therefore not yet counted either way.
**Check that warning before approving** — approving with pending exceptions
still open means those teachers' numbers are undercounted, not wrong, but
undercounted, and this is your last checkpoint before it's frozen.

Approving is deliberately one-way: once you approve, the report can no
longer be regenerated from newer data, even if something changes later.
That's intentional — it's what makes "the September report" mean the same
thing in six months that it means today. A correction after approval needs
a manual, audited adjustment, not a re-run.

---

## 4. HR

**Monthly Reports** is your screen. A report reaches you already in **Sent
to HR** state — by the time you see it, an Officer has generated and
reviewed it and a Principal has approved it, and it's frozen: the numbers
you're looking at will never silently change under you.

Open a report to see:

- **Payable hours** — the total for hourly-paid staff, which is what
  actually drives their pay this month.
- **Oversight hours** — the same measurement for salaried staff, tracked for
  visibility, not pay.
- A per-teacher table underneath: present, present (administrative), absent,
  absent (justified), and hours, per person — matching the paper timetable
  shape rather than a flat export.
- A basis note at the bottom of the page spelling out exactly how hours were
  computed (currently 1 period = 1 hour) — printed on the report itself so
  nobody has to guess or ask later.

If a report you need isn't in **Sent to HR** state yet, it's still moving
through the Officer/Principal steps above — ask them rather than the
database; there's deliberately no way to pull an unfinished month's numbers
from this screen, since an in-progress report can still change.

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
and about two calendar months of simulated attendance (some clean, some with
genuine exceptions to look at) — the most recently completed month is walked
all the way through to a finished, **Sent to HR** monthly report as a
worked example, and the current, still-in-progress month is left as a fresh
**Draft** so you can demo the Officer → Principal approval workflow live
(§2–§4 above). It also creates one login per role:

| Role | Email | Password |
|---|---|---|
| Admin | `admin@attendancia.test` | `password` |
| Officer | `officer@attendancia.test` | `password` |
| Principal | `principal@attendancia.test` | `password` |
| HR | `hr@attendancia.test` | `password` |

Only ever run `--fresh` against a demo/training environment — it wipes
whatever's currently in the database first and will ask you to confirm
before doing so.
