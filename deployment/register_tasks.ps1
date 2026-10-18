<#
.SYNOPSIS
    Registers the three Data Gateway scheduled tasks in Windows Task Scheduler.

.DESCRIPTION
    worker.php   - claims due delayed executions          (every 1 minute)
    cleanup.php  - expires results, recovers stale rows   (every 5 minutes)
    webhooks.php - delivers pending webhook notifications (every 1 minute)

    The project root is derived from this script's own location (..\), so the
    folder can be moved as long as deployment/ stays inside the project.

.PARAMETER PhpExe
    Full path to the PHP CLI binary. Defaults to the XAMPP location.

.PARAMETER Prefix
    Task-name prefix (use it to run a second gateway instance side by side).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deployment\register_tasks.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deployment\register_tasks.ps1 -PhpExe "C:\php\php.exe"

.NOTES
    Registers the tasks under the CURRENT user (runs only while logged on).
    For an unattended service account, register them as a scheduled task that
    runs whether the user is logged on or not, or move them to a Windows
    Service. Run this from an elevated shell if your policy requires it.
#>
[CmdletBinding()]
param(
    [string]$PhpExe = "C:\xampp\php\php.exe",
    [string]$Prefix = "DataGateway"
)

$ErrorActionPreference = "Stop"

# --- Resolve paths from this script's location ------------------------------
$projectRoot = Split-Path -Parent $PSScriptRoot
if (-not (Test-Path -LiteralPath $PhpExe -PathType Leaf)) {
    throw "PHP CLI not found at '$PhpExe'. Pass -PhpExe with the correct path."
}

# The three tasks: script name, repetition minutes, human label.
$tasks = @(
    @{ Script = "worker.php";   Minutes = 1; Label = "delayed executions" },
    @{ Script = "cleanup.php";  Minutes = 5; Label = "expiry + recovery" },
    @{ Script = "webhooks.php"; Minutes = 1; Label = "webhook delivery" }
)

foreach ($t in $tasks) {
    $scriptPath = Join-Path $projectRoot ("cli\" + $t.Script)
    if (-not (Test-Path -LiteralPath $scriptPath -PathType Leaf)) {
        throw "Missing CLI script: $scriptPath"
    }

    $taskName = "$Prefix-$($t.Script -replace '\.php$','')"

    $action = New-ScheduledTaskAction -Execute $PhpExe -Argument "`"$scriptPath`"" -WorkingDirectory $projectRoot
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
        -RepetitionInterval (New-TimeSpan -Minutes $t.Minutes) `
        -RepetitionDuration (New-TimeSpan -Days 3650)

    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
        -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes ([Math]::Max(10, $t.Minutes + 5))) `
        -MultipleInstances IgnoreNew

    # Idempotent: replace any prior registration with the same name.
    if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    }
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings | Out-Null

    Write-Host "Registered '$taskName' -> $PhpExe $($t.Script) (every $($t.Minutes) min: $($t.Label))"
}

Write-Host ""
Write-Host "Done. Verify with:  Get-ScheduledTask -TaskName '$Prefix-*'"
Write-Host "The tasks run as the current user, only while logged on."
