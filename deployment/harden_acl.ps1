<#
.SYNOPSIS
    Locks down filesystem ACLs for the Data Gateway (Windows).

.DESCRIPTION
    A compromised PHP worker must not be able to rewrite its own code,
    config/.env, or the CLI/deployment scripts — and only the service
    identity needs to touch storage/. This script enforces exactly that:

      Administrators : Full Control on the whole project tree. The ONLY
                       identity with write access to code and config.
      SYSTEM         : Read & Execute on the tree, Modify on storage/ ONLY.
                       XAMPP's Apache and the scheduled tasks run as SYSTEM,
                       so this is the PHP identity in the default setup.
      WebUser        : the same split as SYSTEM (-WebUser; pass the account
                       PHP really runs as when it is not SYSTEM).
      Everyone else  : BUILTIN\Users, Authenticated Users and Everyone grants
                       are removed from every object in the tree (/T).

    Why SYSTEM is not Full Control: the previous version of this script
    granted it, which defeated the point — the PHP process could still
    rewrite src/, config/.env and deployment/, so a compromised worker could
    change the code that checks it. Read & Execute on the code plus Modify on
    storage/ is the split a compromised worker cannot widen. If Apache really
    runs as SYSTEM, this RX/M split is the only protection the filesystem
    gives you; a dedicated service account is still better.

    Inheritance is removed on every object first (/inheritance:r /T), so no
    grant flows in from the volume root and none of the old broad ACEs
    survive on child objects. The script is idempotent: setting the same ACLs
    again is a no-op (it only ever adds/removes the ACEs named below).

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
    # Resolved in the body: Windows PowerShell 5.1 leaves $PSScriptRoot empty
    # while it binds parameter defaults, so the documented `powershell -File`
    # invocation would otherwise fail before this script does anything.
    [string]$ProjectRoot = '',
    [string]$WebUser = 'NT AUTHORITY\SYSTEM'
)

$ErrorActionPreference = "Stop"

if ($ProjectRoot.Trim() -eq '') {
    $ProjectRoot = Split-Path -Parent $PSScriptRoot
}
if ($ProjectRoot.Trim() -eq '') {
    throw "Cannot derive the project root. Pass -ProjectRoot 'C:\path\to\data-gateway'."
}

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

$webIsSystem = $WebUser.Trim().ToUpperInvariant() -eq 'NT AUTHORITY\SYSTEM'

Write-Host "Hardening ACLs under $root"
Write-Host "WebUser (PHP identity): $WebUser"

# --- 1. No inheritance anywhere ---------------------------------------------
# /inheritance:r removes the inherited ACEs on every object and disables
# inheritance, so nothing broad flows in from the volume root and no child
# keeps a stale explicit copy of an old grant. Every right below is then
# granted explicitly, by this script.
& icacls $root /inheritance:r /T | Out-Null
if ($LASTEXITCODE -ne 0) { throw "icacls /inheritance:r /T failed on $root" }

# --- 2. Strip the broad interactive-user groups -----------------------------
# Belt and braces after /inheritance:r: an object may carry an explicit grant
# to one of these groups from before this script existed.
foreach ($group in @('BUILTIN\Users', 'NT AUTHORITY\Authenticated Users', 'Everyone')) {
    & icacls $root /remove:g $group /T | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "icacls remove ($group) /T failed" }
}

# --- 3. Administrators: the only Full Control -------------------------------
& icacls $root /grant "Administrators:(OI)(CI)F" /T | Out-Null
if ($LASTEXITCODE -ne 0) { throw "icacls grant (Administrators) failed" }

# --- 4. SYSTEM / WebUser: read the tree, write only storage/ ----------------
# The code tree — src/, public/, cli/, deployment/ and config/ (including
# .env, which the gateway must READ) — is Read & Execute. Only storage/ gets
# Modify, for result files, cache and lock files. No Full Control anywhere for
# either identity, so a worker cannot rewrite src/ or .env.
$grantReadOnly = {
    param([string]$identity)
    & icacls $root /grant "${identity}:(OI)(CI)RX" /T | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "icacls grant (${identity} read) failed" }
}
$grantStorageWrite = {
    param([string]$identity)
    & icacls $storage /grant "${identity}:(OI)(CI)M" /T | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "icacls grant (${identity} modify on storage) failed" }
}

& $grantReadOnly 'SYSTEM'
& $grantStorageWrite 'SYSTEM'
if (-not $webIsSystem) {
    # A dedicated account: same split. When it IS SYSTEM the two grants above
    # already cover it (M on storage includes RX on the tree).
    & $grantReadOnly $WebUser
    & $grantStorageWrite $WebUser
}

# --- 5. Prove the split instead of trusting it ------------------------------
# A Full Control ACE for SYSTEM or the WebUser on the root would silently
# undo everything above (e.g. a later manual icacls, or an inherited ACE this
# script could not remove). Fail loudly instead of printing a false "Done".
foreach ($identity in @('NT AUTHORITY\SYSTEM', $WebUser)) {
    foreach ($path in @($root, (Join-Path $root 'config'))) {
        if (-not (Test-Path -LiteralPath $path)) { continue }
        foreach ($ace in (Get-Acl -LiteralPath $path).Access) {
            if ($ace.AccessControlType -ne 'Allow') { continue }
            if ($ace.IdentityReference.Value -ne $identity) { continue }
            if ($ace.FileSystemRights.ToString() -match 'FullControl') {
                throw ("Refusing to continue: '$identity' still has Full Control on '$path'. " +
                       "That is exactly the privilege this script exists to remove; " +
                       "check for a deny/hold on the object and re-run from an elevated prompt.")
            }
        }
    }
}

Write-Host ""
Write-Host "Resulting top-level ACL:"
& icacls $root
Write-Host "storage ACL:"
& icacls $storage
$envFile = Join-Path $root 'config\.env'
if (Test-Path -LiteralPath $envFile) {
    Write-Host "config\.env ACL (must be readable, never writable, by SYSTEM/WebUser):"
    & icacls $envFile
}

Write-Host ""
Write-Host ("Done. Verify the app still works (submit one delayed + one instant request),`n" +
            "then confirm from an UNprivileged prompt that code and config/.env are read-only`n" +
            "for the PHP identity and that storage/ is writable by it.")
