# TopS.II Predictive Dialer Relay

A PHP relay that receives GET callbacks from the Ameyo dialer and forwards them to the TopS.II (FastHelp) Web APIs.

## Non-technical overview

- Acts like a mailroom: it receives call status updates from Ameyo and forwards the right CRM API call.
- Replies immediately with a simple JSON acknowledgment, then continues processing in the background.
- Keeps a lightweight state file per call to decide what to send when a customer has two phone numbers.

## How it works (short)

1. Ameyo calls `index.php` (call end / not answer) or `call_start.php` (call start) with query parameters via `GET`.
2. The relay logs the request, immediately returns `{"success": true, "message": "Data Received"}`, then keeps processing asynchronously.
3. It maps the incoming fields to the correct TopS.II API payload and sends the request (when `ENABLE_REAL_SEND=true`).

---

## Endpoints

### Primary endpoints (active)

| Endpoint | Handles |
|----------|---------|
| `GET /index.php` | Call End (`createCallEnd`) and Not Answer (`createNotAnswer`) |
| `GET /call_start.php` | Call Start (`createCallStart`) |

### Routing rules — `index.php`

| Condition | Action |
|-----------|--------|
| `systemDisposition=CONNECTED` and `shareablePhonesDialIndex=0` | → `createCallEnd` (phone1 connected, no errorInfo) |
| `systemDisposition=CONNECTED` and `shareablePhonesDialIndex>=1` | → `createCallEnd` (phone2 connected, phone1 errorInfo included) |
| Not connected, single phone | → `createNotAnswer` with `errorInfo1` |
| Not connected, two phones, phone1 callback | → Store phone1 status in state; wait for phone2 callback |
| Not connected, two phones, phone2 callback | → `createNotAnswer` with `errorInfo1` + `errorInfo2` |
| `hangupCauseCode` present (pre-dial failure signal) | → Store phone1 status in state; wait for phone2 callback |

---

## Field mapping

### `index.php` — key inputs

| Ameyo field | Maps to | Notes |
|-------------|---------|-------|
| `unique_id` | `callId` (Number) | Required |
| `userId` | `predictiveStaffId` | Required for Call End |
| `customerCRTId` | `subCtiHistoryId` | Required for Call End |
| `dialledPhone` → fallback `dialedPhone` | `targetTel` | For phone1 callbacks |
| `cstmPhone` | `targetTel` | Used when `dialIndex >= 1` |
| `systemDisposition` | Connection status / errorInfo value | e.g. `CONNECTED`, `PROVIDER_TEMP_FAILURE` |
| `hangupCauseCode` | Maps to errorInfo via hangup cause table | Numeric SIP code; signals phone1 failure |
| `shareablePhonesDialIndex` | Dial attempt index (0 = phone1, 1+ = phone2) | |
| `phoneList` | Array of phone numbers | JSON-encoded string |
| `callConnectedTime` | `callStartTime` | Ameyo format: `YYYY/MM/DD HH:mm:ss +0900` |
| `customerId` | State key component | Used to namespace state files |

### `call_start.php` — key inputs

| Ameyo field | Maps to | Notes |
|-------------|---------|-------|
| `cs_unique_id` (fallback: `callId`, `crm_push_generated_time`, `sessionId`) | `callId` (Number) | First non-empty value wins |
| `userId` | `predictiveStaffId` | Required |
| `displayPhone` | `targetTel` | Required; only `displayPhone` is used |

---

## Immediate response

Both endpoints always respond immediately with:

```json
{"success": true, "message": "Data Received"}
```

Processing continues after this ACK. Errors that occur after the ACK are logged but not returned to Ameyo (the response is already sent).

---

## Quick start

1. Copy `.env.example` to `.env` and fill in URLs and API keys.
2. Serve the repo root:
   ```bash
   php -S localhost:8000
   ```
3. Test with sample callbacks:
   ```bash
   # Not answered (single phone)
   curl "http://localhost:8000/index.php?unique_id=99999999&systemDisposition=NO_ANSWER&customerId=1&customerCRTId=abc-123&phoneList=%7B%22phoneList%22%3A%5B%2203000000001%22%5D%7D"

   # Call end (phone1 connected)
   curl "http://localhost:8000/index.php?unique_id=99999999&systemDisposition=CONNECTED&shareablePhonesDialIndex=0&userId=ABCD1234&dialledPhone=03000000001&customerCRTId=abc-123&callConnectedTime=2026%2F03%2F06+13%3A20%3A05+%2B0900&customerId=1"

   # Call end (phone2 connected, phone1 failed)
   curl "http://localhost:8000/index.php?unique_id=99999999&systemDisposition=CONNECTED&shareablePhonesDialIndex=1&userId=ABCD1234&cstmPhone=03000000002&customerCRTId=abc-123&callConnectedTime=2026%2F03%2F06+13%3A20%3A05+%2B0900&customerId=1&phoneList=%7B%22phoneList%22%3A%5B%2203000000001%22%2C%2203000000002%22%5D%7D"

   # Call start
   curl "http://localhost:8000/call_start.php?cs_unique_id=99999999&userId=ABCD1234&displayPhone=03000000001"
   ```

---

## Configuration (`.env`)

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `TEST_BASE_URL` | Yes | — | Base URL of the TEST TopS.II API server (no trailing slash) |
| `PROD_BASE_URL` | Yes | — | Base URL of the PROD TopS.II API server |
| `TEST_API_KEY` | Yes | — | 32-character alphanumeric Web API access key (TEST) |
| `PROD_API_KEY` | Yes | — | 32-character alphanumeric Web API access key (PROD) |
| `INDEX_ENV` | Yes | `TEST` | `TEST` or `PROD` — selects which URL and key to use |
| `ENABLE_REAL_SEND` | Yes | `false` | `true` sends upstream; `false` logs only (**must be `true` in production**) |
| `PHONE1_STATE_TTL_SECONDS` | No | `600` | Seconds to retain phone1 state while waiting for phone2 |
| `REQUEST_PROCESSING_TTL_SECONDS` | No | `30` | In-flight dedupe window in seconds |
| `REQUEST_DEDUPE_TTL_SECONDS` | No | `300` | Completed-request dedupe window in seconds |

---

## Logging

Log files are created daily under `logs/`, named by channel:

| File | Contains |
|------|----------|
| `logs/call_start-YYYY-MM-DD.log` | All `createCallStart` activity |
| `logs/call_end-YYYY-MM-DD.log` | All `createCallEnd` activity |
| `logs/not_answer-YYYY-MM-DD.log` | All `createNotAnswer` activity |
| `logs/general-YYYY-MM-DD.log` | Unrouted / fallback events |

- Timezone: `Asia/Tokyo`
- Each request has a unique `request_id` that correlates all log entries for that request.

---

## State files

Stored under `logs/state/`. Three types:

| Prefix | Purpose | TTL |
|--------|---------|-----|
| `phone1_<hash>.json` | Phone1 failure status; retained until phone2 callback arrives | `PHONE1_STATE_TTL_SECONDS` (600s) |
| `req_<hash>.json` | Dedupe gate per index.php request | `REQUEST_DEDUPE_TTL_SECONDS` (300s) |
| `call_start_<hash>.json` | Dedupe gate for call_start.php requests | `REQUEST_DEDUPE_TTL_SECONDS` (300s) |

State files expire lazily (deleted on next read). Run the cleanup script periodically to remove accumulated expired files:

```bash
# Recommended: every 30 minutes via cron
*/30 * * * * php /path/to/tops/bin/cleanup_state.php >> /path/to/tops/logs/cleanup.log 2>&1
```

---

## Troubleshooting

| Symptom | Check |
|---------|-------|
| `Server configuration missing` | `.env` exists and `INDEX_ENV`, `*_BASE_URL`, `*_API_KEY` are all non-empty |
| No upstream request sent | `ENABLE_REAL_SEND=true` in `.env` |
| TopS.II returns "call does not exist" | `callId` matches what was registered in TopS.II; `createCallStart` was sent before `createCallEnd` |
| Phone1 errorInfo missing in Not Answer | Phone1 callback must arrive before phone2; check `state` log events |
| Duplicate requests being skipped | Normal behavior for retries; check dedupe log entries; adjust TTL if needed |
| TLS errors | Verify `BASE_URL` uses a valid SSL certificate; cURL enforces SSL peer verification |

---

## Legacy endpoints

The `public/test/` and `public/prod/` POST endpoints (`createNotAnswer.php`, `createCallStart.php`, `createCallEnd.php`) are **deprecated**. Kept for reference only. The active dialer callback flow uses `index.php` and `call_start.php` exclusively.

---

## Further reading

- [docs/architecture.md](docs/architecture.md) — Full data flow, state handling, dedupe, and debugging steps
- [docs/customer-integration-guide.md](docs/customer-integration-guide.md) — Customer-facing payload reference
- [docs/customer-debugging-checklist.md](docs/customer-debugging-checklist.md) — Step-by-step issue checklist
- [docs/SETUP.md](docs/SETUP.md) — curl examples and local setup notes
- [APISPecs.md](APISPecs.md) — TopS.II Web API specification (upstream CRM)
