# Setup guide — school server and Hikvision device

This is the runbook for putting Attendancia on the physical PC at the school and
wiring up the fingerprint terminal. It assumes one on-site machine acting as the
server (no cloud hosting), and one Hikvision ACS/access-control terminal per
corridor.

Read the **power model** section before you do anything else — it explains why
the boot order matters and why there's no UPS in this design.

---

## 1. The power model (read this first)

The device has its own backup power. The server does not. In practice:

1. Mains fails → the server goes down, the terminal keeps recording scans to
   its own internal storage.
2. Mains returns → the server boots. **Before anything else, it must catch up
   on whatever the device buffered while it was off** (the "backfill"), and
   only then resume watching the live stream.
3. Nothing here is manual — it's the boot order below, done once at setup
   time, that makes every future outage self-healing.

Boot order that must hold every time the server restarts:

```
MySQL up  →  backfill runs  →  live stream starts
```

`hikvision:stream` already does this itself (it runs a backfill before it
opens the live connection, unless you pass `--skip-backfill`), so as long as
Supervisor is configured to start it on boot (§7 below), the ordering is
correct without anything extra. The one thing you must get right is that MySQL
is actually up and accepting connections before Supervisor starts the stream
worker — see §7.

Because there's no UPS, a power cut can kill the server mid-write. MySQL's
InnoDB storage engine is crash-safe, so the database itself won't corrupt; at
worst a few final transactions are lost, and those get re-fetched automatically
by the next backfill. You don't need to do anything special for this beyond
getting the boot order right once.

**Find out the device's actual event storage capacity during device
configuration (§6).** It sets the longest outage the server can recover from
before the device starts overwriting its own buffer and those scans are gone
for good. If the school has frequent multi-day outages, that capacity — not
this guide — is the real constraint.

---

## 2. What you're installing

| Component | Role |
|---|---|
| PHP 8.3+ with the usual Laravel extensions, plus `pcntl` | Runs the app and the stream worker |
| MySQL 8 (or MariaDB 10.6+) | The database |
| Nginx + PHP-FPM (or Caddy) | Serves the admin panel over HTTP on the school LAN |
| Supervisor | Keeps `hikvision:stream` running and restarts it if it dies |
| cron | Fires Laravel's scheduler once a minute, which runs the nightly backfill |

There is **no Redis and no queue worker to run** — sessions, cache, and queue
all use plain database drivers (see `.env.example`), which is one less service
to keep alive on a machine with no UPS.

---

## 3. Prerequisites

- A PC that stays on and network-reachable during school hours (ideally
  always-on), with the Hikvision terminal(s) on the same LAN.
- OS: any Linux with PHP 8.3+ available is easiest to keep patched. If the
  school's existing machine is Windows, this guide's package-manager commands
  won't apply directly — the same components (PHP 8.3+, MySQL, a web server,
  a process supervisor, a scheduled task) are still needed, just via
  Windows-native tooling.
- Root/sudo access to install packages and configure services.

Verify PHP and its extensions before doing anything else — `pcntl` in
particular is easy to miss and the stream worker will not run without it:

```bash
php -v
php -m | grep -E "pcntl|pdo_mysql|mbstring|bcmath|intl"
```

If `php -v` reports anything under 8.3 (common — many distros' default `php`
is older), find or install a PHP 8.3+ binary and use its full path for every
command below and in the Supervisor/cron config, exactly as noted inline in
`deploy/supervisor/hikvision-stream.conf`.

---

## 4. Get the code onto the server

```bash
git clone <your repository URL> /var/www/attendancia
cd /var/www/attendancia
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

---

## 5. Configure `.env`

Open `.env` and set at minimum:

```bash
APP_NAME=Attendancia
APP_ENV=production
APP_DEBUG=false
APP_URL=http://<server-LAN-IP-or-hostname>

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=attendancia
DB_USERNAME=attendancia
DB_PASSWORD=<a real password — not blank>

ATTENDANCE_TIMEZONE=Africa/Douala
ATTENDANCE_ENFORCE_LOCATION=false

HIKVISION_USER=<the ISAPI account you create on the device in §6>
HIKVISION_PASS=<its password>
HIKVISION_IDLE_TIMEOUT=90
HIKVISION_BACKFILL_HOURS=48
HIKVISION_CLOCK_DRIFT_THRESHOLD=240
```

Notes on the less obvious ones:

- **`ATTENDANCE_ENFORCE_LOCATION`** — stays `false` for a single-device pilot.
  With only one corridor wired, most scans will legitimately come from
  teachers whose real classroom has no terminal yet; that's incomplete
  coverage, not fraud. The pairing engine still logs what it *would* have
  flagged, so you can review that log before switching this on once more
  corridors are wired.
- **`HIKVISION_BACKFILL_HOURS`** — how far back a backfill run looks by
  default. 48h comfortably covers an overnight outage or a restart. If the
  school has longer outages, raise this — but check it against the device's
  actual storage capacity first (§1, §6); there's no point requesting more
  history than the device still has.
- **Multiple devices, different credentials** — if a later corridor's
  terminal doesn't share the same ISAPI account as this one, don't overwrite
  `HIKVISION_USER`/`HIKVISION_PASS`. Add a per-device override in
  `config/attendance.php`'s `credentials` array instead, keyed by that
  device's serial, pointing at its own env vars. The default pair remains the
  fallback for every device not listed there.
- **Never commit `.env`** — it's already gitignored. Device IPs and corridor
  assignments live in the database (entered via the admin panel, §8), not
  here — only the ISAPI username/password are secrets.

---

## 6. Configure the Hikvision device

Do this from a browser on the same LAN, using the device's own web UI
(usually `http://<device-ip>`, default Hikvision admin credentials on first
boot — change them immediately if you haven't already).

1. **Network**: give the device a static IP (or a DHCP reservation) on the
   school LAN. Write this IP down — you'll enter it into the admin panel in
   §8.
2. **Time**: sync the device's clock to the same source the server uses (NTP,
   ideally). If the corridor has no internet access, the server itself can
   serve LAN NTP — but at minimum, manually align the device clock at setup
   and re-check it periodically. A rule with a 10-minute grace window makes a
   4-minute drift a real pay dispute, which is what
   `HIKVISION_CLOCK_DRIFT_THRESHOLD` is watching for.
3. **Create a dedicated ISAPI user** for this app rather than reusing the
   device admin account — least privilege, and it survives an admin password
   rotation. This is the username/password that go into `.env` in §5.
4. **Enable event notification / the alert stream** (`ISAPI` →
   `AccessControlEvent` / `alertStream` depending on firmware) — this is what
   `hikvision:stream` connects to for live scans.
5. **Check the event storage capacity** (how many events the device retains
   before overwriting the oldest). Firmware exposes this differently across
   models — check the device's storage/event log settings page. Write this
   number down; it caps how long an outage can last before scans are
   permanently lost (§1).
6. **Note the device's serial number** — you'll need it for the admin panel
   (§8) and for the Supervisor command line (§9).

**Before trusting any of this against the real pipeline**, run the
reconnaissance command from the server once the app is installed:

```bash
php artisan hikvision:dump <device-serial> --seconds=120
```

This connects to the device, prints its device-info response, and captures
120 seconds of live events verbatim — so you can confirm this specific
firmware's field names (`serialNo`, `employeeNoString`, `dateTime`, etc.)
match what the ingestion pipeline expects, before `hikvision:stream` or
`hikvision:backfill` are trusted to run against it unattended. If the field
names don't match, stop and fix the normalizer rather than guessing.

---

## 7. MySQL and migrations

```bash
sudo mysql -e "CREATE DATABASE attendancia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'attendancia'@'localhost' IDENTIFIED BY '<the password from your .env>';"
sudo mysql -e "GRANT ALL PRIVILEGES ON attendancia.* TO 'attendancia'@'localhost';"

cd /var/www/attendancia
php artisan migrate --force
php artisan db:seed --class=RoleSeeder
```

Configure MySQL to start on boot (`systemctl enable mysql`) — this is the
first link in the boot-order chain from §1, and it must come up before
Supervisor's `hikvision-stream` program does.

---

## 8. Enter the school's reference data

Everything below is one-time setup, done once the app is reachable in a
browser (§10). Log in as the admin account created in the next section, then
work through:

1. **Corridors** — one row per physical corridor with a terminal (or that
   will eventually get one).
2. **Rooms**, each assigned to a corridor.
3. **Class codes** — the school's canonical class codes (e.g. `F4A`).
4. **Devices** — one row per terminal: its serial number (§6), IP, and the
   corridor it's mounted in. This is what lets the ingestion pipeline resolve
   an incoming scan's device serial to a corridor for location checks.
5. **Calendar** — public holidays and known school closures for the term, at
   minimum. Half-days and class-scoped suspensions (e.g. one form sitting a
   sequence exam) can be added as they come up.
6. **Rule version** — the grace windows, pairing windows, debounce, and
   minimum-session values (§5 of the project scope has the current defaults:
   10 min late grace, 15 min early grace, 15 min pairing windows either side,
   30s debounce, 10 min minimum session, 1.00 hours per period). Every value
   here is versioned by `valid_from`, so a later correction never rewrites
   an already-computed month.
7. **Teachers and timetables** — see §11 below; for anything beyond a
   handful of teachers, use the bulk-import commands rather than the admin
   forms.

**One gap to know about now**: the bell schedule (`period_slots` — each
teaching/break period's day, sequence, and start/end time) has **no admin
screen yet**. It must be entered directly, either by writing a short one-off
seeder (see `database/seeders/DemoSeeder.php`'s `seedPeriodSlots()` method for
the shape to copy) or via `php artisan tinker`:

```bash
php artisan tinker
>>> \App\Models\PeriodSlot::create([
...     'day_of_week' => 1, // 0 = Sunday .. 6 = Saturday
...     'seq' => 1,
...     'start_time' => '07:30:00',
...     'end_time' => '08:25:00',
...     'is_break' => false,
...     'valid_from' => '2026-09-01',
... ]);
```

Repeat for every period and break, every teaching day, per the school's
official timetable. This is the single most important reference table in the
system — every session boundary and every grace window is computed from it —
so get the official grid from the school before doing this, rather than
guessing.

---

## 9. Create user accounts

Logins are created from the admin panel's **Users** screen (name, email,
password, and one of the four roles — `admin`, `officer`, `principal`,
`hr`) — but that screen only exists once a first `admin` account exists to
sign in as, so bootstrap that one account from the command line:

```bash
php artisan tinker
>>> $user = \App\Models\User::create([
...     'name' => 'Full Name',
...     'email' => 'person@school.example',
...     'password' => bcrypt('a-real-password'),
... ]);
>>> $user->assignRole('admin');
```

From there, sign in as that admin and create every other login (officer,
principal, hr, and any additional admins) from **Users** in the panel — no
further `tinker` needed.

**Role boundaries are enforced**: each role only sees the Filament resources
relevant to it (Officer: Exception Queue, Manage Timetable, Teacher
Biometric IDs; Principal: Exception Queue, Audit Log; HR: nothing dedicated
yet; Admin: everything, including Users). See the [onboarding
guide](onboarding.md) for what each role is actually responsible for.

---

## 10. Web server

Point Nginx (or your web server of choice) at `public/index.php` the standard
Laravel way, then enable it to start on boot:

```nginx
server {
    listen 80;
    server_name <server-LAN-IP-or-hostname>;
    root /var/www/attendancia/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo systemctl enable nginx php8.3-fpm
sudo systemctl restart nginx php8.3-fpm
```

Confirm `storage/` and `bootstrap/cache/` are writable by the web server
user:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
```

---

## 11. Bulk-importing teachers and timetables

Manually entering 60–100 teachers and their grids one at a time through the
admin forms is days of work. Two CSV import commands exist specifically to
avoid that — use them for the initial data load, and again whenever the
school hands over a corrected or new-term grid.

**Teachers** — `staff_no,full_name,employment_type,active_from,biometric_id`
(`biometric_id` is optional; leave it blank and enrol it later via the
Teacher Biometric IDs screen once the teacher has actually scanned once):

```bash
php artisan teachers:import /path/to/teachers.csv
```

**Timetables** — `staff_no,valid_from,day,period,class_code,room_code` (one
row per cell of the paper grid; `day` is a name like `Monday`, `period` is
that day's period number):

```bash
php artisan timetables:import /path/to/timetables.csv --entered-by=<admin user id>
```

Both commands validate the **entire file** before writing anything — if row
47 has a typo, nothing from the file is saved, and you get a line-by-line
list of what to fix. Re-running the same file is always safe: teacher imports
update existing staff_no rows rather than duplicating them, and timetable
imports **replace** a teacher's whole grid for that `valid_from` to exactly
match the file — so a corrected file fully supersedes the previous import,
and dropping a row from the file removes that cell.

Import teachers before timetables — the timetable importer looks teachers up
by `staff_no` and will reject rows for anyone not yet in the system.

---

## 12. Supervisor — keep the live stream running

Copy the provided config and point it at your actual PHP 8.3+ binary and app
path:

```bash
sudo cp deploy/supervisor/hikvision-stream.conf /etc/supervisor/conf.d/
sudo $EDITOR /etc/supervisor/conf.d/hikvision-stream.conf
# update the `command=` line's php path and the device serial argument,
# and the `directory=` line, to match this server
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status hikvision-stream
```

The config already has `startretries=999` and `autorestart=true` — an
offline device is retried forever rather than giving up, which is the
correct behaviour for a terminal that might legitimately be powered off or
network-isolated for hours. `startsecs=10` is deliberate and documented
inline in the conf file — don't lower it; a too-low value makes Supervisor
treat every reconnect attempt against an unreachable device as an immediate
crash and it stops retrying entirely.

If there's more than one device/corridor eventually, add one
`[program:hikvision-stream-<corridor>]` block per device, each with its own
device serial argument and log file.

---

## 13. cron — the nightly backfill

The nightly backfill (`routes/console.php`, `02:00` local time, one run per
active device) is driven by Laravel's own scheduler, which needs exactly one
crontab entry:

```bash
sudo crontab -e -u www-data
```

```
* * * * * cd /var/www/attendancia && php8.3 artisan schedule:run >> /dev/null 2>&1
```

(Use the same PHP 8.3+ binary path as everywhere else in this guide.)

---

## 14. Backups

There's no UPS, so treat abrupt shutdowns as routine, not exceptional.
InnoDB survives them, but that's not a substitute for a real backup — add a
nightly `mysqldump` to cron, writing to a separate disk or off-machine
location if at all possible:

```
30 1 * * * mysqldump -u attendancia -p'<password>' attendancia | gzip > /var/backups/attendancia-$(date +\%F).sql.gz
```

Run this **before** the 02:00 nightly backfill so a restore never needs to
re-run a backfill that already happened against the dump.

---

## 15. Verify everything end to end

Work through this checklist once setup is complete:

1. `php artisan migrate:status` — all migrations ran.
2. Log into the admin panel at `http://<server-address>/admin` with the
   account from §9.
3. `sudo supervisorctl status hikvision-stream` — `RUNNING`, not
   `FATAL`/`BACKOFF`.
4. Walk to the device and do one real scan-in and scan-out for a real,
   entered teacher during one of their scheduled periods.
5. Check the `devices` row for that device in the admin panel —
   `last_seen_at` should update to just now.
6. Run the engine for today:
   ```bash
   php artisan attendance:compute
   ```
7. Open the **Exception queue** page in the admin panel — a correctly paired
   scan inside the grace window won't appear there at all (it's `Present`);
   an intentionally malformed test (e.g. only a scan-in, no scan-out) should
   show up as `Unpaired` and be resolvable there.
8. Kill power to the server, wait a minute, restore it. Confirm it boots
   unattended, `hikvision-stream` comes back up on its own
   (`supervisorctl status`), and re-running `hikvision:dump` or checking
   `raw_events` shows no gap and no duplicated events for the outage window.

If you don't have a device available yet to test any of this against, see
`docs/onboarding.md`'s note on `demo:seed` — it exercises the full ingestion
→ pairing → rule engine pipeline with synthetic data, which is useful for
sanity-checking the *app* independently of whether the device is reachable.

---

## 16. Troubleshooting

- **`hikvision:stream` exits immediately, Supervisor gives up (`FATAL`)** —
  check `startsecs` wasn't lowered (§12), and check the device IP/credentials
  in `.env` are correct. Run `hikvision:dump` manually to see the actual
  connection error.
- **Digest auth failures** — confirm the ISAPI user created in §6 has the
  right permission level on the device (some firmware requires "Media User"
  or "Operator" rather than "Viewer" for event subscription endpoints).
- **Clock drift alerts** — re-sync the device's clock (§6). If the corridor
  has no internet for NTP, sync it against the server's clock manually and
  recheck periodically; drift accumulates on cheap embedded clocks faster
  than you'd expect.
- **Duplicate-key errors on `raw_events`** — this is dedup working as
  intended (`UNIQUE(device_serial, device_event_serial)`); a replayed backfill
  overlapping the live stream is expected to hit this and is silently
  ignored, not a bug.
- **A teacher's scans aren't resolving to them** — check
  **Teacher Biometric IDs** in the admin panel; a worn fingerprint that was
  re-enrolled on the device needs a new mapping row here (closing out the old
  one), otherwise the old id keeps pointing at them and the new one resolves
  to nobody.
