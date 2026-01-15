TopS.II Web-API Design Document (Excerpt for Predictive Dialer Integration)
September 19, 2025
Version 1.0
TechMatrix Corporation

CONFIDENTIAL
Table of Contents
1. Overview
  1-1. Background
  1-2. List of Integration Requirements
  1-3. API List
2. Integration Sequence
  2-1. Outbound Automatic Dialing
3. Common API Specifications
  3-1. Communication Specifications
  3-2. Response Specifications
  3-3. Error Specifications
4. API Specifications
  4-1. Outbound Auto Dialing: Not Answered Registration
  4-2. Outbound Auto Dialing: Call Start Registration
  4-3. Outbound Auto Dialing: Call End Registration

1. Overview
1-1. Background
By customizing the standard Web-API functionality of FastHelp, online integration with the linked system (AmeyoJ) is achieved.
1-2. List of Integration Requirements
The following lists the integration requirements using the Web-API functionality.
Outbound Automatic Dialing: In order to integrate with predictive dialers for outbound auto dialing, APIs are provided to register non-answered calls (not connected), pop up calls on operators’ screens at the start of a call, and register call information upon call termination.
1-3. API List
The following lists the APIs and their corresponding linked systems:
- Outbound Auto Dialing Not Answered Registration: Registers information for calls that were not answered or resulted in outbound errors.
- Outbound Auto Dialing Call Start Registration: Registers call start information and pops up the call in TopS.II.
- Outbound Auto Dialing Call End Registration: Registers call termination information.
2. Integration Sequence
2-1. Outbound Automatic Dialing
- Not Answered: Calls the Outbound Auto Dialing Not Answered Registration API to record information about non-answered calls or call errors.
 
- Call Start: Calls the Outbound Auto Dialing Call Start Registration API to record the start of the call and pop up the call in TopS.II.


- Call End: Calls the Outbound Auto Dialing Call End Registration API to record call termination information.

3. Common API Specifications
3-1. Communication Specifications
Communication Method: HTTP Request (SSL)
HTTP Method: POST
Content-Type: application/x-www-form-urlencoded
Character Encoding: UTF-8
Request Parameter Format: JSON format (set to the key 'jsonData' after URL-encoding)
Response Format: JSON
Authentication Method: 'X-FastHelp-API-Key' in request header (32-character alphanumeric key registered in TopS.II).
Notes: Even when errors occur, the HTTP status code will always be 200 if the API is reached. Therefore, error judgment must combine both HTTP status code and the result field in the response.
3-2. Response Specifications
The response format is as follows:
- count: Number of hits (set to -1 for non-list APIs)
- data: API-specific response
- limit: Retrieved count (set to -1 for non-list APIs)
- offset: Start position (set to -1 for non-list APIs)
- result: 'success' or 'fail'
- service: The requested API
- size: Number of data items
- message / messageCode: Error message and code
- subMessage / subMessageCode: Sub error message and code
- exception: Exception information
- errors: Array of errors (for FastHelp standard screens, not for external system use)
3-3. Error Specifications
Error codes and messages:
- WTYP0010: Logical Error (logical issue occurred)
- WTYP0020: License Error (license limit exceeded; not expected in external system integration)
- WTYP0030: Authentication Error (WebAPI access key authentication failed)
- ETYP0040: Application Error (internal application error, such as type mismatch)

Notes:
Except for logical errors, external systems should uniformly handle errors as 'System Error'.
For logical errors, external systems must distinguish based on sub-error codes.
Some messages may be displayed to users, depending on the API design.

4. API Specifications
4-1. Outbound Auto Dialing Not Answered Registration
Registers information for calls that were not answered or resulted in call errors.
4-1-1. Request Specifications
Request URL: https://xxx/fasthelp5-server/service/callmanage/predictiveCallApiService/createNotAnswer.json
(Replace 'xxx' with the FQDN of the TopS.II AP server in the DMZ).
Parameters:
- callId (Number, required): Call ID
- callTime (String, required): Call time (YYYY-MM-DD HH:mm:ss)
- errorInfo1 (String, required): Error information for the first phone number
- errorInfo2 (String, optional): Error information for the second phone number
Request Parameter Example (Not answered by a client who has only one number):
{
  "predictiveCallCreateNotAnswer": {
    "callId": 99999999,
    "callTime": "2025-08-11 11:03:23",
    "errorInfo1": "NO_ANSWER"
  }
}
Request Parameter Example (Not answered by a client who has two numbers):
{
  "predictiveCallCreateNotAnswer": {
    "callId": 99999999,
    "callTime": "2025-08-11 11:03:23",
    "errorInfo1": "NO_ANSWER",
    "errorInfo2": "PROVIDER_TEMP_FAILURE"
  }
}
4-1-2. Response Specifications
Successful Response (Example):
{
  "count": -1,
  "limit": -1,
  "offset": -1,
  "result": "success",
  "service": "/callmanage/predictiveCallApiService/createNotAnswer.json",
  "size": 1
}
Error Response (call does not exist) (Example):
{
  "count": -1,
  "limit": -1,
  "offset": -1,
  "result": "fail",
  "service": "/callmanage/predictiveCallApiService/createNotAnswer.json",
  "size": -1,
  "message": "Logical Error",
  "messageCode": "WTYP0010",
  "subMessage": "Call does not exist.",
  "subMessageCode": "WPRDEXTC3201",
  "exception": "jp.co.techmatrix.fasthelp.framework.LogicalCheckFailureException : [WPRDEXTC3201] Call does not exist."
}
4-1-3. Logical Error Specifications
- WPRDEXTC3201: Call does not exist. (Occurs when the call is not registered in TopS.II).
4-2. Outbound Auto Dialing Call Start Registration
Registers call start information and pops up the call in TopS.II.
4-2-1. Request Specifications
Request URL: https://xxx/fasthelp5-server/service/callmanage/predictiveCallApiService/createCallStart.json
Parameters:
- callId (Number, required): Call ID
- predictiveStaffId (String, required): ID of predictive dialing staff
- targetTel (String, required): Destination phone number
Request Parameter Example:
{
  "predictiveCallCreateCallStart": {
    "callId": 99999999,
    "predictiveStaffId": "ABCD1234",
    "targetTel": "03000000001"
  }
}
4-2-2. Response Specifications
Successful Response (Example):
{
  "count": -1,
  "limit": -1,
  "offset": -1,
  "result": "success",
  "service": "/callmanage/predictiveCallApiService/createCallStart.json",
  "size": 1
}
Error Response (staff not logged in) (Example):
{
  "count": -1,
  "limit": -1,
  "offset": -1,
  "result": "fail",
  "service": "/callmanage/predictiveCallApiService/createCallStart.json",
  "size": -1,
  "message": "Logical Error",
  "messageCode": "WTYP0010",
  "subMessage": "Staff is not logged in.",
  "subMessageCode": "WPRDEXTC3203",
  "exception": "jp.co.techmatrix.fasthelp.framework.LogicalCheckFailureException : [WPRDEXTC3203] Staff is not logged in."
}
4-2-3. Logical Error Specifications
<Sub error code>
- WPRDEXTC3201: Call does not exist. (occurs when the call is not registered in TopS.II)
- WPRDEXTC3202: Staff cannot be identified. (occurs when staff is identified)
- WPRDEXTC3203: Staff is not logged in. (occurs when staff is not logged in TopS.II)
4-3. Outbound Auto Dialing Call End Registration
Registers call termination information.
4-3-1. Request Specifications
Request URL: https://xxx/fasthelp5-server/service/callmanage/predictiveCallApiService/createCallEnd.json
Parameters:
- callId (Number, required): Call ID
- callStartTime (String, required): Call start time (YYYY-MM-DD HH:mm:ss)
- callEndTime (String, required): Call end time (YYYY-MM-DD HH:mm:ss)
- subCtiHistoryId (String, required): Identifier of the call
- targetTel (String, required): Target phone number
- predictiveStaffId (String, required): Predictive dialing staff ID
- errorInfo (String, optional): Error info for the first phone number if the second number succeeded
Request Parameter Example (1st phone number connected, call ended):
{
  "predictiveCallCreateCallEnd": {
    "callId": 99999999,
    "callStartTime": "2025-07-11 11:22:33",
    "callEndTime": "2025-07-11 11:23:44",
    "subCtiHistoryId": "ABCDE-FGHIJK-1234",
    "targetTel": "03000000001",
    "predictiveStaffId": "ABCD1234"
  }
}
Example Request (2nd phone number connected, 1st failed):
{
  "predictiveCallCreateCallEnd": {
    "callId": 99999999,
    "callStartTime": "2025-07-11 11:22:33",
    "callEndTime": "2025-07-11 11:23:44",
    "subCtiHistoryId": "ABCDE-FGHIJK-1234",
    "targetTel": "03000000001",
    "predictiveStaffId": "ABCD1234",
    "errorInfo": "NO_ANSWER"
  }
}
4-3-2. Response Specifications
Successful Response (Example):
{
  "count": -1,
  "limit": -1,
  "offset": -1,
  "result": "success",
  "service": "/callmanage/predictiveCallApiService/createCallEnd.json",
  "size": 1
}
Error Response (call does not exist) (Example):
{
  "count": -1,
  "limit": -1,
  "offset": -1,
  "result": "fail",
  "service": "/callmanage/predictiveCallApiService/createCallEnd.json",
  "size": -1,
  "message": "Logical Error",
  "messageCode": "WTYP0010",
  "subMessage": "Call does not exist.",
  "subMessageCode": "WPRDEXTC3201",
  "exception": "jp.co.techmatrix.fasthelp.framework.LogicalCheckFailureException : [WPRDEXTC3201] Call does not exist."
}
4-3-3. Logical Error Specifications
<Sub Error Code>
- WPRDEXTC3201: Call does not exist. (Occurs when the call is not registered in TopS.II).

