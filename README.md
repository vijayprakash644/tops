# TopS.II Predictive Dialer Relay

A tiny PHP relay that receives GET callbacks from an external dialer system and forwards them to TopS.II Web APIs.

## Non-technical overview
- Acts like a mailroom: it receives call status updates and forwards the right TopS.II API.
- Keeps responses simple: upstream always returns HTTP 200; the real success/fail flag is inside the JSON body.

## How it works (short)
1) An external dialer calls `index.php` with query params (`GET`).
2) The relay maps those fields to TopS.II payloads, validates required fields, and forwards to the configured TopS.II URL.
3) Whatever TopS.II returns is passed straight back to the caller (status 200) when sending is enabled.

## Endpoint
Primary endpoint:

- `GET /index.php`

Routing rules:
- If `systemDisposition=CONNECTED`, it sends TopS.II Call End.
- Otherwise it sends TopS.II Not Answer.

Field mapping:
- `unique_id` -> `callId`
- `userId` -> `predictiveStaffId`
- `dialledPhone` (fallback: `dstPhone`) -> `targetTel`
- `dispositionCode` -> `errorInfo1`

Optional fields:
- `callStartTime`, `callEndTime`, `subCtiHistoryId` (used for Call End; defaults apply if missing)
- `callTime` (used for Not Answer; defaults to now if missing)

## Quick start (tech)
1) Copy `.env.example` to `.env` and fill in URLs and API keys.
2) Serve the repo root. Example:
   ```bash
   php -S localhost:8000
   ```
3) Call `http://localhost:8000/index.php?...` with your dialer query params.

## Sample calls
- Not answered
  ```text
  /index.php?unique_id=99999999&systemDisposition=NO_ANSWER&dispositionCode=NO_ANSWER
  ```
- Call end
  ```text
  /index.php?unique_id=99999999&systemDisposition=CONNECTED&userId=ABCD1234&dialledPhone=03000000001
  ```

## Configuration (.env)
- `TEST_BASE_URL` / `PROD_BASE_URL` Base URL of your TopS.II server (no trailing slash).
- `TEST_API_KEY` / `PROD_API_KEY` 32-character Web API access keys.
- `INDEX_ENV` Set to `TEST` or `PROD` (default: `TEST`).
- `ENABLE_REAL_SEND` Set to `true` to send to TopS.II; default is `false` (logs only).

## Behavior and errors
- HTTP status is always 200 to mirror TopS.II; check `result` in the JSON body for `success` or `fail`.
- The relay returns validation errors if required fields are missing before it calls TopS.II.

## Project layout
- `index.php` Primary GET endpoint for dialer callbacks.
- `src/` Bootstrap, validation, HTTP client, and index handler.
- `docs/SETUP.md` Setup steps and example curl calls.
- `APISPecs.md` Vendor API specification excerpt.

## Legacy endpoints
The old `public/test` and `public/prod` POST endpoints are still available but not used by the current dialer callback flow.

## Troubleshooting
- Get `Server configuration missing`: ensure `.env` is present and values are non-empty.
- TLS issues: verify the `BASE_URL` uses a valid cert; cURL is set to verify peers.

## Further reading
See [docs/SETUP.md](docs/SETUP.md) for detailed curl examples and operational notes.
