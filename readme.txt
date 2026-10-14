=== Waypoint WiPay for WooCommerce ===
Contributors: waypoint
Tags: woocommerce, payment-gateway, wipay, hosted-checkout
Requires at least: 6.4
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent WooCommerce gateway that redirects customers to WiPay's hosted card checkout.

This is a Sandbox testing release. Classic checkout and WooCommerce Checkout Blocks have been exercised on Waypoint's staging store. The webhook, refund and reconciliation features added in 1.1.0 have not yet been exercised against WiPay sandbox. Live WiPay processing has not been tested; do not use this release to accept live payments.

== Description ==

This independent plugin is maintained by Waypoint and is not affiliated with, sponsored by, or endorsed by WiPay. Card details are entered on WiPay's hosted checkout page; this plugin does not collect card numbers or CVV values.

Payments are only completed after the server verifies WiPay's response hash using the exact order total pinned when checkout starts. Signed webhooks, full-order refunds and on-demand transaction reconciliation are available once their server-side constants are configured. This software is provided under the GPL-2.0-or-later license without warranty; see LICENSE.

== Installation ==

1. Install and activate WooCommerce.
2. Install this plugin and open WooCommerce > Settings > Payments > Waypoint WiPay.
3. Enable the gateway and leave Environment set to Sandbox.
4. Choose the WiPay processing platform associated with your merchant account and save.
5. Place sandbox orders to review successful and declined/failed payment flows. Keep this release in Sandbox; live processing is outside the scope of this release and has not been tested.

WiPay's current Payments API documentation publishes sandbox account `1234567890` and sandbox response-verification key `123`. The plugin uses these test values only when Sandbox is selected. Live payments require the store owner's verified WiPay Business account.

== Supported platform codes ==

This version offers BB, GD, GY, JM, and TT, matching WiPay's current published Payments API platform list. St. Lucia is not listed as a Payments API platform in the documentation reviewed for this release.

== Configuration ==

- Environment: Sandbox for this testing release. Live processing has not been tested.
- WiPay platform: BB, GD, GY, JM, or TT.
- Live account number: your own merchant account number.
- Fee structure: merchant_absorb, customer_pay, or split.
- Live API key: server-side `WAYPOINT_WIPAY_API_KEY` constant in `wp-config.php`; the key is not stored in plugin settings or printed into JavaScript.
- Diagnostics: optional, off by default. Logs contain event names, order IDs, and HTTP status codes only; customer data, request/response bodies, hashes, and keys are not logged.

The plugin supports classic WooCommerce checkout and WooCommerce Checkout Blocks, and declares HPOS compatibility. Classic and Blocks sandbox payments have been exercised on Waypoint's staging store; each merchant should complete its own sandbox checkout review. Webhooks, refunds and reconciliation are new in 1.1.0 and have not been exercised against WiPay sandbox; work through TESTING.md before relying on them. Live WiPay processing has not been tested.

Email delivery is handled by the store's WordPress, WooCommerce, and hosting configuration. Merchants should test WooCommerce order and status emails separately; SMTP setup is not part of this payment gateway.

== Webhooks, refunds and reconciliation ==

Signed webhooks: register the endpoint shown on the gateway settings screen on the WiPay dashboard Developer page, and define WAYPOINT_WIPAY_WEBHOOK_SECRET in wp-config.php. Events are authenticated with HMAC-SHA256 over the raw request body, rejected outside a five minute timestamp window, and de-duplicated by event id. A webhook can never complete an order whose recorded transaction id does not match the event.

Refunds: define WAYPOINT_WIPAY_WAPI_KEY in wp-config.php to refund from the WooCommerce order screen. WiPay documents a refund request with no amount parameter, so only full order refunds are accepted and partial refunds are refused. A refund request does not confirm that funds have returned - watch for the payment.refunded or payment.refund_rejected webhook event.

Reconciliation: run the Reconcile with WiPay order action to fetch the transaction's current state and record the comparison as an order note. It reports discrepancies and never completes or refunds an order on its own.

== Disclaimer ==

This software is provided "as is", without warranty of any kind. Waypoint accepts no liability for any loss, damages, payment disputes, or other claims arising from use of this plugin. The merchant is responsible for testing, configuration, compliance, and reconciliation. This is not legal, financial, or PCI compliance advice.

WiPay is a trademark of its respective owner. Mention of WiPay identifies the payment service this independent plugin connects to and does not imply endorsement.

== Changelog ==

= 1.1.0 =
* Add signed webhook handling for payment, chargeback, refund and fraud events, with HMAC-SHA256 verification, a five minute timestamp window and event id deduplication.
* Add full order refunds through WiPay's WAPI refund request; partial refunds are refused because WiPay documents no amount parameter.
* Add a Reconcile with WiPay order action that records the transaction's current state as an order note.
* Move the platform and host map into a shared Waypoint_WiPay_Platforms class.

= 1.0.4 =
* Add the required accessibility label for WooCommerce Checkout Blocks.
* Read gateway presentation settings from WooCommerce's payment method data.

= 1.0.3 =
* Fix WooCommerce Checkout Blocks payment method detection so the gateway is available in block checkout.

= 1.0.2 =
* Add the WooCommerce Checkout Blocks server and client payment method integration.
* Declare Cart and Checkout Blocks compatibility while retaining classic checkout support.

= 1.0.1 =
* Send the Payments API request as JSON, matching WiPay's documented request format.
* Omit dependent customer prefill fields when their required parent field is absent.

= 1.0.0 =
* Initial Waypoint-maintained release.

