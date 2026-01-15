# TopS.II Predictive Dialer API Relay (Plain PHP)

This folder provides 6 endpoints (test/prod) that relay the 3 TopS.II Web APIs.

## Files
- `public/test/createNotAnswer.php`
- `public/test/createCallStart.php`
- `public/test/createCallEnd.php`
- `public/prod/createNotAnswer.php`
- `public/prod/createCallStart.php`
- `public/prod/createCallEnd.php`

## Setup
1) Copy `.env.example` to `.env` and fill in your values.
2) Deploy the `public/` folder to your PHP server as the web root.

## Test locally (example)
Start PHP built-in server from project root:

```bash
php -S localhost:8000 -t public
```

### Example calls
Not Answered (test):

```bash
curl -X POST http://localhost:8000/test/createNotAnswer.php \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode 'jsonData={"predictiveCallCreateNotAnswer":{"callId":99999999,"callTime":"2025-08-11 11:03:23","errorInfo1":"NO_ANSWER"}}'
```

Call Start (test):

```bash
curl -X POST http://localhost:8000/test/createCallStart.php \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode 'jsonData={"predictiveCallCreateCallStart":{"callId":99999999,"predictiveStaffId":"ABCD1234","targetTel":"03000000001"}}'
```

Call End (test):

```bash
curl -X POST http://localhost:8000/test/createCallEnd.php \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode 'jsonData={"predictiveCallCreateCallEnd":{"callId":99999999,"callStartTime":"2025-07-11 11:22:33","callEndTime":"2025-07-11 11:23:44","subCtiHistoryId":"ABCDE-FGHIJK-1234","targetTel":"03000000001","predictiveStaffId":"ABCD1234"}}'
```

Replace `test` with `prod` for production endpoints.

## Notes
- These endpoints only accept `POST` with `jsonData` form parameter.
- Upstream API always returns HTTP 200; this relay preserves that behavior.
- Keep `.env` out of source control.