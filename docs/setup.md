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
the service supervisor is configured to start it on boot (§12 below), the
ordering is correct without anything extra. The one part that used to require
getting something right by hand — MySQL actually being up and accepting
connections before the stream worker starts — is now automatic: the
`--wait-for-db` flag makes the worker poll the database itself before doing
anything else, so there's nothing left to configure for this beyond following
§7 and §12 as written.

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

The same five components either way; only the packaging differs.

| Component | Role | On Linux | On Windows Server |
|---|---|---|---|
| PHP 8.3+ | Runs the app and the stream worker | distro package | the **Non-Thread-Safe** x64 build from windows.php.net |
| MySQL 8 (or MariaDB 10.6+) | The database | distro package | MySQL Installer for Windows |
| A web server | Serves the admin panel over HTTP on the school LAN | Nginx + PHP-FPM (or Caddy) | IIS + FastCGI (or Apache) |
| A process supervisor | Keeps `hikvision:stream` running and restarts it if it dies | Supervisor | NSSM |
| A scheduler | Fires Laravel's scheduler, which runs the nightly backfill | cron, once a minute | a second NSSM service running `schedule:work` |

There is **no Redis and no queue worker to run** — sessions, cache, and queue
all use plain database drivers (see `.env.example`), which is one less service
to keep alive on a machine with no UPS.

**On `pcntl`**: on Linux the stream worker uses it to catch `SIGTERM` and shut
down cleanly. It does not exist on Windows — not "usually missing", it cannot
be built there — so on Windows the worker uses
`sapi_windows_set_ctrl_handler()` instead, which is what NSSM's Ctrl+C stop
delivers. Both paths are in `App\Services\Console\GracefulShutdown`, chosen at
runtime. You do not need to install or configure anything for this; it is
called out only because older notes listed `pcntl` as a hard requirement.

---

## 3. Prerequisites

- A PC that stays on and network-reachable during school hours (ideally
  always-on), with the Hikvision terminal(s) on the same LAN.
- Administrator / root access to install packages and configure services.

Whichever OS, verify PHP **before doing anything else**. If `php -v` reports
anything under 8.3, find or install a PHP 8.3+ binary and use its full path
for every command below and in the service configuration — a wrong `php` on
PATH is the single most common way this install goes sideways.

### Linux

```bash
php -v
php -m | grep -E "pcntl|pdo_mysql|mbstring|bcmath|intl|curl"
```

Many distros' default `php` is older than 8.3; install a 8.3+ package and use
its full path, exactly as noted inline in
`deploy/supervisor/hikvision-stream.conf`.

### Windows Server

1. Download the **Non-Thread-Safe (NTS) x64** build of PHP 8.3+ from
   [windows.php.net](https://windows.php.net/download/) and unzip it to
   e.g. `C:\php`. NTS is the build IIS FastCGI and the CLI both want; the
   Thread-Safe build is only for the long-obsolete ISAPI module.
2. Install the **Visual C++ Redistributable** the download page names for
   that build (VS16 or VS17 x64). PHP will not start without it, and the
   error it gives is unhelpfully generic.
3. Copy `php.ini-production` to `php.ini` and enable these extensions by
   removing the leading `;`:

   ```ini
   extension_dir = "ext"

   extension=bcmath
   extension=curl
   extension=fileinfo
   extension=gd
   extension=intl
   extension=mbstring
   extension=openssl
   extension=pdo_mysql
   extension=sodium
   extension=zip
   ```

   `curl` and `openssl` are not optional here — the ISAPI client talks to the
   device with digest auth over Guzzle's curl handler.
4. Raise the memory limit. The default 128M is not enough for the monthly
   report PDFs (dompdf):

   ```ini
   memory_limit = 512M
   ```
5. Add `C:\php` to the system PATH, then confirm from a **new** shell:

   ```powershell
   php -v
   php -m
   ```

   `pcntl` will not be listed. That is expected and fine — see §2.
6. **Exclude the application directory and `C:\php` from Windows Defender
   real-time scanning.** PHP opens thousands of small files per request and
   Defender inspects every one; this single setting is the difference between
   a panel that feels instant and one that takes seconds per page. It is
   invisible if you don't know to look for it.

   ```powershell
   Add-MpPreference -ExclusionPath 'C:\inetpub\attendancia', 'C:\php'
   ```

---

## 4. Get the code onto the server

### Linux

```bash
git clone <your repository URL> /var/www/attendancia
cd /var/www/attendancia
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

### Windows Server

```powershell
git clone <your repository URL> C:\inetpub\attendancia
cd C:\inetpub\attendancia
composer install --no-dev --optimize-autoloader
copy .env.example .env
php artisan key:generate
```

Every path in the rest of this guide assumes `C:\inetpub\attendancia`;
substitute your own throughout, including in `deploy/windows/*.ps1`.

**Node is not needed on the server.** The `/admin` panel runs entirely on
Filament's own published assets (`public/css/filament`, `public/js/filament`),
which `composer install` puts in place. `npm run build` is only needed for the
Laravel welcome page at `/`, which nothing in this deployment uses — if you
skip it, `/` errors and `/admin` is fine.

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

Create the database and its user:

```sql
CREATE DATABASE attendancia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'attendancia'@'localhost' IDENTIFIED BY '<the password from your .env>';
GRANT ALL PRIVILEGES ON attendancia.* TO 'attendancia'@'localhost';
```

On Linux run those with `sudo mysql -e "..."`; on Windows paste them into MySQL
Workbench or `mysql -u root -p`.

Then, from the application directory on either platform:

```
php artisan migrate --force
php artisan db:seed --class=RoleSeeder
```

### Pin the durability settings

These pin MySQL's crash-safety settings explicitly rather than relying on them
silently being the defaults (see `deploy/mysql/attendancia.cnf` for why — the
short version: no UPS means a committed write must survive a power cut, and a
generic "performance tuning" guide run against this server later would
otherwise be the most likely thing to break that).

**Linux:**

```bash
sudo cp deploy/mysql/attendancia.cnf /etc/mysql/mysql.conf.d/attendancia.cnf
sudo systemctl restart mysql
sudo systemctl enable mysql
```

(If your distro's MySQL/MariaDB package uses a different drop-in directory
than `/etc/mysql/mysql.conf.d/`, put it wherever that install's own `my.cnf`
`!includedir` line points instead.)

**Windows:** there is no drop-in directory. Paste the contents of
`deploy/windows/my.ini.snippet` into the `[mysqld]` section of MySQL's
`my.ini` — usually `C:\ProgramData\MySQL\MySQL Server 8.0\my.ini`, a
hidden directory — then restart the service:

```powershell
Restart-Service MySQL80
Set-Service MySQL80 -StartupType Automatic
```

Verify on either platform that it took:

```
mysql -u attendancia -p -e "SHOW VARIABLES LIKE 'innodb_flush_log_at_trx_commit';"
# expect the value column to read 1
```

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
6. **Bell schedule** — the **Period Slots** screen: each teaching/break
   period's day, sequence, and start/end time. This is the single most
   important reference table in the system — every session boundary and every
   grace window is computed from it — so get the official grid from the
   school before entering it, rather than guessing. Repeat for every period
   and break, every teaching day. Like the rule version below, it's versioned
   by `valid_from`/`valid_to`, so a mid-year bell-schedule change never
   rewrites how a past date was resolved; the list defaults to showing only
   the version active today (remove the "Current version only" filter to see
   past or future versions).
7. **Rule version** — the grace windows, pairing windows, debounce, and
   minimum-session values (§5 of the project scope has the current defaults:
   10 min late grace, 15 min early grace, 15 min pairing windows either side,
   30s debounce, 10 min minimum session, 1.00 hours per period). Every value
   here is versioned by `valid_from`, so a later correction never rewrites
   an already-computed month.
8. **Teachers and timetables** — see §11 below; for anything beyond a
   handful of teachers, use the bulk-import commands rather than the admin
   forms.

---

## 9. Create user accounts

Logins are created from the admin panel's **Users** screen (name, email,
password, one of the four roles — `admin`, `officer`, `principal`, `hr` —
and optionally a language: French or English, defaulting to French since
the app is mostly used by francophone staff; leave it blank to have that
user follow the site default and their own in-panel language switcher
instead of a fixed per-account setting) — but that screen only exists once
a first `admin` account exists to sign in as, so bootstrap that one account
from the command line:

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
Biometric IDs, Monthly Reports (generate/review); Principal: Exception
Queue, Audit Log, Monthly Reports (approve); HR: Monthly Reports
(read-only — no generate button); Admin: everything, including Users and
Period Slots). See the [onboarding guide](onboarding.md) for what each role
is actually responsible for.

---

## 10. Web server

Point the web server at the **`public/` directory**, never at the application
root — `.env` lives one level up and is served as plain text by any server
rooted a directory too high.

### Linux — Nginx + PHP-FPM

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

### Windows Server — IIS + FastCGI

1. Install the IIS role, then two things IIS does not ship with:
   - **CGI** (Server Manager → Add Roles → Web Server → Application
     Development → CGI), which is what lets IIS run PHP at all.
   - The **URL Rewrite** module, from iis.net. Without it IIS ignores
     `public/web.config`'s rewrite rules and every URL except `/` is a 404 —
     which looks exactly like a broken app rather than a missing module.
2. Add a FastCGI application pointing at `C:\php\php-cgi.exe`
   (IIS Manager → server node → FastCGI Settings → Add). Set:
   - `InstanceMaxRequests` = 10000
   - `PHP_FCGI_MAX_REQUESTS` = 10000 under Environment Variables
   - **Activity Timeout** = 300. The Calendar screens run
     `attendance:compute` synchronously on save, so a term's worth of
     calendar entries can legitimately hold a request open past the 30s
     default.
3. Create the site with its physical path set to
   `C:\inetpub\attendancia\public`, and add a handler mapping for `*.php`
   to that FastCGI module.
4. `public/web.config` is already in the repo — it is the IIS translation of
   `public/.htaccess` (front-controller rewrite, trailing-slash redirect,
   `Authorization` header pass-through, directory browsing off). Nothing to
   write by hand.
5. Grant write access where Laravel needs it. This is the `chown` step's
   equivalent, and it needs doing for **two** identities: the IIS
   application pool, and whatever account the NSSM services run as
   (`LocalSystem` by default):

   ```powershell
   $app = 'C:\inetpub\attendancia'
   foreach ($dir in @("$app\storage", "$app\bootstrap\cache")) {
       icacls $dir /grant "IIS AppPool\Attendancia:(OI)(CI)M" /T
       icacls $dir /grant "SYSTEM:(OI)(CI)M" /T
   }
   ```
6. Open the LAN port. Windows Firewall blocks inbound 80 by default, so the
   panel is reachable from the server itself and nowhere else until this is
   done:

   ```powershell
   New-NetFirewallRule -DisplayName 'Attendancia HTTP' -Direction Inbound `
       -Protocol TCP -LocalPort 80 -Action Allow -Profile Domain,Private
   ```

**Apache instead?** If whoever maintains the machine already knows Apache,
that works too and needs no extra file: `public/.htaccess` is used as-is.
Install Apache as a Windows service with `mod_rewrite` and `mod_fcgid`
enabled, set `DocumentRoot` to `...\attendancia\public`, and add
`AllowOverride All` for that directory — without it `.htaccess` is silently
ignored and you get the same "everything 404s" symptom as a missing URL
Rewrite module.

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

## 12. Keep the live stream running

`hikvision:stream` is a long-running worker that must come back automatically
after a crash, a device outage, or a power cut. On Linux that is Supervisor;
on Windows it is NSSM. The behaviour both are configured for is the same, and
two settings in it are load-bearing:

- **Retry forever.** An offline device is retried indefinitely rather than
  given up on — correct for a terminal that might legitimately be powered off
  or network-isolated for hours.
- **A start-success window longer than 10 seconds.** The worker's own
  `connectTimeout` is 10s, so an unreachable device makes it exit *faster*
  than a default "did it start successfully?" window. Too low a value and the
  supervisor reads every reconnect attempt as an immediate crash and stops
  retrying entirely — the opposite of what you want.

The worker also runs `--wait-for-db`, which polls the database before it does
anything else. That is what makes §1's boot order (MySQL up → backfill → live
stream) hold automatically, without you having to reason about whether the
database service and the supervisor happen to race each other on a given boot.
If MySQL is still doing InnoDB crash recovery from the last power cut, the log
shows a heartbeat line every ~15s until it's ready, instead of the worker
crash-looping against a database that isn't accepting connections yet.

### Linux — Supervisor

```bash
sudo cp deploy/supervisor/hikvision-stream.conf /etc/supervisor/conf.d/
sudo $EDITOR /etc/supervisor/conf.d/hikvision-stream.conf
# update the `command=` line's php path, app path and device serial, and
# the `directory=` line, to match this server
sudo systemctl enable supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status hikvision-stream
```

The config already has `startretries=999`, `autorestart=true` and
`startsecs=10`. Don't lower `startsecs` — see the second bullet above; the
reasoning is also documented inline in the conf file.

### Windows Server — NSSM

1. Download NSSM from [nssm.cc](https://nssm.cc/download), and put
   `win64\nssm.exe` somewhere on PATH.
2. Edit the four variables at the top of
   `deploy\windows\install-services.ps1` — the PHP path, the app root, the
   device serial from §6, and the log directory.
3. Run it from an **elevated** PowerShell prompt:

   ```powershell
   cd C:\inetpub\attendancia\deploy\windows
   .\install-services.ps1
   ```

That installs **two** services, both set to start at boot:
`AttendanciaStream` (this section) and `AttendanciaScheduler` (§13). The
script is commented with which NSSM setting corresponds to which Supervisor
one; the ones that matter are `AppExit Default Restart` with
`AppThrottle 15000` (retry forever, back off on fast failures) and
`AppStopMethodConsole 15000` (stop by sending Ctrl+C, which the worker catches
and exits cleanly on — the equivalent of Supervisor's `stopsignal=TERM`).

```powershell
Get-Service AttendanciaStream, AttendanciaScheduler
```

**If there's more than one device/corridor eventually**, add one service per
device, each with its own serial and log file — on Linux, one
`[program:hikvision-stream-<corridor>]` block per device; on Windows, run the
install script again with a different `$Serial` and `$StreamSvc`.

---

## 13. The nightly backfill and recompute

At `02:00` local time, `routes/console.php` runs `NightlyRecovery`: a
backfill for every active device, then `attendance:compute` for today and
yesterday. Backfill alone only recovers `raw_events` — this second half is
what turns a routine overnight outage into finished `period_results`
without anyone having to notice the gap and re-run the engine by hand. A
longer outage (more than a day) needs a human to run `attendance:compute
--date=YYYY-MM-DD` for each earlier affected day once it's noticed, the
same way it already needs `hikvision:backfill --hours=N` for a window
longer than the default 48h.

This is driven by Laravel's own scheduler, which has to be running.

**Linux** — one crontab entry:

```bash
sudo crontab -e -u www-data
```

```
* * * * * cd /var/www/attendancia && php8.3 artisan schedule:run >> /dev/null 2>&1
```

(Use the same PHP 8.3+ binary path as everywhere else in this guide.)

**Windows** — nothing to do; `install-services.ps1` already installed the
`AttendanciaScheduler` service, which runs `artisan schedule:work`. That is
the long-running form of the same thing, and as a service it's both simpler
and cheaper than a Task Scheduler entry that spawns PHP 1,440 times a day.
Confirm it's running and that it can see the schedule:

```powershell
Get-Service AttendanciaScheduler
php artisan schedule:list
```

---

## 14. Backups

There's no UPS, so treat abrupt shutdowns as routine, not exceptional.
InnoDB survives them, but that's not a substitute for a real backup. Run the
dump **before** the 02:00 nightly backfill so a restore never needs to re-run
a backfill that already happened against the dump, and write it to a separate
disk or off-machine location if at all possible.

**Linux** — a crontab entry:

```
30 1 * * * mysqldump -u attendancia -p'<password>' attendancia | gzip > /var/backups/attendancia-$(date +\%F).sql.gz
```

**Windows** — `deploy\windows\backup.bat`, scheduled at 01:30:

```powershell
schtasks /create /tn "Attendancia backup" /sc daily /st 01:30 /ru SYSTEM `
         /tr "C:\inetpub\attendancia\deploy\windows\backup.bat"
```

Edit the paths at the top of the script first. It reads the password from a
MySQL option file rather than taking it on the command line — put one at the
path `CREDENTIALS` names, readable only by the backup account:

```ini
[client]
user=attendancia
password=<the password from your .env>
```

---

## 15. Verify everything end to end

Work through this checklist once setup is complete:

1. `php artisan migrate:status` — all migrations ran.
2. Log into the admin panel at `http://<server-address>/admin` with the
   account from §9.
3. The stream worker is up:
   - Linux: `sudo supervisorctl status hikvision-stream` — `RUNNING`, not
     `FATAL`/`BACKOFF`.
   - Windows: `Get-Service AttendanciaStream` — `Running`, not `Stopped`.
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
   unattended, the stream worker comes back up on its own, and re-running
   `hikvision:dump` or checking
   `raw_events` shows no gap and no duplicated events for the outage window.
   Recovering `raw_events` isn't the whole story, though — a scan for a
   period that's already passed only becomes a `Present`/`Absent` result
   once `attendance:compute` runs for that date. §13's nightly job does
   that automatically for today/yesterday; to confirm the gap is actually
   closed, don't just check `raw_events` — check that a `period_results`
   row exists for the affected teacher and date too.
   While it's coming back up, follow the worker's log — `tail -f
   /var/log/hikvision-stream.log` on Linux, `Get-Content -Wait
   storage\logs\hikvision-stream.log` on Windows. You should see
   "Still waiting for the database..." heartbeat lines (§12) while MySQL is
   still recovering, then a single "The database is up." line, then the
   worker's own startup output. Repeated crash/restart cycles instead of
   clean heartbeats means the boot-order fix isn't actually wired up —
   check §16.

9. **Windows only** — confirm the stop path is clean, because this is the
   one behaviour that differs between the two platforms and the one that
   silently degrades if PHP was installed without a console:

   ```powershell
   nssm stop AttendanciaStream
   Get-Content C:\inetpub\attendancia\storage\logs\hikvision-stream.log -Tail 5
   ```

   The log's last line should be *"Received termination signal, exiting
   cleanly."* If instead it just stops mid-sentence, the Ctrl+C never
   reached PHP — check `nssm edit AttendanciaStream` and make sure
   **Console** is the first shutdown method and "Don't create a console
   window" is unchecked. Ingestion still works either way (see §16), but
   you lose the clean shutdown.

If you don't have a device available yet to test any of this against, see
`docs/onboarding.md`'s note on `demo:seed` — it exercises the full ingestion
→ pairing → rule engine pipeline with synthetic data, which is useful for
sanity-checking the *app* independently of whether the device is reachable.

---

## 16. Troubleshooting

- **`hikvision:stream` exits immediately and the supervisor gives up**
  (Supervisor `FATAL`, or an NSSM service that keeps landing back in
  `Stopped`) — check the start-success window wasn't lowered (§12:
  `startsecs` on Linux, `AppThrottle` on Windows), and check the device
  IP/credentials in `.env` are correct. Run `hikvision:dump` manually to see
  the actual connection error.
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
- **The worker's log shows repeated `Still waiting for the database...`
  heartbeats that never resolve** — don't wait for the supervisor to give up
  as your signal that something's actually wrong; it retries forever, and
  each attempt is free to poll for up to `--db-timeout` (default 300s)
  before failing, so that could take a very long time if MySQL never comes
  back. Use the heartbeat count instead: a few minutes of heartbeats right
  after a boot that followed a long outage is normal (InnoDB crash recovery
  genuinely takes longer the more was in-flight when power was lost) — but
  if it's still polling well past that, treat it as a real fault. Check the
  database service first (`systemctl status mysql` / `Get-Service MySQL80`)
  — it may have failed to start at all, not just be slow — then confirm
  `DB_HOST`/`DB_PORT`/`DB_USERNAME`/`DB_PASSWORD` in `.env` are still
  correct, then check by hand with `php artisan db:wait --timeout=5`. Once
  MySQL is confirmed reachable, restart the worker
  (`sudo supervisorctl restart hikvision-stream` /
  `nssm restart AttendanciaStream`) to pick it up immediately rather than
  waiting on the supervisor's own retry timing.

- **Windows: every URL except `/` returns 404** — the URL Rewrite module
  isn't installed, so IIS is ignoring `public/web.config` (§10). On Apache,
  the same symptom means `AllowOverride All` is missing and `.htaccess` is
  being ignored.

- **Windows: the log warns "no signal handling available on this PHP build"**
  — informational, not a fault. Stops become a hard kill instead of a clean
  exit. Nothing is lost when that happens: `raw_events` is deduped on
  `UNIQUE(device_serial, device_event_serial)` and the boot-time backfill
  re-reads the window, so a killed worker's window is recovered on restart.
  It does mean `sapi_windows_set_ctrl_handler` is unavailable, which usually
  means PHP is running without a console — see §15.9.

- **Windows: the `attendance.log` daily rotation misbehaves at midnight** —
  `config/logging.php`'s `attendance` channel is a rotating file written by
  *both* the stream worker and the web server. Windows keeps a lock on an
  open file, so the midnight rename can fail while a process still holds the
  handle, and you may see a stale or missing `attendance-YYYY-MM-DD.log`.
  This is a logging artefact only — ingestion is unaffected — and the
  worker's own NSSM stdout log (`storage\logs\hikvision-stream.log`) is the
  authoritative record for anything the worker did. Don't read a gap here as
  a gap in `raw_events`; check the table.
