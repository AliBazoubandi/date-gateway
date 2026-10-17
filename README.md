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
- **Instant** — the query runs synchronously during the submit call and the
  rows come back in the JSON response (optionally cached for N seconds).

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
   C:\xampp\mysql\bin\mysql.exe -u root -p < sql\schema.sql
   ```

   (or paste `sql/schema.sql` into phpMyAdmin's SQL tab).

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

The worker, cleanup, and webhook dispatcher are plain CLI scripts. Register
them once from an elevated prompt (adjust paths):

```
schtasks /Create /F /TN "DataGateway worker"  /SC MINUTE /MO 1 /TR "\"C:\xampp\php\php.exe\" \"C:\path\to\data-gateway\cli\worker.php\""
schtasks /Create /F /TN "DataGateway cleanup" /SC MINUTE /MO 5 /TR "\"C:\xampp\php\php.exe\" \"C:\path\to\data-gateway\cli\cleanup.php\""
schtasks /Create /F /TN "DataGateway webhooks" /SC MINUTE /MO 1 /TR "\"C:\xampp\php\php.exe\" \"C:\path\to\data-gateway\cli\webhooks.php\""
```

- **worker** (every 1 min) — claims due delayed executions, runs them on
  the target DB, writes result files, then drains due webhook deliveries.
- **cleanup** (every 5 min) — expires TTL'd results, deletes their files,
  removes failed leftovers and stale instant state.
- **webhooks** (optional, every 1 min) — retries webhook deliveries between
  worker passes so clients get notified faster than once a minute. The
  worker already drains the queue at the end of each pass; this task just
  closes the gap. Delivery is fenced with `GET_LOCK`, so running worker and
  webhooks concurrently is safe.

Delete a task with `schtasks /Delete /TN "DataGateway webhooks" /F`.

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

:: download — stream the result file (until expires_at)
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
curl -X POST -H "Authorization: Bearer CLIENT_KEY" http://gw.internal/api/v1/request/sales.lookup
-> 200 {"call_id":"...","mode":"instant","status":"ready","executed_at":"...",
        "row_count":2,"truncated":false,"data":[{"col":"val"},...]}
```

If the request has `instant_cache_seconds > 0`, an identical re-submit
within the window replays the cached copy instantly (`instant_cache_hit`
logged). If the target connection is at its concurrency cap the call gets
`429 rate_limited` + `Retry-After` instead of queuing.

## 5. Webhooks

If a client has a webhook URL + secret configured, every delayed call gets
a signed POST when its execution turns `ready` (and on retry after
failures). Headers:

- `Content-Type: application/json`
- `X-Gateway-Signature: sha256=<hex>` — HMAC-SHA256 of the exact raw body,
  keyed with the client's webhook secret
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

Receiver-side verification (PHP example):

```php
$raw  = file_get_contents('php://input');
$sig  = $_SERVER['HTTP_X_GATEWAY_SIGNATURE'] ?? '';
$good = 'sha256=' . hash_hmac('sha256', $raw, $WEBHOOK_SECRET);
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

## 6. Cache rules (delayed mode)

1. A submit while an execution for the request is `scheduled`/`running`
   attaches to it — no duplicate run; the new call gets its own token.
2. A submit while a `ready`, unexpired execution exists attaches to it and
   the response carries a fresh download link immediately.
3. Otherwise a new execution is scheduled.

Instant results follow the same spirit via the time-windowed cache.

## 7. Upgrading an existing installation

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

## 8. Tests

Run from the project root (needs a live MariaDB `data_gateway`; the smoke
tests create and remove their own fixtures marked `smoke` / `smk.` / `swk.` /
`whk.`):

```
php tests\api_smoke.php      REM full API surface over php -S (30 checks)
php tests\worker_smoke.php   REM real CLI worker + cleanup passes
php tests\webhook_smoke.php  REM real webhook dispatch against a local receiver
php tests\admin_smoke.php    REM admin panel flows over php -S with curl
```
