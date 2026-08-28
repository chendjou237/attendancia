#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Installs the two Attendancia background services on Windows Server.

.DESCRIPTION
    The Windows counterpart of deploy/supervisor/hikvision-stream.conf and the
    crontab entry in docs/setup.md §13. Creates:

      AttendanciaStream     — the live Hikvision alertStream worker (§7.2)
      AttendanciaScheduler  — Laravel's scheduler, which fires the 02:00
                              nightly backfill + recompute (§13)

    Both are installed with NSSM (https://nssm.cc). See README.md in this
    directory for why NSSM rather than sc.exe.

    Re-running this script against existing services is safe: it removes and
    recreates them.

.NOTES
    Edit the variables below to match this server, then run from an elevated
    PowerShell prompt:

        .\install-services.ps1
#>

# --- Edit these four ---------------------------------------------------------

# Full path to the PHP 8.3+ CLI binary (the Non-Thread-Safe build).
$PhpExe    = 'C:\php\php.exe'

# The application root — the directory containing artisan.
$AppRoot   = 'C:\inetpub\attendancia'

# The device serial for the corridor this worker watches. Must match a row in
# the `devices` table exactly (docs/setup.md §6, §8).
$Serial    = 'main-gate'

# Where service stdout/stderr goes. Created if missing.
$LogDir    = 'C:\inetpub\attendancia\storage\logs'

# --- Usually fine as-is ------------------------------------------------------

# nssm.exe — on PATH, or give the full path.
$Nssm      = 'nssm.exe'

# The Windows service name of MySQL, from `Get-Service *mysql*`. Both services
# are made to depend on it so Windows at least starts them in the right order;
# the stream worker's own --wait-for-db is what actually handles the case that
# matters, where MySQL has "started" but is still doing InnoDB crash recovery
# after a power cut (docs/setup.md §1).
$MysqlSvc  = 'MySQL80'

$StreamSvc    = 'AttendanciaStream'
$SchedulerSvc = 'AttendanciaScheduler'

# -----------------------------------------------------------------------------

$ErrorActionPreference = 'Stop'

foreach ($path in @($PhpExe, (Join-Path $AppRoot 'artisan'))) {
    if (-not (Test-Path $path)) {
        throw "Not found: $path — check the variables at the top of this script."
    }
}

if (-not (Get-Command $Nssm -ErrorAction SilentlyContinue)) {
    throw "nssm.exe not found. Download it from https://nssm.cc, put it on PATH, or set `$Nssm to its full path."
}

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Remove-ServiceIfPresent($name) {
    if (Get-Service -Name $name -ErrorAction SilentlyContinue) {
        Write-Host "Removing the existing $name service..."
        & $Nssm stop   $name          | Out-Null
        & $Nssm remove $name confirm  | Out-Null
        Start-Sleep -Seconds 2
    }
}

function Set-CommonPolicy($name, $stdout, $stderr) {
    & $Nssm set $name AppDirectory $AppRoot

    # Restart forever. The Linux side spells this autorestart=true with
    # startretries=999; NSSM throttles rather than counting attempts, so it
    # never gives up at all — which is the behaviour docs/setup.md §12
    # actually wants for a terminal that may legitimately be powered off or
    # network-isolated for hours.
    & $Nssm set $name AppExit Default Restart
    & $Nssm set $name AppRestartDelay 5000

    # The direct analogue of Supervisor's startsecs=10, and load-bearing for
    # the same reason: an unreachable device makes the worker exit inside its
    # own 10s connectTimeout. A throttle window above that tells NSSM to treat
    # those fast exits as failures worth backing off from, instead of
    # hammering the device in a tight restart loop.
    & $Nssm set $name AppThrottle 15000

    # Stop by sending Ctrl+C, which GracefulShutdown catches on Windows — the
    # equivalent of Supervisor's stopsignal=TERM. The 15000ms matches
    # stopwaitsecs=15. AppNoConsole is deliberately left at its default (0):
    # without a console allocated, Ctrl+C cannot be delivered and every stop
    # would degrade to a kill.
    & $Nssm set $name AppStopMethodConsole 15000
    & $Nssm set $name AppStopMethodWindow 5000
    & $Nssm set $name AppStopMethodThreads 5000

    # Supervisor's stdout_logfile + stdout_logfile_maxbytes=10MB.
    & $Nssm set $name AppStdout $stdout
    & $Nssm set $name AppStderr $stderr
    & $Nssm set $name AppStdoutCreationDisposition 4
    & $Nssm set $name AppStderrCreationDisposition 4
    & $Nssm set $name AppRotateFiles 1
    & $Nssm set $name AppRotateOnline 1
    & $Nssm set $name AppRotateBytes 10485760

    & $Nssm set $name Start SERVICE_AUTO_START
    & $Nssm set $name DependOnService $MysqlSvc
}

$artisan = Join-Path $AppRoot 'artisan'

# --- The live stream worker ---------------------------------------------------

Remove-ServiceIfPresent $StreamSvc
Write-Host "Installing $StreamSvc..."
& $Nssm install $StreamSvc $PhpExe
& $Nssm set $StreamSvc AppParameters "`"$artisan`" hikvision:stream $Serial --wait-for-db"
Set-CommonPolicy $StreamSvc (Join-Path $LogDir 'hikvision-stream.log') (Join-Path $LogDir 'hikvision-stream.log')
& $Nssm set $StreamSvc Description "Attendancia: Hikvision alertStream live event worker for device $Serial"

# --- The scheduler ------------------------------------------------------------
#
# schedule:work is the long-running equivalent of Linux's per-minute
# `* * * * * artisan schedule:run` crontab entry. As a service it is both
# simpler to reason about and cheaper than a Task Scheduler entry that spawns
# PHP 1,440 times a day. It holds no long-lived connection, so a hard stop
# costs nothing and it needs no signal handling to be correct.

Remove-ServiceIfPresent $SchedulerSvc
Write-Host "Installing $SchedulerSvc..."
& $Nssm install $SchedulerSvc $PhpExe
& $Nssm set $SchedulerSvc AppParameters "`"$artisan`" schedule:work"
Set-CommonPolicy $SchedulerSvc (Join-Path $LogDir 'scheduler.log') (Join-Path $LogDir 'scheduler.log')
& $Nssm set $SchedulerSvc Description 'Attendancia: Laravel scheduler (nightly backfill and recompute)'

# --- Start --------------------------------------------------------------------

Write-Host "`nStarting services..."
& $Nssm start $StreamSvc
& $Nssm start $SchedulerSvc

Start-Sleep -Seconds 3
Get-Service $StreamSvc, $SchedulerSvc | Format-Table -AutoSize

Write-Host @"

Both services are installed and set to start at boot.

Verify with:
  Get-Service $StreamSvc, $SchedulerSvc
  Get-Content -Wait '$(Join-Path $LogDir 'hikvision-stream.log')'

A clean stop should log "Received termination signal, exiting cleanly":
  $Nssm stop $StreamSvc
"@
