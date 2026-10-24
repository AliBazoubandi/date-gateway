# Data Gateway — Client Guidance & Integration Manual

Welcome to the **Data Gateway** API. This guide provides everything client developers need to authenticate, invoke queries, poll execution status, securely download results, process webhooks, and handle errors.

---

## 1. System Overview & Core Concepts

The Data Gateway is a high-performance HTTP gateway that allows authenticated internal systems to execute pre-approved, reviewed SQL queries against internal databases (MySQL, MariaDB, Microsoft SQL Server, PostgreSQL) and retrieve results in clean, structured JSON format.

### Key Architectural Concepts

| Concept | Description |
| :--- | :--- |
| **Request Code (`code`)** | A unique identifier for a pre-configured, audited SQL query (e.g., `sales.daily`, `inventory.lookup`). **Clients never send raw SQL.** You invoke requests strictly by their assigned code. |
| **Call ID (`call_id`)** | A 32-character hexadecimal string (`[a-f0-9]{32}`) uniquely identifying an individual submission made by your client account. |
| **Execution ID** | The internal identifier for a query execution on the target database. If multiple clients submit the same request simultaneously, the gateway safely shares the execution. |
| **Download Token** | A secure, 48-character hexadecimal token unique to your specific call. Used to download the result file. |
| **Timezone** | **Tehran Local Time (`+03:30`, Iran Standard Time, no DST)**. All timestamps returned by the gateway follow ISO 8601 with the `+03:30` offset (e.g. `2026-10-22T14:30:00+03:30`). |
| **Base URL & Subpath** | The gateway may be mounted at domain root (e.g. `https://gw.internal`) or under a subpath prefix (e.g. `https://rbb.palood.ir/gateway`). All endpoints are relative to your deployment's base URL. Result download `link` URLs returned in responses and webhooks automatically include the full prefix. |

### Response Modes: Instant vs. Delayed

Every request code is configured in one of two modes:

```
                            ┌────────────────────────────────────────┐
                            │ POST /api/v1/request/{code}            │
                            └───────────────────┬────────────────────┘
                                                │
                       ┌────────────────────────┴────────────────────────┐
                       ▼                                                 ▼
             [ Instant Mode ]                                  [ Delayed Mode ]
         Synchronous execution                              Asynchronous execution
                       │                                                 │
        ┌──────────────┴──────────────┐                   ┌──────────────┴──────────────┐
        ▼                             ▼                   ▼                             ▼
   Standard POST              POST ?download=1         Polling Flow              Webhook Flow
 Inline JSON payload        Direct result file      Poll /calls/{id}           Signed push POST
   with "data" array        streamed immediately    until status="ready"       when run settles
        │                             │                   │                             │
        └─────────────────────────────┼───────────────────┴─────────────────────────────┘
                                      ▼
                        Download /api/v1/results/{token}
```

- **Instant Mode (Synchronous):** Designed for lightweight, fast queries. The query runs immediately during your HTTP request. Rows are returned inline inside the JSON response, and a downloadable file link is also provided. If you pass `?download=1`, the gateway streams the result file directly as an attachment.
- **Delayed Mode (Asynchronous):** Designed for medium-to-large queries, batch jobs, and scheduled exports. The gateway registers the submission, starts running the query in background worker processes, and allows you to retrieve the result file once the execution completes and the download window opens.

---

## 2. Authentication & Authorization

All API endpoints (except `/health`) require Bearer token authentication via the standard HTTP `Authorization` header.

### Request Header
```http
Authorization: Bearer <YOUR_CLIENT_API_KEY>
```

### Key Rules & Permissions
1. **API Key Generation:** API keys are issued by the gateway administrator. Keys are random strings between 40 and 128 characters (e.g., `gw_live_a1b2c3d4...`).
2. **Key Security:** The gateway only stores salted HMAC-SHA256 hashes of API keys; keys can never be recovered if lost. Keep your key confidential and store it in environment variables or a secrets manager.
3. **Permissions:** Every client account must be explicitly granted permission by an administrator for each request code it intends to call. Calling a code without permission yields `403 Forbidden`.
4. **Account State:** If your client account is deactivated by an administrator, all requests return `403 Forbidden`.

---

## 3. Quick Start (5-Minute Integration)

> **Windows PowerShell Note:** In Windows PowerShell, use `curl.exe` instead of bare `curl` (which is an alias for `Invoke-WebRequest`).

### Scenario A: Instant Query (Get Data Immediately)

#### 1. Standard Inline Request
```bash
curl.exe -X POST \
  -H "Authorization: Bearer YOUR_API_KEY" \
  https://gateway.example.com/api/v1/request/user.lookup
```
**Response (`200 OK`):**
```json
{
  "call_id": "7f8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d",
  "mode": "instant",
  "status": "ready",
  "executed_at": "2026-10-22T10:15:30+03:30",
  "row_count": 2,
  "truncated": false,
  "link": "https://gateway.example.com/api/v1/results/1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f",
  "data": [
    { "id": 101, "username": "alice", "status": "active" },
    { "id": 102, "username": "bob", "status": "pending" }
  ]
}
```

#### 2. One-Call Direct File Download (`?download=1`)
If you want the result file saved directly without buffering JSON in memory:
```bash
curl.exe -X POST -f -OJ \
  -H "Authorization: Bearer YOUR_API_KEY" \
  "https://gateway.example.com/api/v1/request/user.lookup?download=1"
```

---

### Scenario B: Delayed Query (Asynchronous Polling)

#### 1. Submit the Request
```bash
curl.exe -X POST \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Idempotency-Key: submit_order_report_20261022" \
  https://gateway.example.com/api/v1/request/sales.daily
```
**Response (`200 OK`):**
```json
{
  "call_id": "4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d",
  "status": "scheduled",
  "scheduled_at": "2026-10-22T10:20:00+03:30",
  "ready_at": null,
  "expires_at": null,
  "link": null
}
```

#### 2. Poll Call Status
Poll every 5 to 15 seconds using your `call_id`:
```bash
curl.exe \
  -H "Authorization: Bearer YOUR_API_KEY" \
  https://gateway.example.com/api/v1/calls/4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d
```

**When Ready (`200 OK`):**
```json
{
  "call_id": "4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d",
  "request_code": "sales.daily",
  "status": "ready",
  "row_count": 14500,
  "ready_at": "2026-10-22T10:20:00+03:30",
  "expires_at": "2026-10-22T11:20:00+03:30",
  "link": "https://gateway.example.com/api/v1/results/9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8c7b6a",
  "error": null
}
```

#### 3. Safely Download the Result File
Use the URL from the `link` property:
```bash
curl.exe -f -OJ \
  -H "Authorization: Bearer YOUR_API_KEY" \
  https://gateway.example.com/api/v1/results/9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8c7b6a
```
The file is saved under its deterministic server name (e.g., `sales.daily_20261022_112000_client3_e4512.json`).

---

## 4. Endpoints Reference
 
> **Base Path Note:** If your Data Gateway is mounted under a URL subpath (such as `https://rbb.palood.ir/gateway/`), prepend that subpath to all routes below:
> - Submit: `POST <BASE_URL>/api/v1/request/{code}` (e.g. `POST https://rbb.palood.ir/gateway/api/v1/request/{code}`)
> - Status: `GET <BASE_URL>/api/v1/calls/{call_id}` (e.g. `GET https://rbb.palood.ir/gateway/api/v1/calls/{call_id}`)
> - Download: `GET <BASE_URL>/api/v1/results/{token}` (e.g. `GET https://rbb.palood.ir/gateway/api/v1/results/{token}`)
> - Discovery (Catalog): `GET <BASE_URL>/api/v1/requests` or `/api/v1/catalog` (e.g. `GET https://rbb.palood.ir/gateway/api/v1/requests`)
> - Health: `GET <BASE_URL>/health` (e.g. `GET https://rbb.palood.ir/gateway/health`)

### 1. Submit Request
`POST /api/v1/request/{code}`

Initiates execution of a pre-configured request code.

#### URL Parameters
- `code` *(string, required)*: The unique request code (up to 64 alphanumeric characters plus `.` `-` `_`).

#### Query Parameters
- `download=1` *(optional, instant requests only)*: When passed, instructs the gateway to stream the complete result file as an attachment (`Content-Disposition: attachment`) rather than returning the inline JSON envelope.

#### Request Headers
- `Authorization: Bearer <KEY>` *(required)*
- `Idempotency-Key: <STRING>` *(optional, recommended)*: Unique key (8–200 printable ASCII characters) to ensure safe retries. See [Section 6: Idempotency](#6-idempotent-submissions-idempotency-key).

#### Response (Instant Mode)
Status: `200 OK`
```json
{
  "call_id": "string (32 hex chars)",
  "mode": "instant",
  "status": "ready",
  "executed_at": "2026-10-22T10:30:00+03:30",
  "row_count": 150,
  "truncated": false,
  "link": "https://gateway.example.com/api/v1/results/...",
  "data": [
    { "column_name": "value", "...": "..." }
  ]
}
```

#### Response (Delayed Mode)
Status: `200 OK`
```json
{
  "call_id": "string (32 hex chars)",
  "status": "scheduled",
  "scheduled_at": "2026-10-22T10:45:00+03:30",
  "ready_at": null,
  "expires_at": null,
  "link": null
}
```
*(Note: If the request attached to an execution that is already finished and whose download window is already open, status will be `ready` and `link` will be populated immediately).*

---

### 2. Poll Call Status
`GET /api/v1/calls/{call_id}`

Retrieves the current execution and download status of a call. Only the client that created the call can poll it.

#### URL Parameters
- `call_id` *(string, required)*: The 32-character hexadecimal Call ID received upon submission.

#### Request Headers
- `Authorization: Bearer <KEY>` *(required)*

#### Response Fields
```json
{
  "call_id": "string (32 hex)",
  "request_code": "string",
  "status": "scheduled | running | ready | expired | failed",
  "row_count": 14500,
  "ready_at": "2026-10-22T10:30:00+03:30",
  "expires_at": "2026-10-22T11:30:00+03:30",
  "link": "https://gateway.example.com/api/v1/results/...",
  "error": null
}
```

#### Lifecycle Status States

| Status | Meaning | Action Needed |
| :--- | :--- | :--- |
| `scheduled` | The query is waiting in queue, or running, or has finished early but is waiting for the download window (`ready_at`) to open. | Continue polling (e.g. every 5–15 seconds). |
| `running` | The query is currently executing against the target database. | Continue polling. |
| `ready` | The query completed successfully and the download window is open. | Download result using the provided `link`. |
| `expired` | The result file has passed its `expires_at` deadline and has been archived. | Resubmit the request if fresh data is needed. |
| `failed` | The query failed to execute (timeout, size limit, error). | Inspect the `error` object. Resubmit or contact administrator. |

#### Understanding the Early-Finish Hold Window
If a delayed request has a delay promise (e.g. 10 minutes), background workers start executing the query immediately upon submission. If the query finishes in 2 minutes, the result is safely stored, but the download window does not open until `ready_at` (10 minutes after submission). During this hold period:
- Polling reports `status: "scheduled"` with `ready_at` and `expires_at` populated, and `link: null`.
- Attempting to download before `ready_at` returns `409 result_not_ready` with a `Retry-After: <seconds>` header.

---

### 3. Download Result File
`GET /api/v1/results/{token}`

Streams the JSON result file. 

#### URL Parameters
- `token` *(string, required)*: The 48-character hexadecimal download token extracted from the `link` URL.

#### Request Headers
- `Authorization: Bearer <KEY>` *(required)*: The downloading client must own the call associated with this token.

#### Response Headers
```http
HTTP/1.1 200 OK
Content-Type: application/json; charset=utf-8
Content-Disposition: attachment; filename="sales.daily_20261022_113000_client3_e4512.json"
Content-Length: 1048576
Cache-Control: no-store
```

#### Safe Download Failure Protection (HTTP 410)
When a result has expired (`now >= expires_at`):
- The server responds with `HTTP/1.1 410 Gone`.
- Headers include `X-Gateway-Error: expired` and `X-Gateway-Message: This result has expired.`.
- **Zero body bytes are sent** (the connection is deliberately terminated short of the declared `Content-Length`).
- **Why this matters:** If your automated script runs `curl -o data.json` after expiration, `curl` exits with error code 18 (`end of response with N bytes missing`) and **leaves your previously downloaded `data.json` completely intact** instead of overwriting it with an error message!

#### Recommended Download Command
To take full advantage of client safety features, use:
```bash
curl.exe -f -OJ -H "Authorization: Bearer YOUR_API_KEY" "https://gateway.example.com/api/v1/results/<TOKEN>"
```
- `-f`: Fails immediately on HTTP errors (e.g., 404, 409, 410) without writing anything.
- `-O -J`: Automatically uses the filename provided in the server's `Content-Disposition` header, and refuses to overwrite existing local files if already downloaded.

---

### 4. Discover Permitted Requests & Endpoints (Catalog)
`GET /api/v1/requests` (or `GET /api/v1/catalog`)

A self-service introspection endpoint that allows authenticated clients to discover:
- Their client account identity (`name`, `type`).
- Standard gateway endpoint templates (`submit`, `status`, `download`, `health`).
- The complete list of active request codes the client is permitted to invoke, including response mode (`instant` vs `delayed`), timing promises (`cache_seconds` or `delay_minutes` / `ttl_minutes`), `max_rows`, `timeout_seconds`, and direct submission URLs.

#### Request Headers
- `Authorization: Bearer <KEY>` *(required)*

#### Response (`200 OK`)
```json
{
  "client": {
    "name": "sales-service",
    "type": "agent"
  },
  "endpoints": {
    "submit": "https://gateway.example.com/api/v1/request/{code}",
    "status": "https://gateway.example.com/api/v1/calls/{call_id}",
    "download": "https://gateway.example.com/api/v1/results/{token}",
    "health": "https://gateway.example.com/health"
  },
  "requests": [
    {
      "code": "inventory.lookup",
      "name": "Real-time Inventory",
      "description": "Stock levels by SKU",
      "mode": "instant",
      "timeout_seconds": 30,
      "max_rows": 1000,
      "submit_url": "https://gateway.example.com/api/v1/request/inventory.lookup",
      "cache_seconds": 30
    },
    {
      "code": "sales.daily",
      "name": "Daily Sales Report",
      "description": "Aggregated daily sales figures grouped by branch",
      "mode": "delayed",
      "timeout_seconds": 60,
      "max_rows": 100000,
      "submit_url": "https://gateway.example.com/api/v1/request/sales.daily",
      "delay_minutes": 5,
      "ttl_minutes": 15
    }
  ]
}
```

#### Example cURL
```bash
curl.exe \
  -H "Authorization: Bearer YOUR_API_KEY" \
  https://gateway.example.com/api/v1/requests
```

---

### 5. Health Check
`GET /health`

An unauthenticated probe endpoint for monitoring systems and load balancers.

#### Response (`200 OK` or `503 Service Unavailable`)
```json
{
  "status": "ok",
  "time": "2026-10-22T10:30:00+03:30",
  "checks": {
    "database": true,
    "database_error": null,
    "storage_writable": true,
    "storage_free_bytes": 53687091200,
    "storage_space": true,
    "worker_last_pass_at": "2026-10-22T10:29:45+03:30",
    "worker_last_pass_started_at": "2026-10-22T10:29:00+03:30",
    "worker_last_pass_age_seconds": 30,
    "worker_last_pass_failures": 0,
    "worker_recent": true
  }
}
```

---

## 5. Result File Format & Data Contract

Downloaded result files (and the output of `POST ?download=1`) are structured in a consistent JSON streaming envelope:

```json
{
  "data": [
    {
      "order_id": 98231,
      "customer_code": "CUST-409",
      "total_amount": 1450000.00,
      "created_at": "2026-10-21 16:45:00",
      "notes": null
    },
    {
      "order_id": 98232,
      "customer_code": "CUST-812",
      "total_amount": 320000.50,
      "created_at": "2026-10-21 17:10:12",
      "notes": "Urgent delivery"
    }
  ],
  "meta": {
    "mode": "delayed",
    "execution_id": 4512,
    "request_code": "sales.daily",
    "row_count": 2,
    "truncated": false,
    "duration_ms": 185,
    "executed_at": "2026-10-22T10:20:00+03:30"
  }
}
```

### Metadata Fields (`meta`)
- `mode`: `"instant"` or `"delayed"`.
- `execution_id`: Numeric ID of the database query execution.
- `request_code`: The request code that produced this result.
- `row_count`: Number of records in the `data` array.
- `truncated`: `true` if the query hit the administrative `max_rows` ceiling (meaning more rows existed in the database than were returned).
- `duration_ms`: Wall-clock query execution time on the target database in milliseconds.
- `executed_at`: Timestamp (Tehran local time) when the query finished execution.
- `encoding_sanitized`: *(Optional boolean)* Present and `true` only if invalid or corrupted UTF-8 bytes in the database were automatically repaired into valid UTF-8.

---

## 6. Idempotent Submissions (`Idempotency-Key`)

To prevent accidentally running expensive queries multiple times due to network disconnects, timeouts, or retried API calls, pass an `Idempotency-Key` header with your `POST` submission.

```http
POST /api/v1/request/sales.daily HTTP/1.1
Authorization: Bearer YOUR_API_KEY
Idempotency-Key: b7f6c382-7f39-4d89-9a2e-4b68ef5d8931
```

### Idempotency Key Rules
1. **Format:** 8 to 200 printable ASCII characters (e.g. UUIDv4 or client transaction identifier).
2. **Scope:** Scoped strictly to `(client_id, request_code, idempotency_key)`.
3. **Replay Behavior:**
   - If a request with the same key is submitted while the initial query is **still executing**: The gateway returns `409 Conflict` with `code: "submission_in_progress"` and a `Retry-After: 5` header. It does **not** launch a duplicate query.
   - Once completed: Resending the exact same key returns the exact same call response (`call_id`, `status`, `data` or `link`) without running the query again or counting towards your rate limit.
   - If expired: Resubmitting an idempotency key for an expired result returns `410 expired` (or delayed submit envelope with `status: "expired"`). To run a fresh query, simply generate a new idempotency key.

---

## 7. Rate Limits & Concurrency

The gateway enforces two tiers of concurrency and rate limits:

### 1. Per-Client Submission Throttle
- To prevent runaway client loops, clients are limited to a configured maximum number of submissions (default: 60 submissions per 60 seconds).
- If exceeded, the gateway returns `429 Too Many Requests`:
  ```json
  {
    "error": {
      "code": "rate_limited",
      "message": "This connection is busy. Retry shortly."
    }
  }
  ```
- **Response Header:** `Retry-After: <seconds>` indicating the remaining seconds before the current rate window resets.

### 2. Target Database Concurrency Cap
- Each target database connection has a concurrency cap (default: 5 concurrent queries).
- **Instant requests:** If all query slots are currently occupied, instant requests return `429 rate_limited` with `Retry-After: 5` instead of queuing, protecting upstream database stability.
- **Delayed requests:** Automatically queue in `scheduled` state and are picked up in earliest-scheduled order by worker processes as slots become available.

---

## 8. Webhooks Integration Guide

If your client account is configured with a **Webhook URL** and a **Webhook Secret**, the gateway automatically pushes a signed HTTP POST notification as soon as a delayed execution finishes (`ready` or `failed`).

### Webhook Request Structure

The gateway sends an HTTP POST request to your webhook URL with the following headers:

```http
POST /your-webhook-endpoint HTTP/1.1
Host: client.example.com
Content-Type: application/json
X-Gateway-Signature: sha256=4f3b2c1d0e9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b4c3d2e1f0a9b8c7d6e5f4a3b
X-Gateway-Timestamp: 1792657200
X-Gateway-Call: 4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d
X-Gateway-Attempt: 1
```

#### Payload: Successful Execution (`ready`)
```json
{
  "call_id": "4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d",
  "request_code": "sales.daily",
  "status": "ready",
  "link": "https://gateway.example.com/api/v1/results/9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8c7b6a",
  "expires_at": "2026-10-22T11:20:00+03:30",
  "row_count": 14500
}
```

#### Payload: Failed Execution (`failed`)
```json
{
  "call_id": "4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d",
  "request_code": "sales.daily",
  "status": "failed",
  "link": null,
  "error": {
    "code": "timeout",
    "message": "The query did not finish within its timeout and was stopped before it produced a result."
  }
}
```

### Signature Verification Algorithm
To ensure the webhook genuinely originated from the gateway and was not tampered with or replayed:

1. **Extract Headers:** Read `X-Gateway-Signature` and `X-Gateway-Timestamp`.
2. **Replay Protection:** Verify that `|current_unix_time - X-Gateway-Timestamp| <= 300` (reject requests older than 5 minutes).
3. **Compute HMAC:** Concatenate the timestamp, a literal period `.`, and the **exact raw request body bytes**:
   $$\text{SignatureString} = \text{Timestamp} + \text{"."} + \text{RawBodyBytes}$$
4. Calculate the HMAC-SHA256 of this string using your Webhook Secret:
   $$\text{ExpectedSignature} = \text{"sha256="} + \text{hex}(\text{HMAC-SHA256}(\text{SignatureString}, \text{Secret}))$$
5. **Constant-Time Comparison:** Compare `ExpectedSignature` with `X-Gateway-Signature` using a timing-attack safe comparison (e.g., `hash_equals` in PHP, `crypto.timingSafeEqual` in Node.js, `hmac.compare_digest` in Python).

### Webhook Delivery Acknowledgement & Retries
- **Success:** Return any `2xx` HTTP status code (e.g. `200 OK` or `202 Accepted`) within **10 seconds**.
- **Retries:** If your server returns any non-2xx status code or times out, the gateway will retry up to **5 times** using an exponential backoff schedule:
  - Attempt 1: Immediate
  - Attempt 2: After 1 minute
  - Attempt 3: After 2 minutes
  - Attempt 4: After 5 minutes
  - Attempt 5: After 10 minutes
  - Attempt 6: After 30 minutes
  - If all 5 retries fail, the delivery transitions to `exhausted`.

---

## 9. Error Reference & Status Codes

All API errors return standard JSON formatted as:
```json
{
  "error": {
    "code": "error_code_string",
    "message": "Human-readable explanation."
  }
}
```

### Error Code Catalog

| HTTP Status | Error Code | Description | Client Remedy |
| :---: | :--- | :--- | :--- |
| `400` | `invalid_idempotency_key` | Idempotency-Key header is less than 8 or more than 200 characters, or contains non-printable characters. | Supply an 8–200 character ASCII key (e.g. UUIDv4). |
| `401` | `unauthorized` | Missing or invalid `Authorization: Bearer <key>` header. | Check API key configuration. |
| `403` | `forbidden` | Client account is deactivated, or lacks permission for this request code. | Request permission from gateway administrator. |
| `404` | `not_found` | Unknown endpoint, unknown request code, or unknown/unauthorized `call_id` / `token`. | Verify request code or call ID spelling. |
| `405` | `method_not_allowed` | HTTP method not allowed (e.g. sending `GET` to `/request/{code}`). | Use `POST` for submission, `GET` for polling/download. |
| `409` | `submission_in_progress` | An idempotent submission with this key is currently executing. | Wait the duration specified in `Retry-After: 5` and retry. |
| `409` | `result_not_ready` | Result finished early; download window opens at `ready_at`. | Wait the duration in `Retry-After` seconds before downloading. |
| `410` | `expired` | The requested result has expired from gateway storage. | Submit a new request. |
| `413` | `result_too_large` | Instant query result exceeded maximum memory limit (default 16 MiB). | Use a delayed request or request administrator to narrow query. |
| `429` | `rate_limited` | Client submission limit reached, or target DB connection busy. | Pause for the duration given in the `Retry-After` header. |
| `502` | `execution_failed` | Database query failed on the target database engine. | Gateway logs record details; contact administrator if persistent. |
| `502` | `query_rejected` | Stored query was rejected by read-only policy. | Administrative configuration error; contact administrator. |
| `502` | `result_unavailable` | Stored result file is missing or unreadable on disk. | Resubmit the request. |
| `504` | `timeout` | Target database query exceeded `timeout_seconds`. | Query took too long; contact administrator to optimize query. |
| `500` | `internal_error` | Unexpected internal server condition. | Contact gateway administrator. |

---

## 10. Code Examples & SDK Patterns

### Python 3 (`requests`)

```python
import time
import requests

GATEWAY_BASE = "https://gateway.example.com"
API_KEY = "your_client_api_key_here"

HEADERS = {
    "Authorization": f"Bearer {API_KEY}",
    "Content-Type": "application/json"
}

def run_instant_query(request_code: str):
    """Executes an instant query and returns inline rows."""
    url = f"{GATEWAY_BASE}/api/v1/request/{request_code}"
    resp = requests.post(url, headers=HEADERS, timeout=60)
    resp.raise_for_status()
    payload = resp.json()
    print(f"Executed in {payload.get('executed_at')}, rows: {payload.get('row_count')}")
    return payload["data"]

def run_delayed_query_and_download(request_code: str, output_filepath: str):
    """Submits a delayed query, polls until ready, and saves result."""
    submit_url = f"{GATEWAY_BASE}/api/v1/request/{request_code}"
    # Use an idempotency key to prevent accidental duplicate runs
    submit_headers = {**HEADERS, "Idempotency-Key": f"py_job_{int(time.time())}"}
    
    resp = requests.post(submit_url, headers=submit_headers, timeout=15)
    resp.raise_for_status()
    call = resp.json()
    call_id = call["call_id"]
    print(f"Submitted call: {call_id}, status: {call['status']}")

    # Polling loop
    poll_url = f"{GATEWAY_BASE}/api/v1/calls/{call_id}"
    while True:
        poll_resp = requests.get(poll_url, headers=HEADERS, timeout=15)
        poll_resp.raise_for_status()
        info = poll_resp.json()
        status = info["status"]
        print(f"Call {call_id} status: {status}")

        if status == "ready":
            download_link = info["link"]
            print(f"Downloading from {download_link}...")
            dl_resp = requests.get(download_link, headers=HEADERS, stream=True, timeout=120)
            dl_resp.raise_for_status()
            with open(output_filepath, "wb") as f:
                for chunk in dl_resp.iter_content(chunk_size=65536):
                    f.write(chunk)
            print(f"Saved result to {output_filepath}")
            return
        elif status == "failed":
            err = info.get("error") or {}
            raise RuntimeError(f"Query execution failed: [{err.get('code')}] {err.get('message')}")
        elif status == "expired":
            raise RuntimeError("Result expired before it could be downloaded.")
        
        # Wait 10 seconds before next poll
        time.sleep(10)

if __name__ == "__main__":
    # Example usage:
    # rows = run_instant_query("sales.daily_summary")
    # run_delayed_query_and_download("sales.monthly_export", "sales_report.json")
    pass
```

---

### Python Webhook Receiver (FastAPI)

```python
import hmac
import hashlib
import time
from fastapi import FastAPI, Request, HTTPException, Header, status

app = FastAPI()
WEBHOOK_SECRET = "your_webhook_secret_here"

@app.post("/webhook/gateway")
async def handle_gateway_webhook(
    request: Request,
    x_gateway_signature: str = Header(None),
    x_gateway_timestamp: str = Header(None),
    x_gateway_call: str = Header(None)
):
    if not x_gateway_signature or not x_gateway_timestamp:
        raise HTTPException(status_code=401, detail="Missing signature headers")

    # 1. Anti-replay check (5 minutes)
    try:
        ts = int(x_gateway_timestamp)
        if abs(time.time() - ts) > 300:
            raise HTTPException(status_code=401, detail="Timestamp expired")
    except ValueError:
        raise HTTPException(status_code=401, detail="Invalid timestamp format")

    # 2. Verify HMAC-SHA256 signature
    raw_body = await request.body()
    signed_data = f"{x_gateway_timestamp}.".encode("utf-8") + raw_body
    computed_sig = "sha256=" + hmac.new(
        WEBHOOK_SECRET.encode("utf-8"),
        signed_data,
        hashlib.sha256
    ).hexdigest()

    if not hmac.compare_digest(computed_sig, x_gateway_signature):
        raise HTTPException(status_code=401, detail="Invalid signature")

    # 3. Process payload
    payload = await request.json()
    status = payload.get("status")
    call_id = payload.get("call_id")

    if status == "ready":
        print(f"Call {call_id} is ready for download: {payload.get('link')}")
        # Enqueue background download task
    elif status == "failed":
        err = payload.get("error")
        print(f"Call {call_id} failed: {err}")

    # Return 200 or 202 to acknowledge receipt
    return {"received": True}
```

---

### Node.js / TypeScript (`fetch` & Webhook Receiver)

```typescript
import crypto from "crypto";
import express, { Request, Response } from "express";

const GATEWAY_BASE = "https://gateway.example.com";
const API_KEY = "your_client_api_key_here";
const WEBHOOK_SECRET = "your_webhook_secret_here";

/**
 * Executes an instant request and returns records
 */
async function queryInstant<T = any>(requestCode: string): Promise<T[]> {
  const resp = await fetch(`${GATEWAY_BASE}/api/v1/request/${requestCode}`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${API_KEY}`,
      "Content-Type": "application/json",
    },
  });

  if (!resp.ok) {
    const err = await resp.json().catch(() => ({}));
    throw new Error(`Gateway Error (${resp.status}): ${JSON.stringify(err)}`);
  }

  const result = await resp.json();
  return result.data as T[];
}

/**
 * Express Webhook Verification Middleware & Endpoint
 */
const app = express();

// Important: Webhook verification requires the raw byte buffer
app.post(
  "/api/gateway-webhook",
  express.raw({ type: "application/json" }),
  (req: Request, res: Response) => {
    const signature = req.headers["x-gateway-signature"] as string;
    const timestamp = req.headers["x-gateway-timestamp"] as string;

    if (!signature || !timestamp) {
      return res.status(401).send("Missing headers");
    }

    // 1. Check replay window (300 seconds)
    const now = Math.floor(Date.now() / 1000);
    if (Math.abs(now - parseInt(timestamp, 10)) > 300) {
      return res.status(401).send("Timestamp expired");
    }

    // 2. Validate HMAC
    const rawBuffer = req.body as Buffer;
    const expected =
      "sha256=" +
      crypto
        .createHmac("sha256", WEBHOOK_SECRET)
        .update(`${timestamp}.`)
        .update(rawBuffer)
        .digest("hex");

    if (
      signature.length !== expected.length ||
      !crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected))
    ) {
      return res.status(401).send("Invalid signature");
    }

    const payload = JSON.parse(rawBuffer.toString("utf-8"));
    console.log(`Received call ${payload.call_id} status: ${payload.status}`);

    if (payload.status === "ready") {
      console.log(`Download URL: ${payload.link}`);
    }

    return res.status(202).json({ accepted: true });
  }
);
```

---

### PHP (`curl` & Webhook Receiver)

```php
<?php
// ============================================================================
// Webhook Receiver Verification (webhook.php)
// ============================================================================

$webhookSecret = 'your_webhook_secret_here';

$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_GATEWAY_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_GATEWAY_TIMESTAMP'] ?? '';

// 1. Replay attack verification (5 min)
if ($timestamp === '' || abs(time() - (int)$timestamp) > 300) {
    http_response_code(401);
    exit('Timestamp invalid or expired');
}

// 2. Signature verification
$expectedSig = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);
if (!hash_equals($expectedSig, $signature)) {
    http_response_code(401);
    exit('Signature mismatch');
}

// 3. Process event
$event = json_decode($rawBody, true);
if (($event['status'] ?? '') === 'ready') {
    $downloadUrl = $event['link'];
    // Queue background download
} elseif (($event['status'] ?? '') === 'failed') {
    $errCode = $event['error']['code'] ?? 'unknown';
    // Log failure
}

http_response_code(202);
echo json_encode(['status' => 'accepted']);
```

---

## 11. Operational Best Practices & Client Checklist

- [ ] **Always Include `Idempotency-Key`:** Generate a unique key per batch/attempt (e.g. `report_20261022_001`). This ensures safe retries during network failures without generating duplicate database load.
- [ ] **Respect Rate Limits (`Retry-After`):** If you receive HTTP `429`, always read the `Retry-After` header and wait that exact duration before re-sending.
- [ ] **Polite Polling Cadence:** When polling `GET /api/v1/calls/{call_id}`, start with an initial 5-second sleep, then poll every 10–15 seconds. Avoid sub-second polling loops.
- [ ] **Use Webhooks for Large Runs:** For jobs with long delay promises or queries that scan large tables, webhooks are far more efficient and reliable than sustained polling.
- [ ] **Verify Webhook Signatures on Raw Bytes:** When verifying webhooks, compute HMAC over the raw request payload before parsing it into an object or JSON dictionary. Re-encoding JSON can alter whitespace and invalidate signatures.
- [ ] **Handle File Streams Gracefully:** Result files can range from a few kilobytes to hundreds of megabytes. Stream large downloads directly to disk rather than loading the entire JSON file into memory.
- [ ] **Download Protection with `curl -f -OJ`:** Ensure automated scripts do not overwrite previously downloaded good files if an expired link returns HTTP 410.

---

*Need new request codes, altered query parameters, or raised concurrency limits? Contact your gateway system administrator.*
