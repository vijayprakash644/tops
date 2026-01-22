# TopS.II Predictive Dialer Relay

A tiny PHP relay that receives GET callbacks from an external dialer system and forwards them to the CRM (TopS.II) Web APIs.

## Non-technical overview
- Acts like a mailroom: it receives call status updates and forwards the right CRM API.
- Replies immediately with a simple JSON ack, then continues processing in the background.
- Keeps a lightweight state file per call to decide what to send for phone1/phone2.

## How it works (short)
1) An external dialer calls `index.php` with query params (`GET`).
2) The relay logs the request, returns `{"success": true, "message": "Data Received"}`, then keeps processing.
3) It maps fields to the correct CRM API and sends the request when enabled.

## Endpoints
Primary endpoints:

- `GET /index.php` (call end + not answer)
- `GET /call_start.php` (call start)

Routing rules for `index.php`:
- Phone1 connected (`systemDisposition=CONNECTED` and `shareablePhonesDialIndex=0`) -> Call End (no errorInfo).
- Phone2 connected (`systemDisposition=CONNECTED` and `shareablePhonesDialIndex>=1`) -> Call End with phone1 errorInfo (from state/DB).
- Not connected -> Not Answer with errorInfo1 (and errorInfo2 if phone2 attempted).

Call start mapping (`call_start.php`):
- `callId` from `callId`, or `unique_id`, or `crm_push_generated_time`, or `sessionId`.
- `predictiveStaffId` from `userId`.
- `targetTel` from `phone`, or `displayPhone`, or `dialledPhone`, or `dstPhone`.

Field mapping (key inputs for `index.php`):
- `unique_id` -> `callId`
- `userId` -> `predictiveStaffId`
- `dialledPhone` (fallback: `dstPhone`, or `cstmPhone` for dialIndex>=1) -> `targetTel`
- `systemDisposition` / `dispositionCode` -> status/errorInfo
- `phoneList` (JSON), `phone1`, `phone2`, `cstmPhone` -> phone list/state
- `shareablePhonesDialIndex`, `numAttempts` -> decision logic
- `customerId` + first phone -> DB lookup for phone1 status

Optional fields:
- `callStartTime`, `callEndTime`, `subCtiHistoryId` (Call End; defaults apply if missing)
- `callTime` (Not Answer; defaults to now if missing)

## Immediate response
Both endpoints respond with:

```json
{"success":true,"message":"Data Received"}
```

Errors are logged (not returned), because processing continues after the response.

## Quick start (tech)
1) Copy `.env.example` to `.env` and fill in URLs and API keys.
2) Serve the repo root. Example:
   ```bash
   php -S localhost:8000
   ```
3) Call `http://localhost:8000/index.php?...` or `http://localhost:8000/call_start.php?...`.

## Sample calls
- Not answered
  ```text
  /index.php?unique_id=99999999&systemDisposition=NO_ANSWER&dispositionCode=NO_ANSWER
  ```
- Call end (phone1)
  ```text
  /index.php?unique_id=99999999&systemDisposition=CONNECTED&shareablePhonesDialIndex=0&userId=ABCD1234&dialledPhone=03000000001
  ```
- Call end (phone2)
  ```text
  /index.php?unique_id=99999999&systemDisposition=CONNECTED&shareablePhonesDialIndex=1&userId=ABCD1234&cstmPhone=03000000002&phoneList={"phoneList":["03000000001","03000000002"]}&customerId=29
  ```
- Call start
  ```text
  /call_start.php?crm_push_generated_time=1766985685673&userId=testex1&phone=07038173460
  ```

## Configuration (.env)
- `TEST_BASE_URL` / `PROD_BASE_URL` Base URL of your CRM API server (no trailing slash).
- `TEST_API_KEY` / `PROD_API_KEY` 32-character Web API access keys.
- `INDEX_ENV` Set to `TEST` or `PROD` (default: `TEST`).
- `ENABLE_REAL_SEND` Set to `true` to send upstream; default is `false` (logs only).
- `DB_LOOKUP_SLEEP_SECONDS` Sleep before retrying DB lookup.
- `PG_HOST`, `PG_DB`, `PG_USER`, `PG_PASSWORD`, `PG_PORT` Postgres connection for phone1 status lookup.

## Logging
- Log files (rotated daily):
  - `logs/call_end-YYYY-MM-DD.log`
  - `logs/not_answer-YYYY-MM-DD.log`
  - `logs/call_start-YYYY-MM-DD.log`
  - `logs/general-YYYY-MM-DD.log`
- Timezone: `Asia/Tokyo`
- Each request includes a `request_id` to correlate entries.

## State files
- Stored under `logs/state/` by `unique_id`.
- Keeps phone list and per-dial-index status so phone2 can include phone1 failure.

## Legacy endpoints
The `public/test` and `public/prod` POST endpoints are deprecated. We keep the code for reference, but the current dialer callback flow uses `index.php` only.

## Troubleshooting
- Get `Server configuration missing`: ensure `.env` is present and values are non-empty.
- No DB lookup results: confirm `customerId`, first phone, and the same-day `date_added` filter.
- TLS issues: verify the `BASE_URL` uses a valid cert; cURL is set to verify peers.

## Further reading
See [docs/SETUP.md](docs/SETUP.md) for detailed curl examples and operational notes.
