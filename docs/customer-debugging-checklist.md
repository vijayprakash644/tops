# Customer Debugging Checklist (CRM-side first)

Use this to validate issues before suspecting the integration.

## 1) Verify Ameyo callback payload

For `index.php`:
- `unique_id`
- `customerCRTId`
- `shareablePhonesDialIndex`
- `systemDisposition` / `dispositionCode`
- `phoneList` (two phones when applicable)
- `dialledPhone` or `dstPhone`

For `call_start.php`:
- `userId`
- `callId` or `cs_unique_id` or `crm_push_generated_time` or `sessionId`
- `phone` (or `displayPhone`, `dialledPhone`, `dstPhone`)

## 2) Check CRM accepts the data

TopS.II should accept:
- `callId` (unique_id)
- `subCtiHistoryId` (customerCRTId)
- `predictiveStaffId` (userId)
- `targetTel`
- `errorInfo1` / `errorInfo2` values

## 3) Validate TopS.II call existence

If TopS.II returns "call does not exist":
- Verify `callId` and `customerCRTId` are consistent with CRM records.
- Confirm that the Call Start API has been sent for the same callId before Call End / Not Answer.

## 4) Two-phone flow checks

Expected sequence:
- Phone1 callback arrives with `shareablePhonesDialIndex=0`
- Phone2 callback arrives with `shareablePhonesDialIndex=1`
- Only after phone2, Not Answer is sent with both errorInfo values

If phone2 never arrives, no combined Not Answer is sent.

## 5) Common causes

- Missing or wrong `customerCRTId`
- `callId` mismatch between Ameyo and TopS.II
- Phone2 callback not sent
- `userId` missing on Call End

## 6) What to provide when reporting an issue

Share these values:
- `unique_id`, `customerCRTId`, `customerId`
- `shareablePhonesDialIndex`, `phoneList`
- `systemDisposition` / `dispositionCode`
- Full callback URL
