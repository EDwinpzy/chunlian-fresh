# GeTui device binding and merchant order notifications

The backend uses GeTui REST API v2. The customer and merchant apps have separate
App IDs and credentials. App IDs may be included in the client build; App Keys
and Master Secrets must exist only in the server process environment.

## Server environment

Configure these values on the API server and the outbox worker:

| Variable | Purpose |
| --- | --- |
| `GETUI_MERCHANT_APP_ID` | Allowed App ID for `/merchant/push-devices` and merchant order notifications |
| `GETUI_MERCHANT_APP_KEY` | Merchant GeTui App Key |
| `GETUI_MERCHANT_MASTER_SECRET` | Merchant GeTui Master Secret |
| `GETUI_CUSTOMER_APP_ID` | Allowed App ID for `/customer/push-devices` |
| `GETUI_CUSTOMER_APP_KEY` | Customer GeTui App Key, reserved for customer-side pushes |
| `GETUI_CUSTOMER_MASTER_SECRET` | Customer GeTui Master Secret, reserved for customer-side pushes |
| `GETUI_HTTP_TIMEOUT_SECONDS` | Request timeout, default 8 seconds and capped at 30 seconds |
| `GETUI_PUSH_TTL_MS` | Offline message lifetime in milliseconds, default 7,200,000 (2 hours); GeTui allows up to 259,200,000 (3 days) |
| `GETUI_TOKEN_CACHE_DIR` | Optional private directory for cached access tokens; defaults to a private directory under the system temp directory |

Credentials are read from `backend/extension/config/extension.php`. Do not put
secrets in `.env.example`, source files, client configuration, build constants,
or API request bodies. Deploy the environment variables to both PHP-FPM/API and
the CLI worker; process supervisors must be restarted after changing them.

## API contract

- `POST /api/v1/merchant/push-devices`
- `DELETE /api/v1/merchant/push-devices/{installation_id}`
- `POST /api/v1/customer/push-devices`
- `DELETE /api/v1/customer/push-devices/{installation_id}`

Registration takes `{ "app_id": "...", "cid": "...", "installation_id": "..." }`.
The authenticated session supplies the account identity. The backend accepts
only the role's configured App ID and ignores any actor ID in a request. The
`(app_id, installation_id)` unique key makes registration idempotent and allows
a refreshed CID to replace the previous one. DELETE is idempotent and only
revokes a device owned by the authenticated account.

The app must obtain the user's explicit privacy consent before initializing the
GeTui SDK or calling a registration endpoint. The Android app also checks the
system notification permission before initializing merchant push. Device
registration stores an existing CID; it does not request or grant OS permission.
For the merchant Android build, configure `GETUI_APP_ID` and the public HTTPS
`MERCHANT_PRIVACY_POLICY_URL` in `local.properties` for development or as Gradle
properties for a release build. The app keeps GeTui disabled if the privacy URL
is blank or invalid, even if notification permission has been granted.
The customer endpoint is currently unused by the customer app and does not enable
customer order notifications.

Run migration `2026_10_17_000009_create_veg_push_device.sql` before enabling the
API routes. The customer endpoint currently stores and revokes customer devices
only; customer order notifications are not enabled in this release.

## Outbox behavior

Only `ORDER_CREATED` is sent as a system notification. The request includes the
GeTui notification for connected clients and an Android vendor notification for
offline delivery. Offline delivery requires the matching vendor push SDK/token
and channel credentials to be configured for the app in the GeTui console; without
that setup, a message may wait in GeTui until the client reconnects. The existing in-app
merchant notification feed is shop-wide, the order record has no shop/admin
recipient column, and all enabled `shopxo_admin` accounts can read the feed.
Therefore the worker targets registered merchant devices owned by enabled
admins. It never targets customer app IDs. If no enabled admin has a registered
merchant device, the event completes without an external request.

If registered merchant devices exist but the configured App ID does not match,
or the merchant App Key/Master Secret is absent, the worker leaves the event
retryable and eventually marks it `DEAD` after the configured retry limit. It
does not report a successful push in that case. GeTui transport failures,
non-success HTTP responses, malformed JSON, non-zero provider codes, and invalid
CID result data also use the outbox retry/backoff policy.

Access tokens are cached in files with restricted permissions, refreshed before
expiration, and refreshed once more when GeTui returns error `10001`. Every
push HTTP attempt, including that token-refresh retry, uses a fresh 32-character
request ID because GeTui warns that reusing an ID can cause message loss. The
worker takes a MySQL named lock and also claims each event atomically by changing
`PENDING` to `PROCESSING` with a per-worker marker. It refreshes the row lease
before each provider call. A new worker reclaims abandoned `PROCESSING` events
after five minutes, counts the lease expiry as a retry, and applies the configured
backoff/max-retry policy; updates from an expired worker are rejected.

After GeTui returns a terminal CID status, the worker checkpoints a SHA-256 hash
of that CID and the status in the outbox payload before sending to the next
device. A retry skips checkpointed CIDs and only sends to remaining devices.
GeTui documents `successed_offline` and `successed_online` as delivery statuses;
`successed_ignore` means a user inactive for 90 days was not sent the message.
That status is checkpointed as terminal to avoid repeatedly trying the same
inactive CID.

Delivery is still at-least-once around the external request boundary. If GeTui
accepts a push but the response times out, the process stops before its CID
checkpoint, or the checkpoint write fails, a later retry may send that CID
again. The provider does not document a transaction that atomically combines
push acceptance with this database checkpoint. Notifications contain only the
generic text “有新订单 / 您有一笔新订单，请打开商家端查看。”; order number,
customer name, phone, address, and item details are not sent.

GeTui confirms that a request was accepted or gives a CID status; it does not
prove that a particular phone displayed the notification. Offline vendor
delivery also depends on the app's GeTui vendor-channel configuration and device
notification permission.

## Official references

- [REST API v2 authentication](https://docs.getui.com/getui/server/rest_v2/token/)
- [REST API v2 push API](https://docs.getui.com/getui/server/rest_v2/push/)
- [REST API v2 common parameters](https://docs.getui.com/getui/server/rest_v2/common_args/)
