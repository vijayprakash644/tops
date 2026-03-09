# Customer Debugging Checklist

Use this checklist to validate an integration issue before escalating. Work through the steps in order — most issues are resolved by step 3.

---

## Step 1 — Verify Ameyo sent the right fields

### For `index.php` (Call End / Not Answer)

Confirm these fields are present and non-empty in the Ameyo callback:

| Field | Required for | Notes |
|-------|-------------|-------|
| `unique_id` | All flows | Becomes `callId` |
| `customerId` | All flows | Used for state file scoping |
| `customerCRTId` | Call End only | Becomes `subCtiHistoryId`; missing = request rejected |
| `systemDisposition` | All standard callbacks | e.g. `CONNECTED`, `PROVIDER_TEMP_FAILURE` |
| `shareablePhonesDialIndex` | Two-phone flows | `0` = phone1, `1` = phone2 |
| `phoneList` | Two-phone flows | JSON string, e.g. `{"phoneList":["090...","080..."]}` |
| `callConnectedTime` | Call End | Format: `YYYY/MM/DD HH:mm:ss +0900` |
| `userId` | Call End | Becomes `predictiveStaffId` |
| `dialledPhone` / `dialedPhone` | Call End (phone1) | Becomes `targetTel` |
| `cstmPhone` | Call End (phone2) | Becomes `targetTel` when `dialIndex >= 1` |
| `hangupCauseCode` | Pre-dial phone1 failure | Alternative to `systemDisposition`; numeric SIP code |

### For `call_start.php` (Call Start)

| Field | Required | Notes |
|-------|----------|-------|
| `userId` | Yes | Becomes `predictiveStaffId` |
| `cs_unique_id` *(or `callId`, `crm_push_generated_time`, `sessionId`)* | Yes | First non-empty value is used as `callId` |
| `displayPhone` | Yes | Becomes `targetTel`; only this field is used |

---

## Step 2 — Confirm the relay received the callback

1. Open the relevant daily log file:
   - Call End: `logs/call_end-YYYY-MM-DD.log`
   - Not Answer: `logs/not_answer-YYYY-MM-DD.log`
   - Call Start: `logs/call_start-YYYY-MM-DD.log`

2. Search for the `unique_id` / call ID. Every request logs an `incoming_get` entry with the full query params.

3. Use the `request_id` value from `incoming_get` to trace all log entries for that single request.

4. Confirm the `decision` entry shows the expected routing outcome (e.g. `Phone1 connected -> createCallEnd`).

---

## Step 3 — Check if TopS.II received the correct data

1. Find the `upstream_request` log entry — it shows the full payload sent to TopS.II.
2. Find `payload_prepared` — confirm `send_enabled=1` (means `ENABLE_REAL_SEND=true`).
3. Find `http_client` — check `http_code` (should be `200`) and `error` (should be empty).
4. Find `upstream_response` — check `result: success` or `result: fail`.

If `result: fail`, check the `subMessageCode`:

| Sub-error code | Meaning | Action |
|----------------|---------|--------|
| `WPRDEXTC3201` | Call does not exist in TopS.II | Verify `callId` matches a registered call; ensure `createCallStart` was sent first |
| `WPRDEXTC3202` | Staff cannot be identified | `predictiveStaffId` (`userId`) does not match any TopS.II user account |
| `WPRDEXTC3203` | Staff is not logged in | User account exists but is not currently logged into TopS.II |

---

## Step 4 — Check dedupe behavior (if request appears skipped)

1. Search the log for `dedupe | Skipped duplicate request`.
2. If found, the request was a duplicate within the dedupe window (default: 300 seconds).
3. This is expected behavior when Ameyo retries a callback.
4. If a valid request is being incorrectly blocked, check `REQUEST_DEDUPE_TTL_SECONDS` in `.env`.

---

## Step 5 — Check two-phone flow state (if errorInfo is missing or wrong)

For Not Answer with two phones:
1. Look for `state | Stored phone1 status; waiting for phone2` in the `not_answer` log.
2. On the phone2 callback, look for `decision | Not connected -> createNotAnswer (errorInfo1+2)`.
3. Confirm `phone1_state_used=1` in the decision log.

For Call End where phone2 connected:
1. Look for the `hangupCauseCode` or phone1 failure callback in the `not_answer` log — it stores the state.
2. On the phone2 call end, look for `decision | Phone2 connected -> createCallEnd` with `resolvedPhone1=<status>`.

If `resolvedPhone1` shows `PROVIDER_TEMP_FAILURE` when it should be something else, check whether the correct `hangupCauseCode` or `systemDisposition` was sent in the phone1 callback.

---

## Step 6 — Common root causes

| Symptom | Most likely cause |
|---------|-----------------|
| No log entry found | Ameyo callback did not reach the relay; check network / firewall |
| `payload_prepared: send_enabled=0` | `ENABLE_REAL_SEND=false` in `.env`; must be `true` for production |
| `Missing required fields: customerCRTId` | `customerCRTId` was empty or missing in the Ameyo callback |
| `errorInfo1=UNKNOWN` in Not Answer | Phone2 callback arrived before phone1; or phone1 state expired |
| `Skipped duplicate request` on every call | Ameyo is retrying within dedupe window; verify Ameyo retry config |
| `result: fail` from TopS.II | See sub-error code table in Step 3 |
| Phone1 errorInfo missing in Call End | Phone1 `hangupCauseCode` callback was not sent or arrived late |
| Wrong `targetTel` in Call End | Check which phone connected; `dialledPhone` (phone1) vs `cstmPhone` (phone2) |

---

## Step 7 — What to provide when escalating an issue

Include the following in your report:

- `unique_id` and `customerCRTId`
- `customerId` and `shareablePhonesDialIndex`
- `phoneList` value
- `systemDisposition` and/or `hangupCauseCode`
- The full raw callback URL (or query string)
- The `request_id` from the relay log
- The relevant log lines (`incoming_get`, `decision`, `upstream_request`, `upstream_response`)
