#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Configures failure-recovery actions for MySQL80, AttendanciaStream, and
    AttendanciaScheduler so all three survive a machine restart automatically.

.DESCRIPTION
    Run once from an elevated PowerShell prompt:
        cd C:\inetpub\attendancia\deploy\windows
        .\fix-service-recovery.ps1

    What it does:
      1. MySQL80 -- sets failure actions (restart after 5s, 10s, 30s) with a
                    24h reset period. Without this, Windows makes one boot
                    attempt and gives up permanently if MySQL is slow to start
                    (e.g. InnoDB crash recovery after a power cut).
      2. AttendanciaStream / AttendanciaScheduler -- starts them if stopped.
         NSSM handles their own crash-restart loop; this just recovers the
         current stopped state after MySQL is confirmed up.
#>

$ErrorActionPreference = 'Stop'

# --- 1. MySQL80: failure recovery actions ------------------------------------
Write-Host "Setting MySQL80 failure-recovery actions..."
sc.exe failure MySQL80 reset= 86400 actions= restart/5000/restart/10000/restart/30000
Set-Service -Name MySQL80 -StartupType Automatic
Write-Host "  Startup type : Automatic"
Write-Host "  Failure actions: restart at 5s / 10s / 30s (reset counter after 24h)"

# --- 2. Wait for MySQL to accept connections ----------------------------------
Write-Host "`nWaiting for MySQL80 to be Running..."
$deadline = (Get-Date).AddSeconds(60)
while ((Get-Service MySQL80).Status -ne 'Running') {
    if ((Get-Date) -gt $deadline) { throw "MySQL80 did not start within 60 s." }
    Start-Sleep -Seconds 2
}
Write-Host "  MySQL80 is Running."

# --- 3. Start Attendancia services if stopped --------------------------------
foreach ($svc in @('AttendanciaStream', 'AttendanciaScheduler')) {
    $s = Get-Service -Name $svc -ErrorAction SilentlyContinue
    if (-not $s) { Write-Warning "$svc not found -- run install-services.ps1 first."; continue }
    if ($s.Status -ne 'Running') {
        Write-Host "Starting $svc..."
        Start-Service -Name $svc
        Start-Sleep -Seconds 2
    }
    Write-Host "  $svc : $((Get-Service $svc).Status)"
}

# --- 4. Status summary -------------------------------------------------------
Write-Host "`n--- Service status ---"
Get-Service MySQL80, AttendanciaStream, AttendanciaScheduler | Format-Table Name, Status, StartType -AutoSize

Write-Host "Self-healing chain is now configured:"
Write-Host "  1. MySQL80           auto-starts, retries on failure (5s/10s/30s)"
Write-Host "  2. AttendanciaStream depends on MySQL80 + polls with --wait-for-db"
Write-Host "  3. AttendanciaScheduler depends on MySQL80"
Write-Host "  4. NSSM restarts both workers indefinitely on crash"
Write-Host ""
Write-Host "Verify after next reboot:"
Write-Host "  Get-Service MySQL80, AttendanciaStream, AttendanciaScheduler"
Write-Host "  Get-Content -Wait C:\inetpub\attendancia\storage\logs\hikvision-stream.log"
