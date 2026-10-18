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
schtasks /Create /F /RU SYSTEM /TN "DataGateway-worker"   /SC MINUTE /MO 1 /TR "\"C:\xampp\php\php.exe\" \"C:\path\to\data-gateway\cli\worker.php\""
schtasks /Create /F /RU SYSTEM /TN "DataGateway-cleanup"  /SC MINUTE /MO 5 /TR "\"C:\xampp\php\php.exe\" \"C:\path\to\data-gateway\cli\cleanup.php\""
schtasks /Create /F /RU SYSTEM /TN "DataGateway-webhooks" /SC MINUTE /MO 1 /TR "\"C:\xampp\php\php.exe\" \"C:\path\to\data-gateway\cli\webhooks.php\""
```

- **worker** (every 1 min) — claims due delayed executions, runs them on
  the target DB, writes result files, then drains due webhook deliveries.
- **cleanup** (every 5 min) — expires TTL'd results, deletes their files,
  removes failed leftovers, recovers instant executions that died mid-query
  in the web server, and deletes orphaned result/cache files whose
  `executions` row no longer exists. With `cleanup.log_retention_days > 0`
  it also prunes `request_logs` older than that many days (batched); `0`
  (default) keeps the log forever.
- **webhooks** (optional, every 1 min) — retries webhook deliveries between
  worker passes so clients get notified faster than once a minute. The
  worker already drains the queue at the end of each pass; this task just
  closes the gap. Delivery is fenced with `GET_LOCK`, so running worker and
  webhooks concurrently is safe.

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
        "row_count":123,"ready_at":"...","expires_at":"...","link":"http://gw.internal/api/v1/results/TOKEN"}

:: download — stream the result file (until expires_at). Downloads carry
:: Content-Disposition: attachment; filename="result-{code}-{execution_id}.json"
curl -H "Authorization: Bearer CLIENT_KEY" LINK -o result.json
```

Status values: `scheduled` → `running` → `ready` (then `expired`), or
`failed`. Error responses carry `{"error":{"code":"...","message":"..."}}`
— codes include `unauthorized` (401), `forbidden` (403), `not_found`
(404), `method_not_allowed` (405), `execution_failed` (502), `timeout`
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
`limits.client_submit_window_seconds`. It counts that client's own
`submitted`/`shared` log rows in a sliding window; once the cap is reached,
further submits return `429 rate_limited` + `Retry-After` and are logged as
`rate_limited`. This is independent of the per-connection concurrency cap
above. **Currently enabled at 60 submissions per 60s window** in
`config/config.php`; set `client_submit_max` back to `0` to disable.

## 5. Webhooks

If a client has a webhook URL + secret configured, every delayed call gets
a signed POST when its execution turns `ready` (and on retry after
failures). Headers:

- `Content-Type: application/json`
- `X-Gateway-Signature: sha256=<hex>` — HMAC-SHA256 of
  `<unix timestamp>.<exact raw body bytes>`, keyed with the client's
  webhook secret
- `X-Gateway-Timestamp: <unix epoch>` — refuse timestamps older than
  ~5 minutes (replay protection)
- `X-Gateway-Call: <call_id>`
- `X-Gateway-Attempt: <n>` (1-based)

Payload:

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

If the DB was created before the webhook feature, add the encrypted-token
column once (also documented at the end of `sql/schema.sql`):

```sql
ALTER TABLE `calls`
  ADD COLUMN `token_enc` TEXT NULL DEFAULT NULL
    COMMENT 'AES-256-GCM copy of the per-call token; lets webhooks rebuild the link'
    AFTER `token_hash`;
```

Old calls keep `token_enc = NULL`: they simply get no `link` in webhook
payloads; everything else keeps working. `php cli\check.php` flags the
missing column if you forget.

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

## 9. Tests

**Safe path — `tests\run_all.ps1`** (PowerShell, no elevation needed): builds
a throwaway environment itself — random `data_gateway_test_<random>` schema
(imported from `sql\schema.sql`), throwaway `storage\test-<random>` dir — and
runs everything against it: `php -l` on every file, `smoke_db`,
`encryption_test --db`, `links_test`, then the four end-to-end smokes
(`api_smoke`, `admin_smoke`, `worker_smoke`, `webhook_smoke`) plus
`stream_memory_test`, each refusing to run unless `GATEWAY_TEST_MODE=1` and
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
6. **Final gate:** `php cli\check.php` reports ALL CHECKS PASSED (this
   includes a set, valid, https `GATEWAY_BASE_URL` — the check FAILS while
   it is missing) **and** all four smoke tests pass.
