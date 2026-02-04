Phone Number Upload API (Teijin)
================================

English
-------

Overview
This API accepts phone records from third-party vendors and uploads them to the campaign system.

Endpoint
- URL: /receivedata1/index.php
- Method: POST
- Content-Type: application/json
- Auth: X-API-Key header
- HTTPS required

Rate Limit
- 1000 requests per hour per IP

Request Body
The request body is JSON and must include an array named customerRecords.

Required fields (per record)
- phone (10-15 digits)
- unique_id
- type (leadId)

Optional fields
- phone2 (10-15 digits)

Example Request
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-api-key-here" \
  -d '{
        "customerRecords": [
          {
            "phone": "1234567890",
            "phone2": "0987654321",
            "unique_id": "abc123",
            "type": "lead-1"
          }
        ]
      }' \
  https://your-domain.com/teijin/index.php

Response
Always returns an array of result objects.

Example Response
[
  {
    "success": true,
    "message": "Successfully inserted",
    "unique_id": "abc123"
  }
]

Error Responses
Examples:
- {"error":"Authentication required"}
- {"error":"HTTPS required. Use https://"}
- {"error":"Content-Type must be application/json."}
- {"error":"Invalid request method. Only POST is allowed."}
- {"success":false,"error":"Rate limit exceeded","unique_id":"abc123"}

Status Codes
- 200: Success
- 400: Bad Request
- 401: Unauthorized
- 403: HTTPS Required
- 405: Method Not Allowed
- 413: Payload Too Large
- 415: Unsupported Media Type
- 429: Rate Limit Exceeded

Configuration (.env)
Create apicc/teijin/.env from apicc/teijin/.env.example and fill in real values.
This file is ignored by git.

Remove Contacts API
-------------------

Overview
Fetches contacts from Ameyo using getContacts, logs in to get a sessionId, and then removes all matching customerIds.

Endpoint
- URL: /receivedata1/remove_contacts.php
- Method: POST
- Content-Type: application/json
- Auth: X-API-Key header
- HTTPS required

Request Body
Either leadIds (array) or leadId (single) is required. leadId values must exist in LEAD_CAMPAIGNS_JSON.

Example Request
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-api-key-here" \
  -d '{
        "leadIds": ["8"],
        "chooseEnabledLeads": true
      }' \
  https://your-domain.com/receivedata1/remove_contacts.php

Response
Returns lead-level results and removeContactsFromCampaign results.

Required .env keys for this API
- GET_CONTACTS_URL
- REMOVE_CONTACTS_URL
- LOGIN_URL
- LOGIN_TOKEN
- LOGIN_USER_ID

Scheduled Removal (All Leads)
-----------------------------

Use the CLI helper to call the remove API with all leadIds from LEAD_CAMPAIGNS_JSON.

Example Cron (midnight)
0 0 * * * /usr/bin/php /path/to/apicc/teijin/run_remove_all.php >> /var/log/remove_contacts_cron.log 2>&1

Required .env keys for the CLI helper
- REMOVE_ENDPOINT_URL
- API_KEY


日本語
------

概要
本APIはベンダーから電話番号を受け取り、キャンペーンシステムに登録します。

エンドポイント
- URL: /receivedata1/index.php
- メソッド: POST
- Content-Type: application/json
- 認証: X-API-Key ヘッダー
- HTTPS 必須

レート制限
- IPごとに1時間あたり1000リクエスト

リクエストボディ
JSON形式で customerRecords 配列を指定します。

必須項目（1レコード）
- phone（10-15桁）
- unique_id
- type（leadId）

任意項目
- phone2（10-15桁）

リクエスト例
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-api-key-here" \
  -d '{
        "customerRecords": [
          {
            "phone": "1234567890",
            "phone2": "0987654321",
            "unique_id": "abc123",
            "type": "lead-1"
          }
        ]
      }' \
  https://your-domain.com/receivedata1/index.php

レスポンス
常に配列で返します。

レスポンス例
[
  {
    "success": true,
    "message": "Successfully inserted",
    "unique_id": "abc123"
  }
]

エラー例
- {"error":"Authentication required"}
- {"error":"HTTPS required. Use https://"}
- {"error":"Content-Type must be application/json."}
- {"error":"Invalid request method. Only POST is allowed."}
- {"success":false,"error":"Rate limit exceeded","unique_id":"abc123"}

ステータスコード
- 200: 成功
- 400: 不正なリクエスト
- 401: 認証エラー
- 403: HTTPS必須
- 405: メソッド不許可
- 413: リクエストサイズ超過
- 415: サポートされないメディアタイプ
- 429: レート制限超過

設定 (.env)
apicc/teijin/.env.example をコピーして apicc/teijin/.env を作成し、値を設定してください。
このファイルはgit管理対象外です。
