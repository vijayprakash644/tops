# Customer Integration Guide (Ameyo -> PHP Relay -> TopS.II)

This guide is for customer and TopS.II teams. It explains what Ameyo sends, how the relay routes calls, and what TopS.II receives.

## Scope

- Ameyo Dialer sends GET callbacks to the relay.
- Relay sends Web API requests to TopS.II.
- Two-phone flows use local state (no DB dependency).

## Endpoints (relay)

- `GET /index.php` (Call End + Not Answer)
- `GET /call_start.php` (Call Start)

## Immediate response

The relay always returns quickly:

```json
{"success":true,"message":"Data Received"}
```

This is only an ACK. Processing continues after the ACK is sent.

## Call Start flow

### Required fields from Ameyo
- `userId`
- One of: `callId`, `cs_unique_id`, `crm_push_generated_time`, `sessionId`
- One of: `phone`, `displayPhone`, `dialledPhone`, `dstPhone`

### Relay -> TopS.II

`POST /fasthelp5-server/service/callmanage/predictiveCallApiService/createCallStart.json`

Payload:
```json
{
  "predictiveCallCreateCallStart": {
    "callId": "string",
    "predictiveStaffId": "string",
    "targetTel": "string"
  }
}
```

## Call End / Not Answer flow

### Required fields from Ameyo
- `unique_id` (used as `callId`)
- `customerCRTId` (required for Call End)
- `systemDisposition` (e.g., CONNECTED / CALL_NOT_PICKED / BUSY)
- `shareablePhonesDialIndex` (0 for phone1, 1 for phone2)
- `phoneList` (JSON string with phone1 + phone2 if two-phone dialing)

### Routing rules

- **Phone1 connected** (`systemDisposition=CONNECTED`, `shareablePhonesDialIndex=0`)
  - Send **Call End** with `subCtiHistoryId = customerCRTId`
  - No errorInfo
- **Phone2 connected** (`systemDisposition=CONNECTED`, `shareablePhonesDialIndex>=1`)
  - Send **Call End** with phone1 errorInfo (if stored)
  - `subCtiHistoryId = customerCRTId`
- **Not connected**
  - **Single phone**: send **Not Answer** immediately with `errorInfo1 = current status`
  - **Two phones**:
    - Phone1 callback (dialIndex=0): store phone1 status and wait
    - Phone2 callback (dialIndex>=1): combine phone1 + phone2 statuses and send **Not Answer**

### Relay -> TopS.II (Call End)

`POST /fasthelp5-server/service/callmanage/predictiveCallApiService/createCallEnd.json`

Payload:
```json
{
  "predictiveCallCreateCallEnd": {
    "callId": "string",
    "callStartTime": "YYYY-MM-DD HH:MM:SS",
    "callEndTime": "YYYY-MM-DD HH:MM:SS",
    "subCtiHistoryId": "customerCRTId",
    "targetTel": "string",
    "predictiveStaffId": "string",
    "errorInfo": "string (optional)"
  }
}
```

### Relay -> TopS.II (Not Answer)

`POST /fasthelp5-server/service/callmanage/predictiveCallApiService/createNotAnswer.json`

Payload:
```json
{
  "predictiveCallCreateNotAnswer": {
    "callId": "string",
    "callTime": "YYYY-MM-DD HH:MM:SS",
    "errorInfo1": "string",
    "errorInfo2": "string (optional)"
  }
}
```

## Sequence diagram

See `docs/sequence-diagram.svg` and `docs/architecture.md`.

## Configuration (relay)

The relay uses:
- `TEST_BASE_URL` / `PROD_BASE_URL`
- `TEST_API_KEY` / `PROD_API_KEY`
- `INDEX_ENV` (`TEST` or `PROD`)
- `ENABLE_REAL_SEND` (`true` to send upstream)

## Important notes for customer teams

- Ameyo may retry callbacks; dedupe prevents duplicate upstream calls.
- Two-phone flows rely on the phone1 callback arriving before phone2.
- `customerCRTId` is required for Call End; if missing, the relay will reject the request.
