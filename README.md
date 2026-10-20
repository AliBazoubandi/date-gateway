# Data Gateway

An internal HTTP gateway that lets authenticated clients run **pre-approved,
reviewed SQL queries** against internal databases and receive results as
time-limited JSON file links. Plain PHP 8.1+, no frameworks, no Composer
packages to install, native DB drivers only (`mysqli` / `sqlsrv_*` / `pg_*`).
Built for Windows + XAMPP; every timestamp is Tehran local time (`+03:30`, no
DST).

`composer.json` exists as a declaration of the required PHP version and
extensions (plus a PSR-4 autoload map). It lists **no packages**, so
`composer install` is never part of installing or running this gateway:
`src/Core/bootstrap.php` registers an SPL autoloader that maps `Core\Db` →
`src/Core/Db.php` and works with no tooling at all. It prefers
`vendor/autoload.php` when a `composer dump-autoload` has been run, so there is
no dependency tree in `vendor/` - only the generated loader.

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
  | `sqlsrv`*            | SQL Server target connections only      |
  | `pgsql`          | PostgreSQL target connections only              |

  \* The SQL Server driver on Windows also needs the Microsoft ODBC driver
  17/18 for SQL Server installed.

- **`memory_limit` >= 256M** in `php.ini`. Instant mode buffers result rows in
  memory to build the inline JSON response; the gateway caps that payload at
  `limits.instant.max_result_bytes` (default 16 MiB, 413 `result_too_large`
  past it) and caps streamed result files at `limits.max_result_bytes`
  (default 512 MiB), but the process still needs headroom above the inline cap.
  `php cli\check.php` warns when `memory_limit` is below 256M.

**Startup failures.** An unusable `GATEWAY_MASTER_KEY` or an unreadable config
stops every request before routing. The CLI prints the reason to STDERR; a web
request writes it to the PHP error log (`error_log`) and answers a bare
`500 Configuration error.` — the reason never goes into the HTTP response.
(`tests\sapi_bootstrap_test.php` runs the failure through both SAPIs.)

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

2. **Create the metadata-DB account**

   ```
   C:\xampp\mysql\bin\mysql.exe -u root -p < sql\create_user.sql
   ```

   `sql/create_user.sql` creates `gateway` and grants it
   SELECT/INSERT/UPDATE/DELETE/LOCK TABLES on `data_gateway` only — no DDL, no
   other schemas. **The password in that file is a placeholder.** Generate your
   own (`php -r "echo bin2hex(random_bytes(24));"`), edit the file or run the
   two `CREATE USER` / `GRANT` statements by hand, and put the same value in
   `GATEWAY_DB_PASSWORD` below. Do not run the gateway as MySQL `root`.

   Give the **target** databases a read-only account (`GRANT SELECT ONLY`) and
   enter those credentials in the admin panel — they are stored encrypted, not
   in this file.

3. **Set the secrets in `config/.env`** — copy `config/.env.example`, which is
   annotated per key. `.env` is gitignored; `config/config.php` is committed and
   reads every secret through `\Core\Env::get()`, so nothing sensitive belongs
   there.

   - `GATEWAY_MASTER_KEY` — generate once, 64 hex chars:
     `php -r "echo bin2hex(random_bytes(32));"`
     This key encrypts connection passwords and per-call download tokens.
     Changing it later makes every stored secret unreadable, and the gateway
     refuses to start while it is empty or still the all-zeros placeholder
     (`Services\Encryption::assertUsableKey()`).
   - `GATEWAY_DB_USER` / `GATEWAY_DB_PASSWORD` — the account from step 2
     (`gateway` is the default username).
   - `GATEWAY_BASE_URL` — the URL clients reach the gateway at; download links
     and webhook payloads are built from it. Required in production
     (`cli\check.php` fails while it is empty); may stay empty in development
     with `GATEWAY_DEBUG=1`, where links fall back to the `Host` header.

   Everything else (`limits.*`, `worker.*`, `cleanup.*`, `storage.*`,
   `metadata_db` host/port/database) has committed defaults in
   `config/config.php` and only needs editing to change a policy.

   **The key that is already burned.** The first snapshot of this repository
   committed a real master key (`5122ef88…bf755f`) in `config/config.php` before
   secrets moved to `config/.env`. It is listed in
   `Services\Encryption::RETIRED_KEYS`, so bootstrap and every encrypt/decrypt
   refuse it and nothing new can be sealed with it — but the value still lives
   in this repository's git history and must be treated as public. Anyone who
   has that key cannot read passwords stored under the current one, and GCM
   authentication means a tampered or foreign blob fails decryption instead of
   returning garbage. **If a connection's password was saved while the retired
   key was configured, it can no longer be decrypted** — the admin panel will
   report `Decryption failed` when it is used; re-enter that password once.
   Removing the value means rewriting published history (every commit hash
   changes for everyone who cloned), which is a separate, deliberate decision
   and not part of this fix.

4. **Point Apache at `public/`** — the project root itself must never be
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

5. **Create the first admin**

   ```
   php cli\create_admin.php --username=admin --password=********
   ```

   (add `--reset-lockout` to unlock a locked-out account). Then open
   `http://gw.internal/admin` and log in. Running the same command again for an
   existing username replaces the password **and** signs out every admin
   session that was already open (§13).

6. **In the admin panel**

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
  At the end of every pass the pool also records the single-row `worker_state`
  heartbeat — start, finish, failure count — that `GET /health` reads (§4). The
  write is caught, so a missing `worker_state` table costs only the health
  answer, never the executions; and a manual `php cli\worker.php` does **not**
  record it, because one ad-hoc run is not evidence that the schedule is alive.
- **cleanup** (every 5 min) — expires TTL'd results, deletes their files,
  removes failed leftovers, recovers instant executions that died mid-query
  in the web server, and deletes orphaned result/cache files whose
  `executions` row no longer exists. Every file/row lookup it does is
  batched (one statement per 500 execution ids), so a pass over a full
  `storage\results` costs round trips per batch, not per result file. It
  also applies the optional row retention below.
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
permission grant for the request code. The one exception in this section is
`GET /health`: it is not under `/api/v1`, carries no key, and is documented at
the end because a monitor needs it before a client does.

### Delayed mode

```
:: submit — one call per submit; identical concurrent submits share one execution
:: add -H "Idempotency-Key: <per-attempt value>" and a retry answers from the
:: call that key already made instead of starting another submission
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
:: The call's own token from its link is the only download credential: a token
:: is never issued for an execution as such, so several calls sharing one
:: execution each download with their own link.
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
(404), `method_not_allowed` (405), `invalid_idempotency_key` (400, the
`Idempotency-Key` header is not 8-200 printable ASCII characters),
`query_rejected` (502, saved SQL is not
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
(`instant_cache_hit` logged, with its own link; `?download=1` also works). The
window is capped at that same file lifetime — a request asking for 24 hours of
cache against a 60-minute file gets 60 minutes, because the sweep deletes the
result a later hit would replay, and the admin form refuses a larger number.
To cache longer, raise `limits.instant.result_ttl_minutes`
(`GATEWAY_INSTANT_RESULT_TTL_MINUTES`). If the target connection is at its
concurrency cap the call gets `429 rate_limited` + `Retry-After` instead of
queuing. That cap is **one counter for both modes**: `connections.max_concurrency`
(or `limits.connection_concurrency`, default 2) counts every `running` execution
on the connection, delayed or instant, because the target database sees queries,
not gateway modes. A connection at its cap therefore defers delayed work until
the instant run finishes, and an instant submit is refused while the worker is
using the slots (2x the cap was possible when each mode counted itself).

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

### Idempotent submits (`Idempotency-Key`)

A submit may carry `Idempotency-Key: <8-200 printable ASCII characters>`. The
gateway stores only its SHA-256 (`calls.idempotency_key_hash`) and, for a key
this client already used **on this request**, answers the retry from the call
that key created: same `call_id`, same execution, same download link, and no
second run of the query. A too-short, too-long or non-printable key is rejected
with `400 invalid_idempotency_key` and nothing is submitted.

What a retry answers, by the state of the original run:

| original run | the keyed retry returns |
|---|---|
| delayed, `scheduled`/`running` | `200` submit envelope, `link: null` (poll that `call_id`) |
| delayed, `ready` | `200` envelope carrying that call's own `link` |
| delayed, `expired` | `200` envelope, `status:"expired"`, `link: null` — it does **not** schedule a new run |
| delayed, `failed` | `200` envelope with `error` set (the submit itself had succeeded) |
| instant, `ready` | the same rows inline, or the same file with `?download=1`, and the same `link` |
| instant, `failed` | the error the original POST returned (`504 timeout`, `413 result_too_large`, `502 …`) |
| instant, `expired` | `410 expired` — the stored result is gone, so there is nothing to replay |
| instant, still running | no finished result to hand back: it submits like a normal one |
| its `executions` row pruned by retention | a normal submit — the key's history went with the call |

A replay starts no work, so it does not consume the per-client submit throttle
above, and it is logged as `idempotent_replay` against the original `call_id`.
The key is scoped to (client, request): the same value from another client, or
on another request code, is an independent submission. To force a fresh run,
submit with a new key; the old call is left exactly as it was.

Concurrency: the replay lookup and this submit's own call write are fenced by a
per-(client, key) `GET_LOCK` (`gw_idem_…`, 10 s wait), so two simultaneous
retries cannot both conclude "no prior call". If the first attempt is an
in-flight instant run that outlives that wait, or the metadata DB stalls the
lock, the second behaves like an un-keyed submit — a duplicate call, never a
lost or wrong answer. This is "one key, one answer once the first attempt is
recorded", not a queue.

Upgrading: run the `calls.idempotency_key_hash` ALTER from the §8 upgrade block
before deploying. Submits without the header never read or write the column.

### Health check (`GET /health`)

`GET /health` answers whether this gateway can do its job **right now**: `200`
while all four checks pass, `503` as soon as one does not, with the individual
checks in the body so a monitor can tell which one failed. No authentication —
a load balancer and an uptime probe hold no client key — so the answer carries
only booleans, byte counts and timestamps: no database name, no paths, no
connection rows, no driver error text.

```
{
  "status": "degraded",
  "time": "2026-10-20T09:12:41+03:30",
  "checks": {
    "database": true,
    "database_error": null,
    "storage_writable": true,
    "storage_free_bytes": 41231686656,
    "storage_space": true,
    "worker_last_pass_at": "2026-10-20T09:11:58+03:30",
    "worker_last_pass_age_seconds": 43,
    "worker_last_pass_failures": 0,
    "worker_recent": false
  }
}
```

| check | what has to be true | why it is written that way |
|---|---|---|
| `database` | the metadata DB answers a ping | everything else is unreadable without it |
| `storage_writable` | a probe file can be created **and removed** inside `storage\results` | a stat-based `is_writable()` still says "writable" for some read-only mounts and ACLs; a gateway that cannot open a result file cannot deliver a result. The check creates `storage\results` first, exactly as the worker and cleanup do, so a fresh installation is not reported as broken |
| `storage_space` | free space on the storage volume is at or above `limits.min_free_disk_bytes` | the **same floor** the worker applies before writing (`Services\StorageGuard`), so the answer and the behaviour cannot drift; `0` disables the floor, and a volume whose free space cannot be measured (`storage_free_bytes: null`) counts as unknown, not full |
| `worker_recent` | `cli\worker_pool.php` finished a pass within `worker.max_silence_seconds` (default 300) | the schedule fires every minute, so 300 tolerates four missed fires before it pages; the row read is `worker_state`, written once per pass (see §3) |

`worker_last_pass_failures` counts the pass's own problems (a child worker that
exited non-zero, or a pool that crashed) and is **informational**: a pass that
ran and reported failures is still a live scheduler, so it does not turn the
answer red. `worker_last_pass_at: null` with `worker_recent: false` means no
heartbeat has ever been recorded — the task is not running, or `worker_state`
has not been created by the §8 upgrade.

Point the monitor at `GET /health` and page on any non-`200`; `status` is the
same verdict in the body if the probe only reads JSON. A `503` on
`worker_recent` while the other three pass is the common one: the web tier is
fine and the scheduled task is not. `php cli\check.php` prints the same
heartbeat age, so the pre-deploy gate catches it before monitoring does.

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

One gap to know about: if the worker cannot record the finished run at all —
the database refuses either the `ready` status update or the delivery insert —
the whole write is rolled back (no half-recorded result), the execution is
recorded as `failed` with `result could not be recorded: …`, and **no webhook
row is queued**: repeating the insert that just failed would abort the pass and
stall every other due execution behind it. That client must fall back to
`GET /api/v1/calls/{call_id}`. `tests\transaction_test.php` injects the failure
with a database trigger and asserts nothing half-applied survives it.

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
  replacement characters instead. `Str::json()` additionally never returns
  `false`: a value with **no** JSON representation at all (`INF`, `NAN`, a
  resource, a self-referencing object) becomes `{}`. Without that fallback the
  `: string` callers (audit before/after, webhook payloads) take a `TypeError`,
  and a response encoded through it is an empty body with a 200.
  `tests\str_json_test.php` asserts both halves.
- The result-file writer (`src/Worker/ResultWriter.php`) goes further for
  file mode: it repairs invalid UTF-8 in values AND in column-name keys
  (mojibake'd SQL aliases become repairable or positional `col_N` names)
  before falling back to substitution. `meta.encoding_sanitized` in a
  result file tells you a repair happened; with the SQL Server charset fix
  it should never appear again.

### What a result row looks like

- **Duplicate column names are kept, not collapsed.** The target drivers fetch
  numeric rows and name them through `Db\ColumnNames::deduplicate()`: the
  second `... AS n` in the same SELECT becomes `n_2`, the third `n_3`, and an
  empty column name becomes `col_<position>`. Otherwise the keys are exactly
  the aliases of the approved SQL — not trimmed, not case-folded, never
  reordered. Before this, the native `fetch_assoc()` / `pg_fetch_all()` /
  `sqlsrv_fetch_array(ASSOC)` calls built the row map from the raw column list
  and kept only the **last** value of a repeated alias, so an approved column
  disappeared from the row counts, the download file and the inline instant
  result, with no error anywhere. `tests\column_names_test.php` pins the
  naming rule and runs it through the real MySQL driver, `QueryRunner` and
  `ResultWriter`.
- **Numbers arrive as strings.** `SELECT 1 AS n` is `"n": "1"`, `2.50` is
  `"2.50"`; only NULL stays JSON `null`. All three drivers hand back the
  text the server sent, and the gateway deliberately does not guess a column's
  type from its name — clients that need integers cast them.
- **Binary columns are lossy.** `BLOB`/`VARBINARY`/`bytea` values reach the
  JSON layer as raw bytes, which are usually invalid UTF-8, so they are
  substituted or repaired (`meta.encoding_sanitized`) and cannot be recovered
  downstream. Base64-encode inside the approved SQL when a client needs binary
  data — `TO_BASE64(col)` on MySQL, `encode(col, 'base64')` on PostgreSQL —
  or keep the column out of the query; T-SQL has no portable built-in.

## 7. Cache rules (delayed mode)

1. A submit while an execution for the request is `scheduled`/`running`
   attaches to it — no duplicate run; the new call gets its own token.
2. A submit while a `ready`, unexpired execution exists attaches to it and
   the response carries a fresh download link immediately.
3. Otherwise a new execution is scheduled.

Rules 1 and 2 attach a call to an execution that already exists; rule 3 writes
two rows — the new scheduled execution and the call waiting for it — as one
transaction, so either both exist or neither does. An execution with no call
attached is a real query run for nobody, and the worker would still claim it.

Instant results follow the same spirit via the time-windowed cache, whose
window never outlives the cached result file.

A submit carrying an `Idempotency-Key` that this client already used on this
request skips these rules entirely: it is answered from the call that key
created (§4). The rules above are what a keyless submit — or a first use of a
key — does.

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

If the DB was created before admin session invalidation, add the session
epoch **before** deploying the new code (every authenticated admin page reads
it, so a missing column breaks the panel):

```sql
ALTER TABLE `admin_users`
  ADD COLUMN `session_version` INT NOT NULL DEFAULT 0 AFTER `locked_until`;
```

If the DB was created before idempotent submits, add the key column **before**
deploying (a submit carrying `Idempotency-Key` reads and writes it, so only
those submits break without it):

```sql
ALTER TABLE `calls`
  ADD COLUMN `idempotency_key_hash` CHAR(64) NULL DEFAULT NULL
    COMMENT 'SHA-256 of the submit Idempotency-Key header; scopes duplicate submits'
    AFTER `token_enc`,
  ADD INDEX `idx_calls_idempotency` (`client_id`, `request_id`, `idempotency_key_hash`);
```

If the DB was created before `GET /health`, create its heartbeat table **before**
deploying (`cli\worker_pool.php` writes it at the end of every pass and §4 reads
it):

```sql
CREATE TABLE IF NOT EXISTS `worker_state` (
  `id`                    TINYINT UNSIGNED NOT NULL,
  `last_pass_started_at`  DATETIME     NOT NULL,
  `last_pass_finished_at` DATETIME     NOT NULL,
  `last_pass_failures`    INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

It needs no backfill: no row simply means "no pass recorded yet", which
`/health` reports as `worker_recent: false`. The pool's write is caught, so even
if the ALTER lags behind the deploy the only cost is that health answer and a
warning per pass — executions, the API and the panel are unchanged. The other
three checks read nothing new, so an upgrade with no `worker_state` still gives
a correct answer about the database, storage and free space.

**Admin sessions and audit (2026-10):** no other schema change. Two behavior
changes are live out of the box (see §13): signed-in admin pages now expire
after `admin.session_idle_minutes` (default 30) without a request, and
existing sessions — which carry no `session_version` — are dropped on the
first request after the deploy, so admins simply log in once again. Logins,
refused logins, logouts and password replacements are written to
`admin_audit_log`; `cleanup.retention.audit_days` (default `0` = keep) is the
knob that prunes them.

**Retention + free-space floor (2026-10):** no schema change. Every
`cleanup.retention.*` window defaults to `0`, so an upgrade deletes nothing
until an admin turns a window on (back the metadata DB up first — pruning a
table with history is not reversible). The only new default that is active out
of the box is `limits.min_free_disk_bytes` (2 GiB): a volume with less free
space than that makes new delayed runs fail with `storage_low`. Lower it (or
set `0`) if the storage volume is small but intentionally nearly full.

**Instant result files (2026-10):** no schema change is needed — instant
runs reuse the existing `calls` token columns. Instant
responses gain a `link`, `?download=1` works out of the box, and the file
lifetime is `limits.instant.result_ttl_minutes` (default 60 minutes, swept
to `expired` by cleanup). Existing consumers that read the inline `data`
stay compatible unchanged.

**Retired execution-level download token (2026-10):** the worker no longer
mints a token per execution, and `executions.download_token_hash` is neither
written nor read any more — no API response ever carried that token, so the only
way to use it was to guess 48 hex characters. `GET /api/v1/results/{token}`
resolves the **call's** token only; a valid token presented by a client that
does not own it is a 404 (as before) and is now logged as `denied`. Fresh
databases do not create the column at all. On an existing database you may drop
it, but only **after** the new code is deployed:

```sql
ALTER TABLE `executions` DROP COLUMN `download_token_hash`;
```

The previous worker version writes that column, so dropping it first would fail
every finished run.

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

**SQL Server certificate validation (2026-10):** no schema change, but the
default flipped: `connections.sqlsrv_trust_server_certificate` used to be `true`
(accept any certificate) and is now `false`, and the session is now encrypted
unless `GATEWAY_SQLSRV_ENCRYPT=0` says otherwise (see §14). **Every SQL Server
target whose certificate does not chain to a CA in the Windows trust store on
the gateway host will start failing to connect on deploy** — a self-signed
intranet server is exactly that case. Either install the issuing certificate
first, or decide deliberately and put

```
GATEWAY_SQLSRV_TRUST_SERVER_CERT=1
```

in `config/.env`. `php cli\check.php` reports the stance and WARNs when active
SQL Server connections exist with validation off. Separately, the connect
handshake is now capped at `GATEWAY_SQLSRV_LOGIN_TIMEOUT` (default 15 s) instead
of inheriting the per-request query timeout, so a dead server fails fast rather
than holding a worker slot for the whole timeout.

## 9. Tests

**Safe path — `tests\run_all.php`** (portable: Windows, Linux, macOS; no
elevation needed): builds a throwaway environment itself — random
`data_gateway_test_<random>` schema (imported from `sql\schema.sql`), throwaway
`storage\test-<random>` dir — and
runs everything against it: `php -l` on every file, `smoke_db`,
`encryption_test --db`, `links_test`, `filesystem_test`, `sqlguard_test`,
`readonly_driver_test`, `pool_test`, `resultwriter_test`, `throttle_test`,
`proxy_test` (the address-range math of `Core\IpRange` and who
`Core\TrustedProxies`/`client_ip()` believe, plus the shipped admin session
defaults read from a `GATEWAY_*`-free child process),
`driver_tls_test` (the SQL Server connection options of §14 — encryption and
certificate validation defaults, the handshake cap, and a real connect attempt
to a closed port proving it gives up in seconds),
`str_json_test` (`Core\Str::json()` never returns `false` and never blanks a
response — §6),
`sapi_bootstrap_test` (a startup config failure under both the CLI and a real
`php -S` web request — §1),
`transaction_test` (the InnoDB engines plus `Core\Db::transaction()`, driven
through the real submit endpoint and a real worker pass with the second
statement refused by a database trigger — §5, §7),
`column_names_test` (the duplicate-column naming rule of §6, run through the
real MySQL driver, `QueryRunner` and `ResultWriter`),
`instant_ttl_test` (the instant cache window of §4 never exceeding the result
file lifetime, including the shipped default read from a `GATEWAY_*`-free child
process),
`health_test` (the four `GET /health` checks of §4 and the 200/503 verdict, over
a real `php -S` server: the 405, the heartbeat written by a real pool pass, the
single-row `worker_state` guarantee, and that the unauthenticated body carries
no database name, user, host, path or key — one child server per degraded
branch),
then the four end-to-end smokes (`api_smoke`, `admin_smoke`, `worker_smoke`,
`webhook_smoke`), `retention_test` (row retention pruning + the free-space
floor, including a subprocess run with `GATEWAY_MIN_FREE_DISK_BYTES` raised
above the real free space), `webhook_url_test` (the webhook SSRF policy of §5,
one child process per policy) plus `stream_memory_test`, each refusing to run
unless
`GATEWAY_TEST_MODE=1` and
the metadata DB name contains `_test` (they `exit(2)` otherwise). The DB and
storage dir are always dropped by a shutdown handler, even when a suite fails
or the runner is interrupted, and every fixture is run-scoped, so a green run
touches nothing outside its sandbox:

```
powershell -ExecutionPolicy Bypass -File tests\run_all.ps1
```

`run_all.ps1` is only the Windows entry point: it finds `php` (and optionally
`mysql`) and hands them to `tests\run_all.php`, so the local run and CI execute
the same code. Run the PHP runner directly for the other options
(`--list`, `--only=name,name`, `--rows=N`, `--keep`, `--root-host/--root-port`
for an admin connection that is not the local socket default).

**CI — `.github/workflows/ci.yml`.** On every push, pull request and manual
dispatch, Ubuntu runners on PHP **8.1 and 8.2** (extensions `mysqli`,
`mbstring`, `curl`, `memory_limit=256M`) start a `mariadb:10.11` service
container and run `php tests/run_all.php --root-host=127.0.0.1` against it. No
target SQL Server or PostgreSQL is contacted: the suites that cover those
drivers assert the option builders and the connect-failure path, and the SQL
Server driver's missing extension on Linux is itself a `DbConnectException`,
which is what `driver_tls_test` expects. Static analysis is deliberately **not**
in the workflow: `composer.json` declares no packages, so there is no way to get
`phpstan` onto a runner without introducing a dependency tree the gateway does
not have — and the `php -l` suite already catches the class of error a tool
would catch without the runtime wiring. The workflow is unverified until the
repository has a remote and a first push runs it.

Running the smoke scripts **directly** against a real `data_gateway` is no
longer supported — the guards above refuse it on purpose.

Two scripts in `tests\` are manual inspectors and are **not** called by
`run_all.php`: `audit_spot.php` prints the newest `admin_audit_log` rows from
whatever DB is configured (the smokes clean up after themselves, so it is only
useful against a database with real admin activity), and `driver_test.php`
opens a live connection to a target and needs its actual credentials.

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
   minutes); re-run the script to apply updated limits to existing tasks. Then
   point monitoring at `GET /health` (§4) — it answers `503` while the metadata
   DB is unreachable, storage cannot be written, the volume is under
   `limits.min_free_disk_bytes`, or the pool has not finished a pass within
   `worker.max_silence_seconds`, which is how you notice a scheduled task that
   stopped firing.
6. **Decide the history retention.** Every `cleanup.retention.*` window ships
   at `0` (keep forever), so nothing is pruned until you set it. Pick the
   numbers with whoever owns the metadata DB (§3 recommends
   `90 / 365 / 365 / 30 / 0`), put them in `config/.env` as
   `GATEWAY_RETENTION_*_DAYS`, and confirm the next cleanup pass reports
   `pruned …` counts on `php cli\cleanup.php`. Back the DB up before the
   first real prune.
7. **Decide the admin session rules.** `GATEWAY_ADMIN_SESSION_IDLE_MINUTES`
   (default 30) is the idle window; `GATEWAY_TRUSTED_PROXIES` must list the
   proxy addresses **if and only if** something else terminates TLS in front of
   the panel — otherwise the login throttle counts every admin as one client
   (§13). Then confirm `php cli\check.php` prints the intended
   `admin sessions:` line.
8. **Final gate:** `php cli\check.php` reports ALL CHECKS PASSED (this
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

### What never belongs in the repository

`storage/` is the directory most worth keeping out of git: a file under
`storage\results` is the literal output of a reviewed query — the same business
data an API response carries — and `storage\cache` holds instant-mode copies of
it. `.gitignore` therefore ignores the whole tree **except** `storage/.htaccess`,
the deny-all safety net a fresh checkout needs. An empty `storage/` is a valid
start: `Core\Filesystem::prepare()` (called by the worker, cleanup and the
instant path) creates `results/`, and the `*.lock` files appear when the CLI
scripts first run. `result*.json` is ignored at every level as well, so an
export saved next to `README.md` cannot be staged by accident. The other two
non-source paths are `config/.env` (every secret, §2) and `vendor/` (a generated
autoloader with no packages behind it, see the introduction).

Before pushing, `git status --ignored` should show nothing under `storage/`
except `.htaccess` (which is tracked, on purpose), and `git ls-files "*.json"`
should list only `composer.json`.

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

## 13. Admin panel: sessions, auditing, known limitations

The panel is a single-user-per-account, cookie-authenticated app with no
framework in front of it. What it does guarantee:

**Session lifetime.** A signed-in session expires after
`admin.session_idle_minutes` (`GATEWAY_ADMIN_SESSION_IDLE_MINUTES`, default
`30`) without a request; browsing keeps the window sliding. An expired session
is destroyed, the operator lands on the login page with
*"Your session expired. Please log in again."*, and the drop is audited. Set
the value to `0` only on a machine nobody else can sit at.

**Session invalidation.** `admin_users.session_version` is copied into the
session at login and compared on every request. `php cli\create_admin.php`
with the same or a new password replaces the hash **and** advances the epoch,
so a suspected leak can be closed by running it once: every other browser is
signed out on its next request. Setting `is_active = 0` on an account signs it
out immediately too.

**Cookies and headers.** HttpOnly, SameSite=Lax, `Secure` whenever HTTPS is
in use or `GATEWAY_BASE_URL` is `https://`, session id regenerated at login,
`session.use_strict_mode=1`, and `Cache-Control: no-store` plus the CSP,
`X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff` and
`Referrer-Policy: no-referrer` on every rendered page.

**Audit trail.** `admin_audit_log` rows with `entity_type = 'admin'` cover
`login`, `login_failed`, `logout`, `password_change` and `create`. A row
carries the resolved client IP and, for failures, a reason
(`wrong_password`, `wrong_password_locked`, `unknown_username`,
`inactive_account`, `locked_account`) or `idle`/`manual` for logouts — never a
password, never a hash. `admin_smoke` asserts that. The one thing deliberately
*not* audited is the per-IP throttle refusal (3 failures per address per
5 minutes), because that endpoint is reachable without credentials and every
refused probe would add a row. `cleanup.retention.audit_days` prunes the table
(`0` = keep forever).

**Who a request came from.** `admin.trusted_proxies`
(`GATEWAY_TRUSTED_PROXIES`, comma-separated addresses/CIDRs) decides whether
`X-Forwarded-For` is believed at all: only when `REMOTE_ADDR` is on the list,
and then the **rightmost** entry the list does not explain is used — the left
side of that header is text the client typed. With the default (empty list)
everything is recorded and throttled on `REMOTE_ADDR`. Behind a TLS-terminating
proxy, leave it empty and the login throttle becomes global (one person's three
bad passwords lock every admin out) and the logs show only the proxy.

**Saving a request definition reads the form body only.** Every field of
`/admin/requests` — including `connection_id` — is taken from the POST body; a
value in the query string is ignored, so a link cannot repoint a saved request
at another connection while the on-screen select shows a different one. A save
whose body has no connection fails validation with *"Pick a connection."*
instead of silently binding one.

**Not implemented, on purpose or for now:**

- **No second factor.** One factor — the password — guards the whole gateway.
  Put the panel behind a VPN, a reverse-proxy authentication layer, or Windows
  Firewall IP restrictions instead.
- **One role.** Every active admin sees and can change everything: connection
  credentials, pre-approved SQL, client keys. There is no read-only or
  per-request ownership model.
- **No in-panel account management.** Creating an admin, changing a password
  and clearing a lockout are CLI operations (`php cli\create_admin.php`), which
  is also why there is no "change my password" form or recovery flow: someone
  with host access is the reset path.
- **Sessions are PHP files** in the server's session save path. They survive a
  process restart and vanish with an ephemeral temp dir; there is no admin-side
  "active sessions" list, only the per-account epoch above.
- **The timeout is idle-based.** A page refreshed every 29 minutes keeps a
  session alive indefinitely — there is no absolute lifetime cap. To end one
  for sure, replace the password (`cli\create_admin.php`).
- **No rate limit on the panel as a whole** beyond the login throttle (per
  account: 5 failures then a 15-minute lock; per address: 3 failures in a
  5-minute window). If the panel is reachable from a wide network, throttle it
  at the proxy.

## 14. Target database connections: transport security

The gateway sends each target connection's login and password, and receives
result rows, over the network — so that hop is a secret path, not just a
socket. What it does:

**SQL Server (`sqlsrv`).** `Db\SqlServerDriver::connectionOptions()` is the
single place these options are built (admin *Test* button and worker alike), and
it is unit-tested (`tests\driver_tls_test.php`) without needing a server:

| Option | Value | Knob |
|--------|-------|------|
| `Encrypt` | `true` | `GATEWAY_SQLSRV_ENCRYPT=0` |
| `TrustServerCertificate` | `false` (validated) | `GATEWAY_SQLSRV_TRUST_SERVER_CERT=1` |
| `LoginTimeout` | `min(sqlsrv_login_timeout, max(5, query timeout))` | `GATEWAY_SQLSRV_LOGIN_TIMEOUT` (default `15`) |
| `ReturnDatesAsStrings`, `CharacterSet=UTF-8` | forced, not configurable | — (§6) |

- **Certificates are validated by default.** The target's server certificate
  must chain to a CA in the Windows trusted-root store on the gateway host. A
  server with a self-signed or internally-issued certificate then fails to
  connect with a `sqlsrv_connect` error — that is the intended outcome: fix it
  by exporting the issuing CA's certificate and installing it on the gateway
  (`certmgr.msc` → *Trusted Root Certification Authorities*, or
  `certutil -addstore -f ROOT <file.cer>` elevated).
- Setting `GATEWAY_SQLSRV_TRUST_SERVER_CERT=1` keeps the session encrypted but
  accepts **any** certificate, so anyone on the path to the target database can
  impersonate it and read every credential and row. Defensible on a closed LAN,
  never across a network you do not own. `php cli\check.php` prints the effective
  stance and WARNs while active SQL Server connections exist with validation
  off.
- **The handshake gets its own cap.** Before, `LoginTimeout` was derived from
  the per-request query timeout, so a 1800-second timeout also meant "wait
  half an hour for a server that never answers" — tying up a worker slot or an
  instant request. The login now never exceeds
  `connections.sqlsrv_login_timeout` (still at least 5 s for a slow VPN), and
  `driver_tls_test` proves a connect attempt to a closed port raises
  `Db\DbConnectException` in seconds.

**MySQL and PostgreSQL.** These drivers set **no TLS options at all** —
`MySqlDriver` uses `MYSQLI_OPT_CONNECT_TIMEOUT`/`MYSQLI_OPT_READ_TIMEOUT` and
`PgSqlDriver` uses `connect_timeout=15`, then pass host/port/user/password/db
only; neither `MYSQLI_OPT_SSL_*` nor `sslmode` is available in this build. For
those targets the encryption decision belongs to the database server and the
network: require TLS server-side (`require_secure_transport=ON` for
MySQL/MariaDB, `ssl = on` + `hostssl` lines in `pg_hba.conf`), or carry the
connection over a tunnel you control (IPsec, stunnel, an SSH port-forward to
`127.0.0.1`). `connections.sqlsrv_*` knobs do not affect them.


