<#
.SYNOPSIS
    Locks down filesystem ACLs for the Data Gateway (Windows).

.DESCRIPTION
    A compromised PHP worker must not be able to rewrite its own code,
    config/.env, or the CLI/deployment scripts — and only the service
    identity needs to touch storage/. This script enforces exactly that:

      Administrators + SYSTEM : Full Control on the whole project tree
      WebUser (PHP identity)  : Read&Execute everywhere (incl. config/
                                so .env stays readable) EXCEPT storage/,
                                which gets Modify (results, cache, locks)
      Everyone else           : removed (BUILTIN\Users / Authenticated Users
                                inherited grants are stripped)

    Inheritance on the project root is disabled and converted to explicit
    ACEs, so nothing re-inherits broad grants from the volume root. The
    script is idempotent: setting the same ACLs again is a no-op.

.PARAMETER ProjectRoot
    Gateway root (the folder that holds src/, public/, storage/, ...).
    Defaults to the folder above deployment/.

.PARAMETER WebUser
    The account PHP runs as. XAMPP's Apache runs as SYSTEM out of the box,
    so that is the default; pass the app-pool identity under IIS
    (e.g. "IIS AppPool\gateway") or any dedicated service account.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deployment\harden_acl.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deployment\harden_acl.ps1 -WebUser "IIS AppPool\gateway"
#>
[CmdletBinding()]
param(
    [string]$ProjectRoot = (Split-Path -Parent $PSScriptRoot),
    [string]$WebUser = 'NT AUTHORITY\SYSTEM'
)

$ErrorActionPreference = "Stop"

# --- Elevation is required to change ACLs on the tree -----------------------
$elevated = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
    ).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $elevated) {
    throw ("harden_acl.ps1 must run from an ELEVATED PowerShell.`n" +
           "Run:  powershell -ExecutionPolicy Bypass -File deployment\harden_acl.ps1`n" +
           "(or right-click PowerShell -> Run as administrator, then the same command)")
}

$root = (Resolve-Path -LiteralPath $ProjectRoot).Path
$storage = Join-Path $root 'storage'
if (-not (Test-Path -LiteralPath $storage -PathType Container)) {
    throw "storage/ not found under '$root' - pass -ProjectRoot with the gateway root."
}

Write-Host "Hardening ACLs under $root"
Write-Host "WebUser (PHP identity): $WebUser"

# Disable inheritance, converting inherited ACEs to explicit ones so the
# grants below fully determine effective access and nothing leaks back in.
& icacls $root /inheritance:d | Out-Null
if ($LASTEXITCODE -ne 0) { throw "icacls /inheritance:d failed on $root" }

# Owners + the OS: full control everywhere.
& icacls $root /grant "Administrators:(OI)(CI)F" "SYSTEM:(OI)(CI)F" | Out-Null
if ($LASTEXITCODE -ne 0) { throw "icacls grant (Administrators/SYSTEM) failed" }

# PHP identity: read+execute on the tree (code, config incl. .env, CLI),
# write access ONLY inside storage/ (result files, cache, lock files).
& icacls $root /grant "${WebUser}:(OI)(CI)RX" | Out-Null
if ($LASTEXITCODE -ne 0) { throw "icacls grant (${WebUser} read) failed" }
& icacls $storage /grant "${WebUser}:(OI)(CI)M" | Out-Null
if ($LASTEXITCODE -ne 0) { throw "icacls grant (${WebUser} modify on storage) failed" }

# Strip the broad interactive-user groups that /inheritance:d converted to
# explicit ACEs: nothing outside Admins/SYSTEM/WebUser needs any access.
& icacls $root /remove "BUILTIN\Users" "NT AUTHORITY\Authenticated Users" | Out-Null
if ($LASTEXITCODE -ne 0) { throw "icacls remove (Users/Authenticated Users) failed" }

Write-Host ""
Write-Host "Resulting top-level ACL:"
& icacls $root
Write-Host "storage ACL:"
& icacls $storage

Write-Host ""
Write-Host ("Done. Verify the app still works (submit one delayed + one instant request),`n" +
            "then confirm from an UNprivileged prompt that code and config/.env are read-only`n" +
            "for the PHP identity.")
