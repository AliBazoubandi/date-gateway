<#
.SYNOPSIS
    Registers the three Data Gateway scheduled tasks in Windows Task Scheduler.

.DESCRIPTION
    worker_pool.php - fans out one per-connection worker per due connection
                      (every 1 minute; cli\worker.php is the manual single pass)
    cleanup.php  - expires results, recovers stale rows   (every 5 minutes)
    webhooks.php - delivers pending webhook notifications (every 1 minute)

    The project root is derived from this script's own location (..\), so the
    folder can be moved as long as deployment/ stays inside the project.

.PARAMETER PhpExe
    Full path to the PHP CLI binary. Defaults to the XAMPP location.

.PARAMETER Prefix
    Task-name prefix (use it to run a second gateway instance side by side).

.PARAMETER RunAs
    System      (default) - tasks run as NT AUTHORITY\SYSTEM: no console window
    ever appears (session 0) and they run whether a user is logged on or not.
    Requires an elevated PowerShell. Recommended for servers.

    CurrentUser - tasks run under the current account. Console-mode would flash
    a window every minute, so these runs go through deployment\run_hidden.vbs
    (wscript, GUI subsystem => completely silent, exit code still forwarded).
    Only valid while that user is logged on.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deployment\register_tasks.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deployment\register_tasks.ps1 -RunAs CurrentUser

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deployment\register_tasks.ps1 -PhpExe "C:\php\php.exe"
#>
[CmdletBinding()]
param(
    [string]$PhpExe = "C:\xampp\php\php.exe",
    [string]$Prefix = "DataGateway",
    [ValidateSet('System', 'CurrentUser')]
    [string]$RunAs = 'System'
)

$ErrorActionPreference = "Stop"

# --- Resolve paths from this script's location ------------------------------
$projectRoot = Split-Path -Parent $PSScriptRoot
if (-not (Test-Path -LiteralPath $PhpExe -PathType Leaf)) {
    throw "PHP CLI not found at '$PhpExe'. Pass -PhpExe with the correct path."
}

# --- Elevation is required to register SYSTEM -------------------------------
if ($RunAs -eq 'System') {
    $elevated = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
        ).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    if (-not $elevated) {
        throw "Registering tasks as SYSTEM requires an elevated PowerShell. `n" +
              "Run: powershell -ExecutionPolicy Bypass -File deployment\register_tasks.ps1 (as administrator)`n" +
              "or use: -RunAs CurrentUser"
    }
}

# --- Tasks: script name, repetition minutes, human label, hard time cap ------
# TimeLimitMin is a HARD kill switch: if a run wedges (DB hang, stuck socket),
# Task Scheduler force-terminates it. The next scheduled fire simply claims
# the work again, so an aggressive cap is safe — an unlimited run is not.
#
# The caps must clear the LONGEST legitimate run, not the average one: a delayed
# request may run up to 1800 s (30 min, the admin cap on timeout_seconds), so the
# worker entry gets 60 min = 2x that. A run killed early leaves its execution
# 'running' until worker.stale_running_minutes (90) sweeps it - the client waits
# that long for a failure, which is why the cap must never sit near 30 min.
#
# 'Name' pins the registered task identity so renaming the script behind a task
# (worker.php -> worker_pool.php, P1-1) updates the existing task in place
# instead of leaving an orphan registered next to a duplicate.
$tasks = @(
    @{ Script = "worker_pool.php"; Name = "worker"; Minutes = 1; Label = "delayed executions"; TimeLimitMin = 60 },
    @{ Script = "cleanup.php";  Minutes = 5; Label = "expiry + recovery";  TimeLimitMin = 30 },
    @{ Script = "webhooks.php"; Minutes = 1; Label = "webhook delivery";   TimeLimitMin = 15 }
)

# --- Hidden launcher for the CurrentUser mode -------------------------------
if ($RunAs -eq 'CurrentUser') {
    $vbs = Join-Path $PSScriptRoot "run_hidden.vbs"
    @'
' usage: wscript.exe //B run_hidden.vbs "<php.exe>" "<script.php>"
' Window style 0 (invisible) + synchronous, so the true PHP exit code is
' forwarded to Task Scheduler. wscript itself is GUI-subsystem: NO console
' window flashes when tasks fire every minute.
If WScript.Arguments.Count < 2 Then WScript.Quit 1
Dim phpExe, phpScript, sh, exitCode
phpExe    = WScript.Arguments(0)
phpScript = WScript.Arguments(1)
Set sh = CreateObject("WScript.Shell")
sh.CurrentDirectory = Mid(phpScript, 1, InStrRev(phpScript, "\") - 1)
exitCode = sh.Run(Chr(34) & phpExe & Chr(34) & " " & Chr(34) & phpScript & Chr(34), 0, True)
WScript.Quit exitCode
'@ | Set-Content -LiteralPath $vbs -Encoding ASCII
}

foreach ($t in $tasks) {
    $scriptPath = Join-Path $projectRoot ("cli\" + $t.Script)
    if (-not (Test-Path -LiteralPath $scriptPath -PathType Leaf)) {
        throw "Missing CLI script: $scriptPath"
    }

    $taskName = "$Prefix-$(if ($t.Name) { $t.Name } else { $t.Script -replace '\.php$','' })"

    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
        -RepetitionInterval (New-TimeSpan -Minutes $t.Minutes) `
        -RepetitionDuration (New-TimeSpan -Days 3650)

    # Hard cap per run — see $tasks table above for why these values.
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
        -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes $t.TimeLimitMin) `
        -MultipleInstances IgnoreNew

    if ($RunAs -eq 'System') {
        $action = New-ScheduledTaskAction -Execute $PhpExe -Argument "`"$scriptPath`"" -WorkingDirectory $projectRoot
        # SYSTEM runs in session 0: invisible, survives logoff/reboot.
        $principal = New-ScheduledTaskPrincipal -UserId "NT AUTHORITY\SYSTEM" -RunLevel Highest
        if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
            Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
        }
        Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger `
            -Settings $settings -Principal $principal | Out-Null
    }
    else {
        $vbs = Join-Path $PSScriptRoot "run_hidden.vbs"
        # wscript is GUI-subsystem: firing every minute shows no window.
        $action = New-ScheduledTaskAction -Execute "$($env:SystemRoot)\System32\wscript.exe" `
            -Argument "//B `"$vbs`" `"$PhpExe`" `"$scriptPath`"" -WorkingDirectory $projectRoot
        if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
            Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
        }
        Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings | Out-Null
    }

    Write-Host "Registered '$taskName' ($RunAs) -> $($t.Script) (every $($t.Minutes) min: $($t.Label))"
}

Write-Host ""
Write-Host "Done. Verify with:  Get-ScheduledTask -TaskName '$Prefix-*' | Format-Table TaskName, State"
if ($RunAs -eq 'System') {
    Write-Host "Tasks run as SYSTEM: invisible, and whether a user is logged on or not."
}
else {
    Write-Host "Tasks run as the current user while logged on, fully via the hidden VBS launcher."
}