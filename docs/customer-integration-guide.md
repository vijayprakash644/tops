# Customer Integration Guide — Ameyo → PHP Relay → TopS.II

This guide is for customer and TopS.II teams. It explains what Ameyo sends to the relay, how the relay decides which API to call, and exactly what TopS.II receives.

---

## Scope

- Ameyo Dialer sends GET callbacks to the PHP relay.
- The relay immediately acknowledges, then asynchronously forwards the correct TopS.II Web API call.
- Two-phone flows use local state files — no database dependency.

---

## Relay endpoints

| Endpoint | Handles |
|----------|---------|
| `GET /index.php` | Call End (`createCallEnd`) and Not Answer (`createNotAnswer`) |
| `GET /call_start.php` | Call Start (`createCallStart`) |

---

## Immediate response

The relay always responds immediately with:

```json
{"success": true, "message": "Data Received"}
```

This is an acknowledgment only. Processing continues after this response is sent. Errors that occur during background processing are logged but not returned.

---

## Call Start flow (`call_start.php`)

### Required fields from Ameyo

| Field | Description |
|-------|-------------|
| `userId` | Staff identifier (becomes `predictiveStaffId`) |
| `cs_unique_id` *(or `callId`, `crm_push_generated_time`, `sessionId`)* | Call identifier — first non-empty value is used |
| `displayPhone` | The phone number shown on the agent screen (becomes `targetTel`) |

### What the relay sends to TopS.II

`POST /fasthelp5-server/service/callmanage/predictiveCallApiService/createCallStart.json`

```json
{
  "predictiveCallCreateCallStart": {
    "callId": 193421,
    "predictiveStaffId": "teijin_sv2",
    "targetTel": "08099366675"
  }
}
```

> **`callId` is a Number (integer)** — not a string. This matches the TopS.II API specification.

---

## Call End / Not Answer flow (`index.php`)

### Required fields from Ameyo

| Field | Description |
|-------|-------------|
| `unique_id` | Call identifier (becomes `callId`) |
| `customerId` | Customer identifier (used to scope state files) |
| `customerCRTId` | Required for Call End — becomes `subCtiHistoryId` |
| `systemDisposition` | Outcome: `CONNECTED`, `PROVIDER_TEMP_FAILURE`, `NUMBER_TEMP_FAILURE`, `CALL_DROP`, etc. |
| `shareablePhonesDialIndex` | `0` for phone1 attempt, `1` for phone2 attempt |
| `phoneList` | JSON-encoded array of phone numbers for this customer |
| `callConnectedTime` | Connection timestamp (format: `YYYY/MM/DD HH:mm:ss +0900`) — required for Call End |
| `userId` | Staff identifier — required for Call End |
| `hangupCauseCode` | *(Optional)* Numeric SIP code; signals phone1 pre-dial failure |

### Routing rules

| Condition | Action |
|-----------|--------|
| `systemDisposition=CONNECTED`, `shareablePhonesDialIndex=0` | → **Call End** (phone1 connected, no errorInfo) |
| `systemDisposition=CONNECTED`, `shareablePhonesDialIndex≥1` | → **Call End** with phone1 errorInfo (from stored state) |
| Not connected, 1 phone | → **Not Answer** with `errorInfo1` |
| Not connected, 2 phones, phone1 callback (`dialIndex=0`) | → Store phone1 status; wait for phone2 callback |
| Not connected, 2 phones, phone2 callback (`dialIndex≥1`) | → **Not Answer** with `errorInfo1` + `errorInfo2` |
| `hangupCauseCode` present (any phone count ≥ 2) | → Store phone1 status; wait for phone2 callback |

---

### What the relay sends — Call End

`POST /fasthelp5-server/service/callmanage/predictiveCallApiService/createCallEnd.json`

**Phone1 connected (single or first phone):**
```json
{
  "predictiveCallCreateCallEnd": {
    "callId": 193416,
    "callStartTime": "2026-03-06 13:53:43",
    "callEndTime":   "2026-03-06 13:53:55",
    "subCtiHistoryId": "d336-69a6d5e5-vce-103",
    "targetTel": "07029109978",
    "predictiveStaffId": "teijin_sv2"
  }
}
```

**Phone2 connected (phone1 previously failed):**
```json
{
  "predictiveCallCreateCallEnd": {
    "callId": 193421,
    "callStartTime": "2026-03-06 13:20:05",
    "callEndTime":   "2026-03-06 13:21:02",
    "subCtiHistoryId": "d336-69a6d5e5-vce-101",
    "targetTel": "08099366675",
    "predictiveStaffId": "teijin_sv2",
    "errorInfo": "PROVIDER_TEMP_FAILURE"
  }
}
```

> - `callId` is a **Number** (integer).
> - `errorInfo` is only included when phone2 connected and phone1 had previously failed.
> - `subCtiHistoryId` is always the `customerCRTId` from the Ameyo callback.
> - `callStartTime` is sourced from `callConnectedTime` (converted to `YYYY-MM-DD HH:mm:ss` in Asia/Tokyo).

---

### What the relay sends — Not Answer

`POST /fasthelp5-server/service/callmanage/predictiveCallApiService/createNotAnswer.json`

**Single phone failed:**
```json
{
  "predictiveCallCreateNotAnswer": {
    "callId": 193369,
    "callTime": "2026-03-06 13:04:47",
    "errorInfo1": "PROVIDER_TEMP_FAILURE"
  }
}
```

**Both phones failed:**
```json
{
  "predictiveCallCreateNotAnswer": {
    "callId": 193386,
    "callTime": "2026-03-06 13:53:54",
    "errorInfo1": "PROVIDER_TEMP_FAILURE",
    "errorInfo2": "CALL_DROP"
  }
}
```

> - `callId` is a **Number** (integer).
> - `errorInfo2` is only included when a second phone was attempted and also failed.
> - `callTime` is the relay's processing timestamp (Asia/Tokyo).

---

## Two-phone flow — detailed sequence

### Scenario: Both phones fail → Not Answer

```
Ameyo                          Relay                         TopS.II
  |                              |                               |
  |-- phone1 callback (FAIL) --> |                               |
  |                              |-- save phone1Status to state  |
  |                              |   (waiting for phone2)        |
  |                              |                               |
  |-- phone2 callback (FAIL) --> |                               |
  |                              |-- load phone1Status           |
  |                              |-- POST createNotAnswer -----> |
  |                              |         errorInfo1 (phone1)   |
  |                              |         errorInfo2 (phone2)   |
```

### Scenario: Phone1 fails, phone2 connects → Call Start + Call End

```
Ameyo                          Relay                         TopS.II
  |                              |                               |
  |-- hangupCause callback    -> |                               |
  |   (phone1 SIP failure)       |-- save phone1Status to state  |
  |                              |                               |
  |-- call_start callback -----> |                               |
  |   (phone2 connected)         |-- POST createCallStart -----> |
  |                              |                               |
  |-- call_end callback -------> |                               |
  |   (phone2 call ends)         |-- load phone1Status           |
  |                              |-- POST createCallEnd -------> |
  |                              |         errorInfo (phone1)    |
```

---

## Field reference summary

### Ameyo → Relay → TopS.II mapping

| Ameyo field | Relay processing | TopS.II field |
|-------------|-----------------|---------------|
| `unique_id` | Cast to integer | `callId` (Number) |
| `cs_unique_id` (call_start) | Cast to integer | `callId` (Number) |
| `userId` | Direct | `predictiveStaffId` |
| `customerCRTId` | Direct | `subCtiHistoryId` |
| `dialledPhone` / `cstmPhone` | dialIndex-dependent | `targetTel` |
| `displayPhone` (call_start) | Direct | `targetTel` |
| `callConnectedTime` | Parsed from `YYYY/MM/DD HH:mm:ss +0900` → Tokyo | `callStartTime` |
| Current timestamp | Processing time | `callEndTime`, `callTime` |
| `systemDisposition` | Direct or via hangupCauseCode mapping | `errorInfo1` / `errorInfo2` / `errorInfo` |

---

## Configuration (relay)

| Variable | Description |
|----------|-------------|
| `TEST_BASE_URL` / `PROD_BASE_URL` | TopS.II server URL (no trailing slash) |
| `TEST_API_KEY` / `PROD_API_KEY` | 32-character Web API key |
| `INDEX_ENV` | `TEST` or `PROD` |
| `ENABLE_REAL_SEND` | `true` to send to TopS.II; `false` for log-only dry run |

---

## Important notes for customer and TopS.II teams

1. **`customerCRTId` is mandatory for Call End.** If it is missing, the relay will reject the request and log an error. No `createCallEnd` will be sent to TopS.II.

2. **`callId` is a Number, not a string.** The relay casts `unique_id` / `cs_unique_id` to an integer before sending. TopS.II expects a numeric value.

3. **Two-phone flows depend on callback order.** The phone1 failure callback must arrive before the phone2 callback. If phone2 arrives first, `errorInfo1` will be `UNKNOWN`.

4. **Duplicate callbacks from Ameyo are handled.** The relay's dedupe gate prevents the same callback from being forwarded more than once to TopS.II within a 300-second window.

5. **`ENABLE_REAL_SEND=false` is the safe default.** In this mode, the relay builds and logs all payloads but does not send anything to TopS.II. Set to `true` only when ready for live operation.
