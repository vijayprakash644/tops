# Setup and Local Testing Guide

This guide covers local development setup, environment configuration, and curl examples for testing the relay manually.

---

## Prerequisites

- PHP 8.1+ (with cURL extension enabled)
- Write access to the `logs/` directory under the project root

---

## 1. Environment setup

Copy the example config and fill in your values:

```bash
cp .env.example .env
```

Edit `.env`:

```ini
# Which environment to use (TEST or PROD)
INDEX_ENV=TEST

# TopS.II API server base URLs (no trailing slash)
TEST_BASE_URL=https://your-test-tops-server.example.com
PROD_BASE_URL=https://your-prod-tops-server.example.com

# 32-character Web API keys from TopS.II admin
TEST_API_KEY=your32charalphanumerictestapikey
PROD_API_KEY=your32charalphanumericprodapikey

# Set to true only when ready to send real requests
ENABLE_REAL_SEND=false
```

> **Keep `ENABLE_REAL_SEND=false` during development.** In this mode, the relay builds and logs every payload but does not send anything to TopS.II. Set to `true` when deploying to production.

---

## 2. Start the local server

Serve the project root (not `public/`):

```bash
php -S localhost:8000
```

The relay will be available at:
- `http://localhost:8000/index.php` — Call End / Not Answer
- `http://localhost:8000/call_start.php` — Call Start

---

## 3. Test curl examples

All callbacks use `GET`. The relay responds immediately with `{"success":true,"message":"Data Received"}`.

Check `logs/` for the full payload that was built.

---

### Call Start

```bash
curl -v "http://localhost:8000/call_start.php?\
cs_unique_id=99999999\
&userId=ABCD1234\
&displayPhone=03000000001\
&crtObjectId=d336-test-vce-1\
&customerId=100"
```

Expected log: `logs/call_start-YYYY-MM-DD.log`
Expected TopS.II payload:
```json
{
  "predictiveCallCreateCallStart": {
    "callId": 99999999,
    "predictiveStaffId": "ABCD1234",
    "targetTel": "03000000001"
  }
}
```

---

### Call End — Phone1 connected (single phone)

```bash
curl -v "http://localhost:8000/index.php?\
unique_id=99999999\
&customerId=100\
&customerCRTId=d336-test-vce-1\
&systemDisposition=CONNECTED\
&callResult=SUCCESS\
&shareablePhonesDialIndex=0\
&userId=ABCD1234\
&dialledPhone=03000000001\
&phoneList=%7B%22phoneList%22%3A%5B%2203000000001%22%5D%7D\
&callConnectedTime=2026%2F03%2F06+13%3A20%3A05+%2B0900"
```

Expected TopS.II payload:
```json
{
  "predictiveCallCreateCallEnd": {
    "callId": 99999999,
    "callStartTime": "2026-03-06 13:20:05",
    "callEndTime": "<processing time>",
    "subCtiHistoryId": "d336-test-vce-1",
    "targetTel": "03000000001",
    "predictiveStaffId": "ABCD1234"
  }
}
```

---

### Call End — Phone2 connected (after phone1 failed via hangupCauseCode)

First, simulate the phone1 pre-dial failure:

```bash
curl -v "http://localhost:8000/index.php?\
unique_id=99999999\
&customerId=100\
&customerCRTId=d336-test-vce-1\
&hangupCauseCode=487\
&dialedPhone=03000000001\
&phoneList=%7B%22phoneList%22%3A%5B%2203000000001%22%2C%2203000000002%22%5D%7D"
```

Then simulate the phone2 call end (connected):

```bash
curl -v "http://localhost:8000/index.php?\
unique_id=99999999\
&customerId=100\
&customerCRTId=d336-test-vce-1\
&systemDisposition=CONNECTED\
&callResult=SUCCESS\
&shareablePhonesDialIndex=1\
&userId=ABCD1234\
&cstmPhone=03000000002\
&phoneList=%7B%22phoneList%22%3A%5B%2203000000001%22%2C%2203000000002%22%5D%7D\
&callConnectedTime=2026%2F03%2F06+13%3A21%3A00+%2B0900"
```

Expected TopS.II payload (second request):
```json
{
  "predictiveCallCreateCallEnd": {
    "callId": 99999999,
    "callStartTime": "2026-03-06 13:21:00",
    "callEndTime": "<processing time>",
    "subCtiHistoryId": "d336-test-vce-1",
    "targetTel": "03000000002",
    "predictiveStaffId": "ABCD1234",
    "errorInfo": "PROVIDER_TEMP_FAILURE"
  }
}
```

---

### Not Answer — Single phone

```bash
curl -v "http://localhost:8000/index.php?\
unique_id=99999999\
&customerId=100\
&customerCRTId=d336-test-vce-1\
&systemDisposition=NUMBER_TEMP_FAILURE\
&callResult=FAILURE\
&shareablePhonesDialIndex=0\
&phoneList=%7B%22phoneList%22%3A%5B%2203000000001%22%5D%7D"
```

Expected TopS.II payload:
```json
{
  "predictiveCallCreateNotAnswer": {
    "callId": 99999999,
    "callTime": "<processing time>",
    "errorInfo1": "NUMBER_TEMP_FAILURE"
  }
}
```

---

### Not Answer — Both phones failed

First, phone1 failure:

```bash
curl -v "http://localhost:8000/index.php?\
unique_id=99999999\
&customerId=100\
&customerCRTId=d336-test-vce-1\
&systemDisposition=PROVIDER_TEMP_FAILURE\
&callResult=FAILURE\
&shareablePhonesDialIndex=0\
&phoneList=%7B%22phoneList%22%3A%5B%2203000000001%22%2C%2203000000002%22%5D%7D"
```

Then, phone2 failure:

```bash
curl -v "http://localhost:8000/index.php?\
unique_id=99999999\
&customerId=100\
&customerCRTId=d336-test-vce-1\
&systemDisposition=CALL_DROP\
&callResult=FAILURE\
&shareablePhonesDialIndex=1\
&cstmPhone=03000000002\
&phoneList=%7B%22phoneList%22%3A%5B%2203000000001%22%2C%2203000000002%22%5D%7D"
```

Expected TopS.II payload (second request):
```json
{
  "predictiveCallCreateNotAnswer": {
    "callId": 99999999,
    "callTime": "<processing time>",
    "errorInfo1": "PROVIDER_TEMP_FAILURE",
    "errorInfo2": "CALL_DROP"
  }
}
```

---

## 4. Reading the logs

Logs are written to `logs/` daily:

```bash
# Watch call_end log in real time
tail -f logs/call_end-2026-03-06.log

# Find all entries for a specific callId
grep "unique_id=99999999\|callId=99999999" logs/call_end-2026-03-06.log

# Find all entries for a specific request
grep "a9232bf392f03e8d" logs/call_start-2026-03-06.log
```

Each entry includes a `request_id` (16-char hex) that links all log lines for a single request.

---

## 5. State file inspection

State files are stored under `logs/state/`. You can inspect them directly:

```bash
ls logs/state/
cat logs/state/phone1_<hash>.json
```

To clean up expired state files manually:

```bash
php bin/cleanup_state.php
```

---

## 6. Production deployment checklist

- [ ] Set `ENABLE_REAL_SEND=true` in `.env`
- [ ] Set `INDEX_ENV=PROD` in `.env`
- [ ] Fill in real `PROD_BASE_URL` and `PROD_API_KEY`
- [ ] Ensure `logs/` and `logs/state/` are writable by the web server user
- [ ] Confirm `logs/` is not publicly accessible (deny in nginx/Apache config)
- [ ] Schedule `bin/cleanup_state.php` via cron (every 30 minutes recommended)
- [ ] Set up log rotation for `logs/*.log` (daily, retain 30 days)
- [ ] Verify PHP cURL can reach the TopS.II server (SSL certificate must be valid)
- [ ] Confirm `fastcgi_finish_request()` is available in your PHP-FPM environment (enables true async response)

---

## 7. Legacy POST endpoints (deprecated)

The `public/test/` and `public/prod/` directories contain deprecated POST-based endpoints for the three TopS.II APIs. These are kept for reference only and are not part of the active Ameyo callback flow.

If you need to test these legacy endpoints directly:

```bash
# Start server pointed at public/
php -S localhost:8000 -t public

# Not Answered (legacy)
curl -X POST http://localhost:8000/test/createNotAnswer.php \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode 'jsonData={"predictiveCallCreateNotAnswer":{"callId":99999999,"callTime":"2025-08-11 11:03:23","errorInfo1":"NO_ANSWER"}}'
```

> **Do not use these endpoints for new Ameyo dialer integrations.** Use `index.php` and `call_start.php` instead.
