# TopS.II Predictive Dialer Relay

A tiny PHP relay that forwards three Ameyo predictive dialer events to TopS.II Web APIs. It exposes paired **test** and **prod** endpoints so you can verify integrations safely before touching live data.

## Non-technical overview
- Acts like a mailroom: it receives three kinds of call events (not answered, call start, call end) and forwards them to TopS.II.
- Provides separate doors for testing and production so you can try things without risk.
- Keeps responses simple: upstream always returns HTTP 200; the real success/fail flag is inside the JSON body.

## How it works (short)
1) An external dialer sends a `POST` with a JSON payload in a `jsonData` form field.
2) The relay validates required fields, adds the `X-FastHelp-API-Key` header, and forwards to the configured TopS.II URL.
3) Whatever TopS.II returns is passed straight back to the caller (status 200).

## Endpoints
Under `public/` there are six PHP entrypoints:

| Purpose | Test URL | Prod URL | Payload root |
|---------|----------|----------|--------------|
| Not answered / error | `/test/createNotAnswer.php` | `/prod/createNotAnswer.php` | `predictiveCallCreateNotAnswer` |
| Call start (screen-pop) | `/test/createCallStart.php` | `/prod/createCallStart.php` | `predictiveCallCreateCallStart` |
| Call end | `/test/createCallEnd.php` | `/prod/createCallEnd.php` | `predictiveCallCreateCallEnd` |

All endpoints expect `POST`, `Content-Type: application/x-www-form-urlencoded`, with a `jsonData` field containing JSON.

## Quick start (tech)
1) Copy `.env.example` to `.env` and fill in URLs and API keys.
2) Serve the `public/` folder. Example from repo root:
   ```bash
   php -S localhost:8000 -t public
   ```
3) Hit the test endpoints first, then switch to `/prod/*` when ready.

## Sample payloads
- Not answered
  ```json
  {"predictiveCallCreateNotAnswer":{"callId":99999999,"callTime":"2025-08-11 11:03:23","errorInfo1":"NO_ANSWER"}}
  ```
- Call start
  ```json
  {"predictiveCallCreateCallStart":{"callId":99999999,"predictiveStaffId":"ABCD1234","targetTel":"03000000001"}}
  ```
- Call end
  ```json
  {"predictiveCallCreateCallEnd":{"callId":99999999,"callStartTime":"2025-07-11 11:22:33","callEndTime":"2025-07-11 11:23:44","subCtiHistoryId":"ABCDE-FGHIJK-1234","targetTel":"03000000001","predictiveStaffId":"ABCD1234"}}
  ```

## Configuration (.env)
- `TEST_BASE_URL` / `PROD_BASE_URL` – Base URL of your TopS.II server (no trailing slash).
- `TEST_API_KEY` / `PROD_API_KEY` – 32-character Web API access keys.

## Behavior and errors
- HTTP status is always 200 to mirror TopS.II; check `result` in the JSON body for `success` or `fail`.
- The relay returns validation errors if required fields are missing before it calls TopS.II.

## Project layout
- `public/` – Web-exposed entrypoints for test/prod.
- `src/` – Bootstrap, validation, HTTP client, and handlers.
- `docs/SETUP.md` – Setup steps and example curl calls.
- `APISPecs.md` – Vendor API specification excerpt.

## Troubleshooting
- Get `Server configuration missing`: ensure `.env` is present and values are non-empty.
- Get `Missing jsonData payload`: send `jsonData` as form field or raw body.
- TLS issues: verify the `BASE_URL` uses a valid cert; cURL is set to verify peers.

## Further reading
See [docs/SETUP.md](docs/SETUP.md) for detailed curl examples and operational notes.
