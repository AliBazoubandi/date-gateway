<#
.SYNOPSIS
    Data Gateway - safe full test runner (Windows entry point).

.DESCRIPTION
    The work lives in tests\run_all.php, which runs on Windows, Linux and
    macOS; this script only finds the two binaries and hands them over, so
    CI and the local run use the same code path.

    run_all.php creates a THROWAWAY metadata database
    (data_gateway_test_<random>, imported from sql\schema.sql) and a throwaway
    storage dir in the system temp dir (gw-test-<random>, never inside the
    project's storage/), points the gateway at them via
    GATEWAY_DB_NAME / GATEWAY_STORAGE_DIR, and runs:
      1. php -l over every *.php (except vendor/)
      2. the unit-style suites: schema/binder (smoke_db), encryption, links,
         filesystem, SQL guard, read-only drivers, the worker pool, result
         writer, submit throttle, trusted proxies, driver TLS options,
         Str::json, SAPI bootstrap, Db::transaction, target column naming,
         the instant cache-window clamp, the abandoned instant file
      3. the health endpoint (health_test) plus the end-to-end smokes over a
         real php -S server and a real worker pass (api, admin, worker, webhook),
         retention pruning and the webhook URL policy
      4. stream_memory_test (reduced row count)
    tests\audit_spot.php and tests\driver_test.php are manual inspectors (the
    first reads whatever DB is configured, the second needs real target
    credentials), so neither runner calls them.
    The test DB and storage dir are ALWAYS dropped afterwards (even on
    failure), because the cleanup handler is registered before anything is
    created. The live data_gateway database and the real storage/ are never
    touched: every test refuses to run unless GATEWAY_TEST_MODE=1 and the
    configured DB name contains "_test" (test_guard in bootstrap.php).

    Run tests\run_all.php directly to pass the other options (--only, --rows,
    --keep, --list) and for --drop-stale, which drops leftover
    data_gateway_test_* schemas from a run that was hard-killed (add --force
    for a non-interactive shell).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tests\run_all.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tests\run_all.ps1 -MysqlExe "C:\xampp\mysql\bin\mysql.exe" -PhpExe "C:\xampp\php\php.exe"
#>
[CmdletBinding()]
param(
    [string]$MysqlExe = "",
    [string]$PhpExe = "",
    [string]$Only = "",
    [int]$Rows = 0,
    [switch]$Keep
)

$ErrorActionPreference = "Stop"

if (-not $PhpExe) {
    $p = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($p) { $PhpExe = $p.Source }
    elseif (Test-Path -LiteralPath "C:\xampp\php\php.exe" -PathType Leaf) { $PhpExe = "C:\xampp\php\php.exe" }
    else { $p = Get-Command php -ErrorAction SilentlyContinue
           if ($p) { $PhpExe = $p.Source } else { throw "php not found; pass -PhpExe" } }
}

$runner = Join-Path $PSScriptRoot "run_all.php"
$argsList = @($runner)
if ($MysqlExe) { $argsList += "--mysql=$MysqlExe" }
if ($Only)     { $argsList += "--only=$Only" }
if ($Rows)     { $argsList += "--rows=$Rows" }
if ($Keep)     { $argsList += "--keep" }

& $PhpExe @argsList
exit $LASTEXITCODE
