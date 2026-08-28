#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Stops and removes the two Attendancia services installed by
    install-services.ps1.

.DESCRIPTION
    The equivalent of removing the Supervisor conf and running
    `supervisorctl reread && supervisorctl update` on Linux.

    Removing the services does not touch the database, the .env, or anything
    in storage/ — including the logs, which are usually the reason you are
    uninstalling in the first place.
#>

$Nssm     = 'nssm.exe'
$Services = @('AttendanciaStream', 'AttendanciaScheduler')

$ErrorActionPreference = 'Stop'

if (-not (Get-Command $Nssm -ErrorAction SilentlyContinue)) {
    throw "nssm.exe not found. Put it on PATH, or set `$Nssm to its full path."
}

foreach ($name in $Services) {
    if (-not (Get-Service -Name $name -ErrorAction SilentlyContinue)) {
        Write-Host "$name is not installed — skipping."
        continue
    }

    Write-Host "Stopping and removing $name..."

    # Give the stream worker its full stop window: it should exit cleanly on
    # the Ctrl+C rather than being killed mid-read.
    & $Nssm stop   $name          | Out-Null
    & $Nssm remove $name confirm  | Out-Null
}

Write-Host "`nDone."
