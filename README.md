# Waypoint WiPay for WooCommerce

An independent WooCommerce payment gateway that sends customers to WiPay's hosted card checkout. It is maintained by Waypoint and is not affiliated with, sponsored by, or endorsed by WiPay.

## Status

**Sandbox testing release, now at 1.1.0.** Version 1.0.4 completed classic checkout and WooCommerce Checkout Blocks sandbox payment testing on staging; live WiPay processing has not been tested. Version 1.1.0 adds signed webhooks, full-order refunds and transaction reconciliation — these are implemented and unit-tested, but **have not yet been exercised against WiPay sandbox**. Work through [TESTING.md](TESTING.md) before relying on them. Installers must keep the gateway in Sandbox; this release is not for accepting live payments.

## Safety design

- The checkout request amount is read from the WooCommerce order on the server and pinned before the API request.
- Successful payment requires the exact order ID, an order with a recorded started attempt, the exact transaction ID returned when the attempt began, and a valid WiPay hash calculated from the pinned order total. The total returned to the browser is ignored.
- Missing or invalid hashes leave an order unpaid. Repeated callbacks do not complete a paid order again.
- Failed/cancelled responses can only update the same started, unpaid order while it is pending or on hold.
- Late successful payments for cancelled/failed/refunded orders or orders with insufficient stock are recorded in order metadata and an owner-facing note for manual review.
- Webhooks are authenticated with HMAC-SHA256 over the raw request body, rejected outside a five-minute timestamp window, and de-duplicated by event ID. A webhook can never complete an order whose recorded transaction ID does not match the event.
- API keys are not sent to WiPay's request endpoint, stored in plugin settings, or localized into browser code. Live key configuration uses a server-side constant.
- Diagnostics are disabled by default and redact customer and payment payload data.

## Installation and setup

1. Install WordPress and WooCommerce.
2. Copy this folder into `wp-content/plugins/` and activate it.
3. Open WooCommerce → Settings → Payments → Waypoint WiPay, enable the gateway, and keep Environment set to **Sandbox**.
4. Choose the platform for the merchant account and save. Sandbox uses WiPay's published `1234567890` test account and `123` response-verification key.
5. Place sandbox orders to review the checkout flow, including a successful payment and a declined/failed payment. Do not switch this release to Live.

### Server-side constants

All three constants belong in `wp-config.php`. None are stored in plugin settings or exposed to browser code.

```php
// Response-hash verification for live payments.
define( 'WAYPOINT_WIPAY_API_KEY', 'your-live-key' );

// WAPI key for refunds and reconciliation. Environment-specific; sandbox
// keys are prefixed wapi_test_ and cannot authenticate against live.
define( 'WAYPOINT_WIPAY_WAPI_KEY', 'your-wapi-key' );

// Signing secret for inbound webhooks, shown when the endpoint is created
// or rotated on the WiPay dashboard Developer page.
define( 'WAYPOINT_WIPAY_WEBHOOK_SECRET', 'your-signing-secret' );
```

Never commit `wp-config.php` or disclose any of these values.

Live setup is outside the scope of this sandbox testing release. A later release must be verified for live processing before merchants use live credentials or accept real payments.

### Webhooks

The gateway settings screen prints your store's webhook endpoint. Register it on the WiPay dashboard Developer page so it is publicly reachable over HTTPS, then define `WAYPOINT_WIPAY_WEBHOOK_SECRET`. Until the secret is set the endpoint answers `503` so WiPay keeps retrying rather than dropping events.

Events handled: `payment.success`, `payment.failed`, `payment.error`, `payment.chargeback_pending`, `payment.chargeback_processed`, `payment.chargeback_released`, `payment.refund_requested`, `payment.refunded`, `payment.refund_rejected`, `payment.fraud_confirmed`, and `payment.created`. Unknown event types are acknowledged and ignored so a new WiPay event cannot cause an endless retry loop.

### Refunds

WooCommerce's Refund button is enabled. Refunds go through WiPay's WAPI refund request, which documents **no amount parameter**, so only full-order refunds are accepted — partial refunds are refused with a clear error. A refund request does **not** confirm that funds have returned: the outcome arrives later as `payment.refunded` or `payment.refund_rejected`. Watch for those events before treating funds as returned.

### Reconciliation

The order edit screen has a **Reconcile with WiPay** action that fetches the transaction's current state from WAPI and records the comparison as an order note, flagging amount mismatches and status disagreements. It reports and never acts: it will not complete or refund an order on its own.

## API behavior

The plugin uses `POST https://{country-host}/plugins/payments/request` with an `application/json` request body and `Accept: application/json`, matching WiPay's current examples. The request includes documented fields such as `account_number`, `country_code`, `currency`, `environment`, `fee_structure`, `method`, `order_id`, `origin`, `response_url`, and `total`. Billing prefill uses `addr1`/`addr2`; dependent `lname` and `addr2` values are omitted unless their required `fname` and `addr1` are present. WiPay's response is verified on the server using its documented MD5 formula and the original order total.

WAPI calls reuse the same country host with `/wapi/...` paths and authenticate with an `X-WAPI-Key` header.

The endpoint host map lives in [`includes/class-waypoint-wipay-platforms.php`](includes/class-waypoint-wipay-platforms.php) and is shared by payment requests and WAPI calls. The TT pair is published on WiPay's Platforms and Environments page; **re-confirm the JM, BB, GY and GD hostnames before release.**

Email delivery is handled by the store's WordPress, WooCommerce, and hosting configuration. Merchants should test order and status emails separately; SMTP setup is not part of this payment gateway.

## Limits

- Refunds are full-order only. WiPay documents no partial-refund amount parameter.
- A refund request is asynchronous; the plugin cannot force a refund to complete.
- Chargeback webhooks place the order on hold and restore the previous status when released, but the merchant still reconciles disputes in WiPay.
- Stock availability is checked when a successful callback arrives; if the order is no longer payable or stock is insufficient, the plugin records an owner-facing flag/note and does not complete fulfillment automatically.
- The merchant must review WiPay settlement and reconcile refunds/disputes manually.

## Development

```bash
php -l wipay-waypoint.php
php tests/run-tests.php
```

[`tests/run-tests.php`](tests/run-tests.php) loads the plugin against small WordPress/WooCommerce stand-ins, so it runs without a WordPress install. It covers webhook signature verification, timestamp tolerance, replay rejection, event deduplication, the platform/host map, WAPI request construction, the refund guards, and the order completion guards. It does not prove WooCommerce integration or reach WiPay — see [TESTING.md](TESTING.md).

## License and disclaimer

GPL-2.0-or-later. See [LICENSE](LICENSE). Waypoint accepts no liability for use of this software. It is supplied without warranty. WiPay does not endorse this plugin.

## References

- [WiPay Payments API](https://docs.wipayfinancial.com/payments-api)
- [Payment Request](https://docs.wipayfinancial.com/payments-api/payment-request)
- [Transaction Response and hash verification](https://docs.wipayfinancial.com/payments-api/transaction-response)
- [Payments API webhook events](https://docs.wipayfinancial.com/webhooks/payments-api)
- [WAPI refund request](https://docs.wipayfinancial.com/wapi/transactions/createRefundRequest)
- [WAPI transaction retrieval](https://docs.wipayfinancial.com/wapi/transactions/retrieveTransaction)
- [Platforms and Environments](https://docs.wipayfinancial.com/platforms-and-environments)
