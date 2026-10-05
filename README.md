# Waypoint WiPay for WooCommerce

An independent WooCommerce payment gateway that sends customers to WiPay's hosted card checkout. It is maintained by Waypoint and is not affiliated with, sponsored by, or endorsed by WiPay.

## Status

**Sandbox testing release:** Version 1.0.4 has completed classic checkout and WooCommerce Checkout Blocks sandbox payment testing on staging. Live WiPay processing has not been tested. Installers must keep the gateway in Sandbox; this release is not for accepting live payments.

## Safety design

- The checkout request amount is read from the WooCommerce order on the server and pinned before the API request.
- Successful payment requires the exact order ID, an order with a recorded started attempt, the exact transaction ID returned when the attempt began, and a valid WiPay hash calculated from the pinned order total. The total returned to the browser is ignored.
- Missing or invalid hashes leave an order unpaid. Repeated callbacks do not complete a paid order again.
- Failed/cancelled responses can only update the same started, unpaid order while it is pending or on hold.
- Late successful payments for cancelled/failed/refunded orders or orders with insufficient stock are recorded in order metadata and an owner-facing note for manual review.
- API keys are not sent to WiPay's request endpoint, stored in plugin settings, or localized into browser code. Live key configuration uses a server-side constant.
- Diagnostics are disabled by default and redact customer and payment payload data.

## Installation and setup

1. Install WordPress and WooCommerce.
2. Copy this folder into `wp-content/plugins/` and activate it.
3. Open WooCommerce → Settings → Payments → Waypoint WiPay, enable the gateway, and keep Environment set to **Sandbox**.
4. Choose the platform for the merchant account and save. Sandbox uses WiPay's published `1234567890` test account and `123` response-verification key.
5. Place sandbox orders to review the checkout flow, including a successful payment and a declined/failed payment. Do not switch this release to Live.

Live setup is outside the scope of this sandbox testing release. A later release must be verified for live processing before merchants use live credentials or accept real payments. The live API key, when applicable, belongs only in server-side `wp-config.php`:

```php
define( 'WAYPOINT_WIPAY_API_KEY', 'your-live-key' );
```

Never commit `wp-config.php` or disclose a live key.

The endpoint host is selected from WiPay's documented country/environment map. This version supports TT, JM, BB, GY, and GD. It enforces the supplied TTD 5.00 minimum for the TT/TTD combination. It supports classic checkout and WooCommerce Checkout Blocks. Automated refunds are not supported.

Email delivery is handled by the store's WordPress, WooCommerce, and hosting configuration. Merchants should test order and status emails separately; SMTP setup is not part of this payment gateway.

## API behavior

The plugin uses `POST https://{country-host}/plugins/payments/request` with an `application/json` request body and `Accept: application/json`, matching WiPay's current examples. The endpoint and sandbox host mapping already matched the documentation. The request includes documented fields such as `account_number`, `country_code`, `currency`, `environment`, `fee_structure`, `method`, `order_id`, `origin`, `response_url`, and `total`. Billing prefill uses `addr1`/`addr2`; dependent `lname` and `addr2` values are omitted unless their required `fname` and `addr1` are present. WiPay's response is verified on the server using its documented MD5 formula and the original order total.

## Limits

- No automated refunds, chargeback webhooks, or transaction retrieval reconciliation.
- Stock availability is checked when a successful callback arrives; if the order is no longer payable or stock is insufficient, the plugin records an owner-facing flag/note and does not complete fulfillment automatically.
- The merchant must review WiPay settlement and reconcile refunds/disputes manually.

## License and disclaimer

GPL-2.0-or-later. See [LICENSE](LICENSE). Waypoint accepts no liability for use of this software. It is supplied without warranty. WiPay does not endorse this plugin.

## References

- [WiPay Payments API](https://docs.wipayfinancial.com/payments-api)
- [Payment Request](https://docs.wipayfinancial.com/payments-api/payment-request)
- [Transaction Response and hash verification](https://docs.wipayfinancial.com/payments-api/transaction-response)
- [Platforms and Environments](https://docs.wipayfinancial.com/platforms-and-environments)


