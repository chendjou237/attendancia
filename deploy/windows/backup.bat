@echo off
REM ---------------------------------------------------------------------------
REM Nightly database backup — the Windows counterpart of the mysqldump crontab
REM entry in docs/setup.md §14.
REM
REM Schedule it from Task Scheduler to run at 01:30, i.e. BEFORE the 02:00
REM nightly backfill, so a restore never needs to re-run a backfill that has
REM already happened against the dump.
REM
REM   schtasks /create /tn "Attendancia backup" /tr "C:\path\to\backup.bat" ^
REM            /sc daily /st 01:30 /ru SYSTEM
REM
REM The password is NOT in this file. Put it in a MySQL option file that only
REM the backup account can read, and point CREDENTIALS at it:
REM
REM   [client]
REM   user=attendancia
REM   password=...
REM
REM There is no UPS on this server (§1). InnoDB survives abrupt shutdowns, but
REM that is not a backup — write these somewhere that is not this disk.
REM ---------------------------------------------------------------------------

setlocal

set "MYSQLDUMP=C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe"
set "CREDENTIALS=C:\ProgramData\attendancia\backup.cnf"
set "DATABASE=attendancia"
set "BACKUPDIR=D:\backups\attendancia"

REM Deliberately not %date%: its format follows the machine's locale, and on a
REM French-locale box it contains slashes, which are not legal in a filename.
for /f %%d in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "STAMP=%%d"

if not exist "%BACKUPDIR%" mkdir "%BACKUPDIR%"

set "DUMPFILE=%BACKUPDIR%\attendancia-%STAMP%.sql"

REM --single-transaction takes a consistent snapshot without locking the
REM tables, so an overnight scan arriving mid-dump is neither blocked nor
REM half-captured.
"%MYSQLDUMP%" --defaults-extra-file="%CREDENTIALS%" --single-transaction --routines --events "%DATABASE%" > "%DUMPFILE%"

if errorlevel 1 (
    echo mysqldump failed with errorlevel %errorlevel% — leaving "%DUMPFILE%" in place for inspection.
    exit /b 1
)

powershell -NoProfile -Command "Compress-Archive -Path '%DUMPFILE%' -DestinationPath '%DUMPFILE%.zip' -Force"

if errorlevel 1 (
    echo Compression failed — keeping the uncompressed dump.
    exit /b 1
)

del "%DUMPFILE%"

echo Backup written to "%DUMPFILE%.zip"
exit /b 0
