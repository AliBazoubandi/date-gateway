# Data Gateway

An internal HTTP gateway that lets authenticated clients run **pre-approved,
reviewed SQL queries** against internal databases and receive results as
time-limited JSON file links. Plain PHP 8.1+, no Composer, no frameworks,
native DB drivers only (`mysqli` / `sqlsrv_*` / `pg_*`). Built for
Windows + XAMPP; every timestamp is Tehran local time (`+03:30`, no DST).

Core model:

- **Request** — an admin-approved query (`code`, SQL, target connection,
  limits). Clients never send SQL; they call a request by its code.
- **Call** — one client's request submission. Each call gets its own
  download token; several calls may share one execution.
- **Execution** — one actual run of the request's SQL on the target DB.

Two response modes:

- **Delayed** — a background worker runs the query on schedule and the
  client polls `/api/v1/calls/{id}` or receives a webhook when the result
  file is ready.
- **Instant** — the query runs synchronously during the submit call; the
  rows come back inline in the JSON response AND the run is stored as a
  downloadable result file exactly like a delayed execution, so the
  response also carries a download `link` (and `?download=1` skips the
  inline copy and returns the file directly instead).

---

## 1. Requirements

- Windows 10/11 with **XAMPP** (PHP 8.1+, Apache, MariaDB 10.4+).
- PHP extensions (php.ini, then restart Apache):

  | Extension        | Needed for                                      |
  |------------------|-------------------------------------------------|
  | `mysqli`         | metadata DB (always)                            |
  | `curl`           | webhook delivery, smoke tests                   |
  | `openssl`        | AES-256-GCM encryption (passwords, call tokens) |
  | `mbstring`       | UTF-8 handling                                  |
  | `sqlsrv` + `pdo_sqlsrv`* | SQL Server target connections only      |
  | `pgsql`          | PostgreSQL target connections only              |

  \* The SQL Server driver on Windows also needs the Microsoft ODBC driver
  17/18 for SQL Server installed.

- **`memory_limit` >= 256M** in `php.ini`. Instant mode buffers result rows in
  memory to build the inline JSON response; the gateway caps that payload at
  `limits.instant.max_result_bytes` (default 16 MiB, 413 `result_too_large`
  past it) and caps streamed result files at `limits.max_result_bytes`
  (default 512 MiB), but the process still needs headroom above the inline cap.
  `php cli\check.php` warns when `memory_limit` is below 256M.

Verify everything with:

```
php cli\check.php
```

## 2. Install

Assumes the project lives at `C:\path\to\data-gateway` (adjust below) and
XAMPP at `C:\xampp`.

1. **Create the metadata database**

   ```
   C:\xampp\mysql\bin\mysql.exe -u root -p < sql\create_database.sql
   C:\xampp\mysql\bin\mysql.exe -u root -p data_gateway < sql\schema.sql
   ```

   (the first file creates the `data_gateway` database, the second loads the
   tables; or paste both into phpMyAdmin's SQL tab, in this order).

2. **Configure** `config/config.php`

   - `security.master_key` — generate once, 64 hex chars:
     `php -r "echo bin2hex(random_bytes(32));"`
     This key encrypts connection passwords and per-call download tokens.
     Changing it later makes every stored secret unreadable.
   - `metadata_db.*` — XAMPP defaults work (`root`, empty password).
     **Recommended for anything real:** create a dedicated account instead
     of root — the gateway only needs DML on its own schema:
     `CREATE USER 'gateway'@'localhost' IDENTIFIED BY 'strong-password';`
     `GRANT SELECT,INSERT,UPDATE,DELETE,LOCK TABLES ON data_gateway.* TO 'gateway'@'localhost';`
     Give the **target** databases a read-only account
     (`GRANT SELECT ONLY`) and enter those credentials in the admin panel.
   - `app.base_url` — set this if the gateway is reached via a name the
     incoming `Host` header can't provide (links are built from it).

3. **Point Apache at `public/`** — the project root itself must never be
   the document root. In `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

   ```
   <VirtualHost *:80>
       DocumentRoot "C:/path/to/data-gateway/public"
       ServerName gw.internal
       <Directory "C:/path/to/data-gateway/public">
           Require all granted
       </Directory>
   </VirtualHost>
   ```

   Add `127.0.0.1 gw.internal` to `C:\Windows\System32\drivers\etc\hosts`.
   The root `.htaccess` denies everything as a safety net; `storage/`
   (result files) sits outside the web root and is only reachable through
   the authenticated API.

4. **Create the first admin**

   ```
   php cli\create_admin.php --username=admin --password=********
   ```

   (add `--reset-lockout` to unlock a locked-out account). Then open
   `http://gw.internal/admin` and log in.

5. **In the admin panel**

   - *Connections*: register each target DB (type, host, credentials —
     stored AES-256-GCM-encrypted, never displayed again). Use the
     **Test** button to validate.
   - *Requests*: create each approved query — unique `code` (the URL
     fragment clients call), SQL text, connection, mode (delayed/instant),
     TTL, timeout, max rows. Test-run it before activating.
   - *Clients*: create an API client per consumer. The API key is shown
     **exactly once**; only its SHA-256 hash is stored. Grant per-request
     permissions on the client page.
   - *Logs / Audit*: request log with filters (client, request, event,
     mode, date range) and the admin audit trail with field-level
     before/after diffs.

## 3. Scheduled tasks (Windows Task Scheduler)

The worker, cleanup, and webhook dispatcher are plain CLI scripts. The
easiest way to register all three is the bundled PowerShell script; it
derives the project root from its own location and takes the PHP path as a
parameter. Run it from an **elevated** PowerShell:

```
powershell -ExecutionPolicy Bypass -File deployment\register_tasks.ps1 -PhpExe "C:\xampp\php\php.exe"
```

The tasks run as `NT AUTHORITY\SYSTEM` by default (`-RunAs System`): when a
task fires every minute **no console window appears** (SYSTEM runs in
session 0, which has no visible desktop), and the tasks keep running
whether a user is logged on or not. The alternative is `-RunAs
CurrentUser`, which registers the tasks under the current account but fires
them via `deployment\run_hidden.vbs` (wscript, GUI subsystem = silent window
style 0) so no window flashes either — however it only works while that user
is logged on.

Or register them by hand (adjust paths):

```
schtasks /Create /F /RU SYSTEM /TN "DataGateway-worker"   /SC MINUTE /MO 1 /TR "\"C:\xampp\php\php.exe\" \"C:\path\to\data-gateway\cli\worker_pool.php\""
schtasks /Create /F /RU SYSTEM /TN "DataGateway-cleanup"  /SC MINUTE /MO 5 /TR "\"C:\xampp\php\php.exe\" \"C:\path\to\data-gateway\cli\cleanup.php\""
schtasks /Create /F /RU SYSTEM /TN "DataGateway-webhooks" /SC MINUTE /MO 1 /TR "\"C:\xampp\php\php.exe\" \"C:\path\to\data-gateway\cli\webhooks.php\""
```

- **worker** (every 1 min) — `cli\worker_pool.php`: runs the global sweeps once,
  then starts one `cli\worker.php --connection=ID` child per active target
  connection (at most `worker.pool_max_children`, default 8, at a time), waits
  for them, and drains due webhook deliveries once. Because each child is fenced
  by its own `gw_cli_worker:<id>` / `storage\worker-<id>.lock`, work on
  connection A no longer waits behind a 30-minute query on connection B.
  `php cli\worker.php` on its own is still the **manual single pass** over every
  connection (no budget, no fan-out) — useful for one-off runs, not for the
  schedule. `worker.pass_budget_seconds` (default 50) bounds each pool child's
  wall time so a long queue cannot make a pass outlive its own schedule; what a
  child does not reach stays `scheduled` for the next minute. Note that
  executions **within** one connection still run one after another — a slow
  query only queues behind its own connection.
- **cleanup** (every 5 min) — expires TTL'd results, deletes their files,
  removes failed leftovers, recovers instant executions that died mid-query
  in the web server, and deletes orphaned result/cache files whose
  `executions` row no longer exists. It also applies the optional row
  retention below.
- **row retention** (`cleanup.retention.*`, all `0` = keep forever by
  default, so upgrading changes nothing until you opt in):
  `request_logs_days`, `calls_days`, `executions_days`,
  `webhook_deliveries_days`, `audit_days`. Recommended production values are
  **90 / 365 / 365 / 30 / 0** (the audit log is compliance history). Pruning
  runs in batches of 1000 rows, at most 20 batches per table per pass, and is
  referential-safe: a delivery is deleted before the call that owns it, a call
  with a **pending** delivery is never deleted, an execution is kept while any
  call still points at it, `scheduled`/`running` executions are never pruned,
  and a result file is removed before the row that names it. `cleanup.log_retention_days`
  still works as an alias for `request_logs_days`.
- The worker also checks `limits.min_free_disk_bytes` (default 2 GiB, `0`
  disables it) before opening a new result file. Below the floor a delayed
  execution fails immediately with `error_code = storage_low` and no file is
  written; an instant run still answers inline (that is its contract) and only
  loses the downloadable copy. `php cli\check.php` warns when the volume is
  under the floor or when pending webhook deliveries pass
  `cleanup.pending_delivery_warn_count` (default 100).
- **webhooks** (optional, every 1 min) — retries webhook deliveries between
  worker passes so clients get notified faster than once a minute. The pool
  already drains the queue at the end of each pass; this task just closes the
  gap. Delivery is fenced with `GET_LOCK`, so running pool and webhooks
  concurrently is safe.

Delete a task with `schtasks /Delete /TN "DataGateway-webhooks" /F`.

Every task is registered with a **hard time limit** — worker 60 min,
cleanup 30 min, webhooks 15 min. If a run wedges (hung DB connection,
stuck socket), Task Scheduler force-terminates it instead of letting an
orphaned PHP process hold locks forever; the next scheduled fire simply
claims the work again. Re-running `register_tasks.ps1` re-registers the
tasks, so it also applies new limits to an existing installation.

## 4. API

All endpoints live under `/api/v1` and authenticate with
`Authorization: Bearer <api key>`. The client must also have an explicit
permission grant for the request code.

### Delayed mode

```
:: submit — one call per submit; identical concurrent submits share one execution
curl -X POST -H "Authorization: Bearer CLIENT_KEY" http://gw.internal/api/v1/request/sales.daily
-> 200 {"call_id":"...","status":"scheduled","scheduled_at":"...","ready_at":null,"expires_at":null,"link":null}

:: poll — returns the download link once the execution is ready
curl -H "Authorization: Bearer CLIENT_KEY" http://gw.internal/api/v1/calls/CALL_ID
-> 200 {"call_id":"...","request_code":"sales.daily","status":"ready",
        "row_count":123,"ready_at":"...","expires_at":"...","link":"http://gw.internal/api/v1/results/TOKEN",
        "error":null}

:: poll a failed call — `error` is the ONLY failure detail clients get; the
:: raw driver text stays in the admin-visible executions.error_message
-> 200 {"call_id":"...","request_code":"sales.daily","status":"failed",
        "row_count":null,"ready_at":null,"expires_at":null,"link":null,
        "error":{"code":"timeout","message":"The query did not finish within its timeout and was stopped before it produced a result."}}

:: download — stream the result file (until expires_at). Downloads carry
:: Content-Disposition: attachment; filename="result-{code}-{execution_id}.json"
curl -H "Authorization: Bearer CLIENT_KEY" LINK -o result.json
```

Status values: `scheduled` → `running` → `ready` (then `expired`), or
`failed`. Every poll response carries `error`: `null` unless the run failed,
then `{"code":"timeout"|"execution_failed"|"query_rejected"|"result_too_large"|"storage_low",
"message":"<fixed wording>"}` (treat an unknown code as `execution_failed`;
the code set may grow, the wording never contains SQL, host, user or driver
output). `storage_low` means the gateway volume is under
`limits.min_free_disk_bytes` — it is the gateway's problem to fix, retrying
the same query immediately will not help. Error *responses* carry `{"error":{"code":"...","message":"..."}}`
— codes include `unauthorized` (401), `forbidden` (403), `not_found`
(404), `method_not_allowed` (405), `query_rejected` (502, saved SQL is not
read-only), `execution_failed` (502), `result_unavailable` (502, the stored
result file is missing or unreadable — an empty 200 is never returned),
`result_too_large` (413, instant result past
`limits.instant.max_result_bytes`), `timeout`
(504), `rate_limited` (429, with `Retry-After`), and `410 Gone` for an
expired link.

### Instant mode

```
:: submit — the result comes back inline (backward compatible) AND a
::         downloadable file is stored; the response now includes `link`
curl -X POST -H "Authorization: Bearer CLIENT_KEY" http://gw.internal/api/v1/request/sales.lookup
-> 200 {"call_id":"...","mode":"instant","status":"ready","executed_at":"...",
        "row_count":2,"truncated":false,
        "link":"http://gw.internal/api/v1/results/TOKEN",
        "data":[{"col":"val"},...]}

:: one-call file delivery — add ?download=1 to the SAME POST and the body is
:: the result file itself (Content-Disposition: attachment, same {"data":..,
:: "meta":{..}} shape as delayed result files); no inline envelope is sent
curl -X POST -H "Authorization: Bearer CLIENT_KEY" -o result.json \
     "http://gw.internal/api/v1/request/sales.lookup?download=1"

:: or download later with the link from the response / status endpoint
curl -H "Authorization: Bearer CLIENT_KEY" LINK -o result.json
```

Every successful instant run also writes its result file
(`storage/results/{execution_id}.json`) and the call receives a download
token, exactly like delayed executions: the status endpoint (`GET
/api/v1/calls/CALL_ID`) returns the link once ready, and the file stays
downloadable until cleanup expires it (`limits.instant.result_ttl_minutes`,
default 60 minutes — instant requests ignore requests.ttl_minutes by
design). If the request has `instant_cache_seconds > 0`, an identical
re-submit within the window replays the cached copy instantly
(`instant_cache_hit` logged, with its own link; `?download=1` also works). If
the target connection is at its concurrency cap the call gets
`429 rate_limited` + `Retry-After` instead of queuing.

### Per-client submit throttle

`limits.client_submit_max` caps how many submissions one client may start per
`limits.client_submit_window_seconds`. The counter lives in the
`client_rate_limits` table (one row per client: `window_start`, `hits`) and is
bumped by a single `INSERT ... ON DUPLICATE KEY UPDATE`, so simultaneous
submits cannot all read a stale count and slip past the cap together, and
pruning `request_logs` cannot silently loosen it. Once the cap is reached,
further submits return `429 rate_limited` with `Retry-After` set to the seconds
left in the current window (not the whole window) and are logged as
`rate_limited`. The window is **fixed**, not sliding: `hits` resets to 1 on the
first submit after `window_start + window_seconds`.

This is independent of the per-connection concurrency cap above. **Currently
enabled at 60 submissions per 60s window** in `config/config.php`; set
`client_submit_max` (or the `GATEWAY_CLIENT_SUBMIT_MAX` environment override)
to `0` to disable. Upgrading an existing installation requires the
`client_rate_limits` table from `sql/schema.sql` (see the upgrade block at the
end of that file) before deploying this code.

## 5. Webhooks

If a client has a webhook URL + secret configured, every delayed call gets
a signed POST when its execution settles — `ready` **or `failed`** (and on
retry after delivery failures). Headers:

- `Content-Type: application/json`
- `X-Gateway-Signature: sha256=<hex>` — HMAC-SHA256 of
  `<unix timestamp>.<exact raw body bytes>`, keyed with the client's
  webhook secret
- `X-Gateway-Timestamp: <unix epoch>` — refuse timestamps older than
  ~5 minutes (replay protection)
- `X-Gateway-Call: <call_id>`
- `X-Gateway-Attempt: <n>` (1-based)

Payload of a ready result:

```json
{
  "call_id": "...",
  "request_code": "sales.daily",
  "status": "ready",
  "link": "http://gw.internal/api/v1/results/TOKEN",
  "expires_at": "2026-10-17T15:00:00+03:30",
  "row_count": 123
}
```

Payload of a failed run (same envelope, no link, plus `error`):

```json
{
  "call_id": "...",
  "request_code": "sales.daily",
  "status": "failed",
  "link": null,
  "error": {
    "code": "timeout",
    "message": "The query did not finish within its timeout and was stopped before it produced a result."
  }
}
```

`error.code` is one of `timeout`, `execution_failed`, `query_rejected`,
`result_too_large`, `storage_low` (treat an unknown code as
`execution_failed`), and
`error.message` is fixed gateway wording — never SQL, a host, a user or raw
driver output. A failed run is final: the gateway does not retry the query,
so resubmit (or poll `GET /api/v1/calls/{call_id}`, which now reports the same
`error`). Receivers written before failure notifications only ever saw
`status: "ready"`; they must branch on `status` instead of assuming it.

`link` is the absolute download URL and is `null` when the gateway cannot
build one (no `GATEWAY_BASE_URL` configured, or the call predates download
tokens). Receivers must tolerate a missing link and poll
`GET /api/v1/calls/{call_id}` instead — `cli/check.php` reports the missing
base URL as a FAIL so the gap is visible to the operator.

Receiver-side verification (PHP example):

```php
$raw  = file_get_contents('php://input');
$sig  = $_SERVER['HTTP_X_GATEWAY_SIGNATURE'] ?? '';
$ts   = $_SERVER['HTTP_X_GATEWAY_TIMESTAMP'] ?? '';
if ($ts === '' || abs(time() - (int)$ts) > 300) { http_response_code(401); exit; }
$good = 'sha256=' . hash_hmac('sha256', $ts . '.' . $raw, $WEBHOOK_SECRET);
if (!hash_equals($good, $sig)) { http_response_code(401); exit; }

// Only decode after the bytes are verified, and branch on the status:
// a failed run has no link to download.
$event = json_decode($raw, true);
if (($event['status'] ?? '') === 'failed') {
    notify_failure($event['call_id'], $event['error']['code'] ?? 'execution_failed');
} else {
    download($event['call_id'], $event['link']); // link may be null -> poll instead
}
http_response_code(202);
```

Always read the body from `php://input` — re-serializing a decoded payload
changes the bytes and breaks the signature. Reply with any 2xx to mark the
delivery sent; anything else is retried with backoff
(1, 2, 5, 10, 30 min — 5 retries after the initial send, then the delivery
is marked `exhausted`).

Note on links: each call's download token is stored twice — a SHA-256 hash
(for download authentication) and an AES-256-GCM-encrypted copy (so the
worker can rebuild the webhook `link` even for calls that attached to a
ready execution). Neither form exposes the raw token at rest.

### Which URLs the gateway will post to (SSRF policy)

The receiver URL is typed in by an admin and the gateway then POSTs to it
**from inside the network**, so an unchecked URL turns the gateway into a proxy
against its own host — `http://169.254.169.254/...` would fetch cloud instance
credentials, and `http://127.0.0.1:<port>` would reach whatever the server has
listening. `Services\WebhookUrl` therefore validates every URL **when the
client is saved and again before every send**:

| Knob (`config/config.php` → `webhook`) | Env override | Default | Meaning |
| --- | --- | --- | --- |
| `allow_http` | `GATEWAY_WEBHOOK_ALLOW_HTTP=1` | `false` | https only unless on |
| `allow_loopback` | `GATEWAY_WEBHOOK_ALLOW_LOOPBACK=1` | `false` | `127.0.0.0/8`, `::1` |
| `allow_private_hosts` | `GATEWAY_WEBHOOK_ALLOW_PRIVATE_HOSTS=0` | `true` | RFC1918 / `fc00::/7` |
| `allowed_hosts` | `GATEWAY_WEBHOOK_ALLOWED_HOSTS=a.test,b.test` | `[]` | exact lowercase host names, no wildcards |

A host name is resolved and **every** address it resolves to must pass; the
address that was validated is then pinned for the send with
`CURLOPT_RESOLVE`, so a rebinding DNS answer cannot move the connection after
the check. Only http(s) can be spoken (`CURLOPT_PROTOCOLS`), redirects are
never followed (following one would re-resolve and re-scheme outside these
rules), and the response body is discarded after 64 KiB because only the
status code is used.

**Tradeoff, on purpose:** `allow_private_hosts` defaults to `true` because this
deployment's receivers live on the LAN. Set
`GATEWAY_WEBHOOK_ALLOW_PRIVATE_HOSTS=0` if the receivers are publicly reachable
— then only public addresses are accepted. Loopback stays off by default:
enable it only on a machine where the receiver really is local (the test suites
do, to exercise delivery). Link-local (`169.254.0.0/16`, which includes every
cloud metadata endpoint), carrier-grade NAT, multicast and other reserved
ranges are refused **whatever these knobs say**.

A delivery whose stored URL fails the policy is not retried: the row goes to
`state = 'exhausted'` with `last_error = 'blocked: <reason>'`, the reason is
logged as a `webhook_failed` entry, and curl is never called. `cli/check.php`
prints the active policy and lists the configured client URLs that the gateway
would refuse today, so fix them in the admin UI (or relax a knob) before the
notifications are lost.

## 6. Character encoding (non-ASCII data)

- SQL Server connections are opened with `'CharacterSet' => 'UTF-8'` forced
  (`src/Db/SqlServerDriver.php`). Without it the driver converts VARCHAR
  data and column aliases through the client codepage, which turns text
  like Persian into `?` characters plus invalid bytes — corrupting both the
  downloadable result files and the inline responses.
- The JSON layer assumes nothing about data quality:
  `Core\Str::json()` and `json_out()` always encode with
  `JSON_INVALID_UTF8_SUBSTITUTE`, so a malformed value can never fail an
  encoding, blank out a response, or produce a 500 — it is emitted with
  replacement characters instead.
- The result-file writer (`src/Worker/ResultWriter.php`) goes further for
  file mode: it repairs invalid UTF-8 in values AND in column-name keys
  (mojibake'd SQL aliases become repairable or positional `col_N` names)
  before falling back to substitution. `meta.encoding_sanitized` in a
  result file tells you a repair happened; with the SQL Server charset fix
  it should never appear again.

## 7. Cache rules (delayed mode)

1. A submit while an execution for the request is `scheduled`/`running`
   attaches to it — no duplicate run; the new call gets its own token.
2. A submit while a `ready`, unexpired execution exists attaches to it and
   the response carries a fresh download link immediately.
3. Otherwise a new execution is scheduled.

Instant results follow the same spirit via the time-windowed cache.

## 8. Upgrading an existing installation

The statements below are also collected at the end of `sql/schema.sql`.
`php cli\check.php` fails on any of them that is missing, so run it after
upgrading the code.

If the DB was created before the webhook feature, add the encrypted-token
column once:

```sql
ALTER TABLE `calls`
  ADD COLUMN `token_enc` TEXT NULL DEFAULT NULL
    COMMENT 'AES-256-GCM copy of the per-call token; lets webhooks rebuild the link'
    AFTER `token_hash`;
```

Old calls keep `token_enc = NULL`: they simply get no `link` in webhook
payloads; everything else keeps working.

If the DB was created before the per-client submit throttle, create its
counter table **before** deploying the new code (the throttle has no
fallback):

```sql
CREATE TABLE IF NOT EXISTS `client_rate_limits` (
  `client_id`     INT UNSIGNED NOT NULL,
  `window_start`  DATETIME     NOT NULL,
  `hits`          INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `client_rate_limits`
  ADD CONSTRAINT `fk_crl_client` FOREIGN KEY (`client_id`)
    REFERENCES `clients` (`id`) ON DELETE CASCADE;
```

If the DB was created before failure webhooks, add the sanitized failure
code:

```sql
ALTER TABLE `executions`
  ADD COLUMN `error_code` VARCHAR(32) NULL DEFAULT NULL AFTER `error_message`;
```

Existing rows keep `error_code = NULL` and are reported as
`execution_failed` — the generic code — so nothing breaks if the ALTER
lags behind the deploy.

**Retention + free-space floor (2026-10):** no schema change. Every
`cleanup.retention.*` window defaults to `0`, so an upgrade deletes nothing
until an admin turns a window on (back the metadata DB up first — pruning a
table with history is not reversible). The only new default that is active out
of the box is `limits.min_free_disk_bytes` (2 GiB): a volume with less free
space than that makes new delayed runs fail with `storage_low`. Lower it (or
set `0`) if the storage volume is small but intentionally nearly full.

**Instant result files (2026-10):** no schema change is needed — instant
runs reuse the existing `calls`/`executions` token columns. Instant
responses gain a `link`, `?download=1` works out of the box, and the file
lifetime is `limits.instant.result_ttl_minutes` (default 60 minutes, swept
to `expired` by cleanup). Existing consumers that read the inline `data`
stay compatible unchanged.

**Breaking webhook change (2026-10):** signatures are now computed over
`"<unix timestamp>.<raw body>"` and every delivery carries an
`X-Gateway-Timestamp` header. If you built a receiver against the old
`HMAC(body)` scheme it must be updated: verify against
`$timestamp . '.' . $rawBody` and reject requests whose timestamp is older
than ~5 minutes (replay protection). Signatures from the old scheme will
no longer verify.

**Webhook URL policy (2026-10):** no schema change, but the SSRF gate of §5
applies to URLs that are already stored. A `webhook_url` the policy refuses —
plain `http://`, or a loopback address — is no longer posted to: its pending
deliveries go to `exhausted` with `blocked: <reason>` in `last_error`. LAN
receivers keep working unchanged (`webhook.allow_private_hosts` defaults to
`true`). Check before deploying — `php cli\check.php` lists the configured
clients whose URL would be refused — and relax with
`GATEWAY_WEBHOOK_ALLOW_HTTP=1` / `GATEWAY_WEBHOOK_ALLOW_LOOPBACK=1` in
`config/.env` if the deployment really needs one of those receivers.

## 9. Tests

**Safe path — `tests\run_all.ps1`** (PowerShell, no elevation needed): builds
a throwaway environment itself — random `data_gateway_test_<random>` schema
(imported from `sql\schema.sql`), throwaway `storage\test-<random>` dir — and
runs everything against it: `php -l` on every file, `smoke_db`,
`encryption_test --db`, `links_test`, `filesystem_test`, `sqlguard_test`,
`readonly_driver_test`, `pool_test`, `resultwriter_test`, `throttle_test`,
then the four end-to-end smokes (`api_smoke`, `admin_smoke`, `worker_smoke`,
`webhook_smoke`), `retention_test` (row retention pruning + the free-space
floor, including a subprocess run with `GATEWAY_MIN_FREE_DISK_BYTES` raised
above the real free space), `webhook_url_test` (the webhook SSRF policy of §5,
one child process per policy) plus `stream_memory_test`, each refusing to run
unless
`GATEWAY_TEST_MODE=1` and
the metadata DB name contains `_test` (they `exit(2)` otherwise). The DB and
storage dir are always dropped in a `finally` block, and every fixture is
run-scoped, so a green run touches nothing outside its sandbox:

```
powershell -ExecutionPolicy Bypass -File tests\run_all.ps1
```

Running the smoke scripts **directly** against a real `data_gateway` is no
longer supported — the guards above refuse it on purpose.

## 10. Production go-live checklist

1. **Metadata DB user.** Do not run as `root`. Create the dedicated user with
   `sql/create_user.sql` (SELECT/INSERT/UPDATE/DELETE/LOCK TABLES on
   `data_gateway` only) and set its password as `GATEWAY_DB_PASSWORD` in
   `config/.env`. Give each target connection its own account too, and make
   those accounts **read-only** on the target databases.
2. **Apache DocumentRoot MUST be `public/`.** Nothing above `public/` may be
   web-reachable. Keep the root `.htaccess` safety net active by allowing
   overrides in the vhost (`AllowOverride All`). Verify from outside:
   `curl -i http://<host>/config/.env` must return **403 or 404** — if it
   ever returns the file contents, the DocumentRoot or `.htaccess` is wrong;
   fix before going live.
3. **Set `GATEWAY_BASE_URL`** in `config/.env` to the real public URL
   (e.g. `https://gw.internal`). It must be a valid URL and use `https` in
   production — `Links::base()` fails fast (and `cli/check.php` reports
   FAIL) while it is empty or non-https, instead of silently building
   download links from the incoming `Host` header (a cache-poisoning /
   phishing risk). Only with `GATEWAY_DEBUG=1` may it stay empty (links
   then fall back to the Host header, for development machines).
4. **HTTPS/TLS: enabled (decided).** This deployment serves over TLS. The
   session cookie `secure` flag and the download-link scheme auto-detect from
   `HTTPS`/the base URL scheme, so once Apache terminates TLS the app sets
   secure cookies and builds `https://` links with no code change. When you set
   `GATEWAY_BASE_URL` (item 3), use the `https://` scheme so links and secure
   cookies stay correct even behind a TLS-terminating proxy. XAMPP quick setup:
   run `C:\xampp\apache\makecert.bat` to generate a self-signed cert, enable
   `mod_ssl`/`mod_socache_shmcb` in `httpd.conf`, uncomment the `LoadModule
   ssl_module` + `Include conf/extra/httpd-ssl.conf` lines, then restart Apache
   and browse `https://localhost` to accept the warning.
5. **Register the scheduled tasks:** run `deployment\register_tasks.ps1` from
   an elevated PowerShell (§3). The default `-RunAs System` registration is
   invisible (no console flash when tasks fire every minute) and keeps
   running whether a user is logged on or not — exactly what a server wants.
   Each task carries a hard time limit (worker 60 / cleanup 30 / webhooks 15
   minutes); re-run the script to apply updated limits to existing tasks.
6. **Decide the history retention.** Every `cleanup.retention.*` window ships
   at `0` (keep forever), so nothing is pruned until you set it. Pick the
   numbers with whoever owns the metadata DB (§3 recommends
   `90 / 365 / 365 / 30 / 0`), put them in `config/.env` as
   `GATEWAY_RETENTION_*_DAYS`, and confirm the next cleanup pass reports
   `pruned …` counts on `php cli\cleanup.php`. Back the DB up before the
   first real prune.
7. **Final gate:** `php cli\check.php` reports ALL CHECKS PASSED (this
   includes a set, valid, https `GATEWAY_BASE_URL` — the check FAILS while
   it is missing) **and** all four smoke tests pass.

## 11. Filesystem ACLs (Windows)

The gateway stores its own code, `config/.env` (secrets), and the writable
`storage/` tree under one directory — exactly the layout that makes a PHP
runtime compromise expensive when permissions are left at XAMPP defaults.
`deployment\harden_acl.ps1` enforces the least-privilege split (idempotent,
run it again after any reinstall or move):

- **Administrators + SYSTEM** — Full Control on the whole tree.
- **PHP identity** (`-WebUser`, default `NT AUTHORITY\SYSTEM` as XAMPP runs
  Apache) — Read&Execute everywhere, including `config/` so `.env` stays
  readable, but **no write** outside `storage/`; `storage/` gets Modify for
  result files, cache and lock files. A rogue worker cannot rewrite its own
  code, `.env`, or the CLI/deployment scripts.
- **Everyone else** — `BUILTIN\Users` and `Authenticated Users` grants are
  stripped; nothing re-inherits broad rights from the volume root.

Run from an **elevated** PowerShell (it refuses otherwise):

```
powershell -ExecutionPolicy Bypass -File deployment\harden_acl.ps1
```

Pass `-WebUser "IIS AppPool\gateway"` (or your dedicated service account)
when PHP does not run as SYSTEM. Afterwards verify the app end to end: a
read-only code/config tree must not affect submissions, downloads, worker
passes, or webhook delivery.

## 12. Read-only SQL policy

Stored `sql_text` is executed with the **target** connection's credentials
whenever any permitted client submits its code, so the gateway only reads.
Three layers enforce that:

1. **`Services\SqlGuard`** — a quote/comment-aware scanner, run when the
   request is **saved** (admin validation error) and again on **every
   execution** (so rows saved before the policy existed are still covered).
   Allowed: one statement starting with `SELECT`, `WITH … SELECT`, `SHOW`,
   `EXPLAIN`, `DESCRIBE`/`DESC` (optionally qualified with `FORMAT=…` or
   `(…)`), plus a table name after `DESCRIBE`/`DESC`. Rejected: any
   DML/DDL/DCL/session keyword, a second statement (a `;` outside quotes and
   comments), `SELECT … INTO OUTFILE/DUMPFILE`, `FOR UPDATE`,
   `LOCK IN SHARE MODE` — and `EXPLAIN ANALYZE`, which on MySQL 8+ and
   PostgreSQL **executes** the wrapped statement. The body of an allowed
   `EXPLAIN`/`DESCRIBE` is re-scanned, so `EXPLAIN DELETE FROM t` cannot pass.
   Bypassing it deliberately would need a keyword hidden in a quoted
   identifier (which is legal SQL and inert) or a parser-level trick.
2. **Driver session guards** — defence in depth if the scanner is ever wrong:
   - MySQL/MariaDB: the session opens with `START TRANSACTION READ ONLY` and
     closes with `ROLLBACK`. This blocks DML on transactional tables. It does
     **not** block DDL (DDL implicitly commits outside the transaction) — that
     stays layer 1's job.
   - PostgreSQL: `SELECT`/`WITH` run inside `BEGIN READ ONLY` behind the
     cursor; the non-cursor path only accepts `EXPLAIN`/`SHOW`, everything
     else is refused before it reaches libpq.
   - SQL Server: **prefix guard only.** There is no session-level read-only
     mode for `sqlsrv_*`, so a bypass of layer 1 is not caught here. Use a
     login that has `SELECT` only (ideally a read-only database user) — that
     grant, not this software, is the real boundary.
3. **Target account grants** — create the target logins you enter in the admin
   panel as read-only (`GRANT SELECT ONLY`). Layer 3 is the one that holds even
   if both code layers fail; it is also the only one that protects against a
   compromised gateway host.

A rejected statement is not a query failure: the API answers
`502 query_rejected`, the admin sees a validation error at save time, and the
delayed execution is marked failed. In all three cases nothing was sent to the
target database.

