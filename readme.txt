=== Waypoint WiPay for WooCommerce ===
Contributors: waypoint
Tags: woocommerce, payment-gateway, wipay, hosted-checkout
Requires at least: 6.4
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent WooCommerce gateway that redirects customers to WiPay's hosted card checkout.

This is a Sandbox testing release. Classic checkout and WooCommerce Checkout Blocks have been exercised on Waypoint's staging store. Live WiPay processing has not been tested; do not use this release to accept live payments.

== Description ==

This independent plugin is maintained by Waypoint and is not affiliated with, sponsored by, or endorsed by WiPay. Card details are entered on WiPay's hosted checkout page; this plugin does not collect card numbers or CVV values.

Payments are only completed after the server verifies WiPay's response hash using the exact order total pinned when checkout starts. Refund processing is not supported in this release. This software is provided under the GPL-2.0-or-later license without warranty; see LICENSE.

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

The plugin supports classic WooCommerce checkout and WooCommerce Checkout Blocks, and declares HPOS compatibility. Classic and Blocks sandbox payments have been exercised on Waypoint's staging store; each merchant should complete its own sandbox checkout review. Refunds must be handled through the merchant's WiPay account and recorded in WooCommerce manually. Live WiPay processing has not been tested.

Email delivery is handled by the store's WordPress, WooCommerce, and hosting configuration. Merchants should test WooCommerce order and status emails separately; SMTP setup is not part of this payment gateway.

== Disclaimer ==

This software is provided "as is", without warranty of any kind. Waypoint accepts no liability for any loss, damages, payment disputes, or other claims arising from use of this plugin. The merchant is responsible for testing, configuration, compliance, and reconciliation. This is not legal, financial, or PCI compliance advice.

WiPay is a trademark of its respective owner. Mention of WiPay identifies the payment service this independent plugin connects to and does not imply endorsement.

== Changelog ==

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

