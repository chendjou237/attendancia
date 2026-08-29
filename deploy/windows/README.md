# Windows Server deployment files

The production server is a Windows PC at the school. These files are the
Windows halves of what `deploy/supervisor/` and `deploy/mysql/` do on Linux —
the application code itself is identical on both platforms.

| File | Linux counterpart |
|---|---|
| `install-services.ps1` | `deploy/supervisor/hikvision-stream.conf` + the crontab entry in `docs/setup.md` §13 |
| `uninstall-services.ps1` | `supervisorctl remove` |
| `backup.bat` | the `mysqldump` crontab entry in `docs/setup.md` §14 |
| `my.ini.snippet` | `deploy/mysql/attendancia.cnf` |

Run the `.ps1` files from an **elevated** PowerShell prompt. Both read their
paths from the variables at the top of the file — edit those first.

`docs/setup.md` is the runbook; these files are only useful alongside it.

## Why NSSM rather than a bare Windows service

`hikvision:stream` is a console application that expects to be restarted
forever and stopped politely. `sc.exe create` cannot do either: a plain
Windows service has no restart-always-with-backoff policy that survives an
indefinitely offline device, and it stops a console process by terminating
it. NSSM gives both, and its `AppStopMethodConsole` sends a real Ctrl+C —
which is what `App\Services\Console\GracefulShutdown` catches on Windows, so
`nssm stop` produces the same clean exit that `supervisorctl stop` does on
Linux.
