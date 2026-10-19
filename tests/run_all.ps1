<#
.SYNOPSIS
    Data Gateway - safe full test runner.

.DESCRIPTION
    Creates a THROWAWAY metadata database (data_gateway_test_<random>) and a
    throwaway storage dir (storage\test-<random>), points the gateway at them
    via GATEWAY_DB_NAME / GATEWAY_STORAGE_DIR, then runs:
      1. php -l over every *.php (except vendor/)
      2. unit-style tests (smoke_db, encryption_test --db)
      3. the four smoke tests (api, admin, worker, webhook) + audit_spot
      4. stream_memory_test (reduced row count)
    The test DB and storage dir are ALWAYS dropped afterwards (even on
    failure). The live data_gateway database and the real storage/ are never
    touched: every test refuses to run unless GATEWAY_TEST_MODE=1 and the
    configured DB name contains "_test" (test_guard in bootstrap.php).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tests\run_all.ps1
#>
[CmdletBinding()]
param(
    [string]$MysqlExe = "C:\xampp\mysql\bin\mysql.exe",
    [string]$PhpExe = "C:\xampp\php\php.exe"
)

$ErrorActionPreference = "Stop"

# --- Locate tools ------------------------------------------------------------
if (-not (Test-Path -LiteralPath $MysqlExe -PathType Leaf)) {
    $m = Get-Command mysql.exe -ErrorAction SilentlyContinue
    if ($m) { $MysqlExe = $m.Source } else { throw "mysql.exe not found; pass -MysqlExe" }
}
if (-not (Test-Path -LiteralPath $PhpExe -PathType Leaf)) {
    $p = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($p) { $PhpExe = $p.Source } else { throw "php.exe not found; pass -PhpExe" }
}

$projectRoot = Split-Path -Parent $PSScriptRoot
$mysqlArgs = @("-u", "root")

# The gateway connects as this user; it needs rights on the throwaway DB.
# Read the NAME only from config/.env (never the password); default gateway.
$dbUser = "gateway"
$envFile = Join-Path $projectRoot "config\.env"
if (Test-Path -LiteralPath $envFile -PathType Leaf) {
    $m = Select-String -LiteralPath $envFile -Pattern '^\s*GATEWAY_DB_USER\s*=\s*(\S+)\s*$' | Select-Object -First 1
    if ($m -and $m.Groups[1].Value) { $dbUser = $m.Groups[1].Value }
}

# --- Throwaway environment ---------------------------------------------------
$suffix = -join ((48..57) + (97..122) | Get-Random -Count 8 | ForEach-Object { [char]$_ })
$testDb = "data_gateway_test_$suffix"
$storageDir = Join-Path $projectRoot "storage\test-$suffix"

& $MysqlExe @mysqlArgs -e "CREATE DATABASE IF NOT EXISTS ``$testDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if ($LASTEXITCODE -ne 0) { throw "cannot create test database '$testDb' (mysql root access works?)" }
# GRANT ON the throwaway DB only. The grants vanish with the DB on drop.
foreach ($dbHost in @("localhost", "127.0.0.1")) {
    & $MysqlExe @mysqlArgs -e "GRANT ALL PRIVILEGES ON ``$testDb``.* TO '$dbUser'@'$dbHost';"
    if ($LASTEXITCODE -ne 0) { Write-Host "WARN: could not grant on $testDb to '$dbUser'@'$dbHost' ($LASTEXITCODE)" }
}
Get-Content -Raw (Join-Path $projectRoot "sql\schema.sql") | & $MysqlExe @mysqlArgs --default-character-set=utf8mb4 $testDb
if ($LASTEXITCODE -ne 0) { throw "cannot import sql\schema.sql into '$testDb'" }

New-Item -ItemType Directory -Force -Path $storageDir | Out-Null

# Environment for every child process (tests + the php -S servers they spawn).
$env:GATEWAY_TEST_MODE = "1"
$env:GATEWAY_DB_NAME = $testDb
$env:GATEWAY_STORAGE_DIR = $storageDir
# Links must be built from a real base URL; http is accepted only because the
# tests run with GATEWAY_DEBUG=1 (see Links::base / cli\check.php).
$env:GATEWAY_BASE_URL = "http://127.0.0.1"
$env:GATEWAY_DEBUG = "1"
# Tiny inline cap so api_smoke can exercise the 413 result_too_large path with
# a small fixture row; real deployments keep the 16 MiB default.
$env:GATEWAY_INSTANT_MAX_BYTES = "2048"
# Make sure children pick up the SAME php binary the suites spawn as "php".
$env:Path = (Split-Path -Parent $PhpExe) + ";" + $env:Path

Write-Host "== test DB:      $testDb"
Write-Host "== test storage: $storageDir"

# --- Runner ------------------------------------------------------------------
$failures = 0
$results = @()

function Invoke-Suite([string]$name, [scriptblock]$block) {
    Write-Host ""
    Write-Host "--- $name ---"
    & $block
    if ($LASTEXITCODE -eq 0) {
        $script:results += [pscustomobject]@{ Suite = $name; Result = "PASS" }
        Write-Host "PASS  $name"
    }
    else {
        $script:results += [pscustomobject]@{ Suite = $name; Result = "FAIL (exit $LASTEXITCODE)" }
        $script:failures++
        Write-Host "FAIL  $name (exit $LASTEXITCODE)"
    }
}

try {
    # 1) syntax-check every PHP file except vendor/
    Invoke-Suite "php -l (all files)" {
        $bad = 0
        Get-ChildItem -Path $projectRoot -Recurse -Filter *.php |
            Where-Object { $_.FullName -notmatch '\\vendor\\' } |
            ForEach-Object {
                $out = & $PhpExe -l $_.FullName 2>&1
                if ($LASTEXITCODE -ne 0) { $bad = 1; Write-Host $out }
            }
        & cmd /c "exit $bad"
    }

    # 2) unit-style tests
    Invoke-Suite "smoke_db" {
        & $PhpExe (Join-Path $PSScriptRoot "smoke_db.php")
    }
    Invoke-Suite "encryption_test --db" {
        & $PhpExe (Join-Path $PSScriptRoot "encryption_test.php") --db
    }
    Invoke-Suite "links_test" {
        & $PhpExe (Join-Path $PSScriptRoot "links_test.php")
    }
    Invoke-Suite "filesystem_test" {
        & $PhpExe (Join-Path $PSScriptRoot "filesystem_test.php")
    }
    Invoke-Suite "sqlguard_test" {
        & $PhpExe (Join-Path $PSScriptRoot "sqlguard_test.php")
    }
    Invoke-Suite "readonly_driver_test" {
        & $PhpExe (Join-Path $PSScriptRoot "readonly_driver_test.php")
    }
    Invoke-Suite "pool_test" {
        & $PhpExe (Join-Path $PSScriptRoot "pool_test.php")
    }
    Invoke-Suite "resultwriter_test" {
        & $PhpExe (Join-Path $PSScriptRoot "resultwriter_test.php")
    }
    Invoke-Suite "throttle_test" {
        & $PhpExe (Join-Path $PSScriptRoot "throttle_test.php")
    }

    # 3) the four smoke tests.
    # (audit_spot is a manual spot-check: it needs audit rows to survive in
    #  the DB, but the smokes now clean up after themselves - run it by hand
    #  against a DB that has recent admin activity.)
    Invoke-Suite "api_smoke" {
        & $PhpExe (Join-Path $PSScriptRoot "api_smoke.php")
    }
    Invoke-Suite "admin_smoke" {
        & $PhpExe (Join-Path $PSScriptRoot "admin_smoke.php")
    }
    Invoke-Suite "worker_smoke" {
        & $PhpExe (Join-Path $PSScriptRoot "worker_smoke.php")
    }
    Invoke-Suite "retention_test" {
        & $PhpExe (Join-Path $PSScriptRoot "retention_test.php")
    }
    Invoke-Suite "webhook_smoke" {
        & $PhpExe (Join-Path $PSScriptRoot "webhook_smoke.php")
    }

    # 4) streaming memory probe (reduced row count, throwaway target schema)
    Invoke-Suite "stream_memory_test" {
        & $PhpExe (Join-Path $PSScriptRoot "stream_memory_test.php") 100000
    }
}
finally {
    # --- always tear the throwaway environment down --------------------------
    Write-Host ""
    Write-Host "== cleanup =="
    & $MysqlExe @mysqlArgs -e "DROP DATABASE IF EXISTS ``$testDb``;"
    if ($LASTEXITCODE -ne 0) { Write-Host "WARN: could not drop $testDb - drop it manually!"; $script:failures++ }
    else { Write-Host "dropped $testDb" }
    if (Test-Path -LiteralPath $storageDir) {
        Remove-Item -LiteralPath $storageDir -Recurse -Force
        Write-Host "removed $storageDir"
    }
    Remove-Item Env:\GATEWAY_TEST_MODE -ErrorAction SilentlyContinue
    Remove-Item Env:\GATEWAY_DB_NAME -ErrorAction SilentlyContinue
    Remove-Item Env:\GATEWAY_STORAGE_DIR -ErrorAction SilentlyContinue
    Remove-Item Env:\GATEWAY_BASE_URL -ErrorAction SilentlyContinue
    Remove-Item Env:\GATEWAY_DEBUG -ErrorAction SilentlyContinue
}

Write-Host ""
Write-Host "== summary =="
$results | Format-Table -AutoSize
if ($failures -gt 0) {
    Write-Host "== RUN_ALL: $failures suite(s) FAILED =="
    exit 1
}
Write-Host "== RUN_ALL: ALL SUITES PASSED =="
exit 0
