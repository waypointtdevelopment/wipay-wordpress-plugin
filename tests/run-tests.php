<?php
/**
 * Standalone test harness for the Waypoint WiPay WordPress plugin.
 *
 * WordPress and WooCommerce are not installed here, so the plugin is loaded
 * against small stand-ins for the APIs it calls. That is enough to prove the
 * parts that decide whether money moves: webhook signature verification,
 * deduplication, the platform/host map, and the refund guards.
 *
 * Run:  php tests/run-tests.php
 * Exit code 0 means every assertion passed.
 */

define( 'ABSPATH', __DIR__ . '/' );

/* ------------------------------------------------------------------ */
/* WordPress / WooCommerce stand-ins                                   */
/* ------------------------------------------------------------------ */

$GLOBALS['wp_actions']     = array();
$GLOBALS['wp_filters']     = array();
$GLOBALS['wp_options']     = array();
$GLOBALS['wp_orders']      = array();
$GLOBALS['http_log']       = array();
$GLOBALS['http_response']  = array(
	'response' => array( 'code' => 200 ),
	'body'     => '{"message":"Refund request successfully created."}',
);
$GLOBALS['wp_notices']     = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['wp_actions'][ $hook ][] = $callback;
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['wp_filters'][ $hook ][] = $callback;
	return true;
}

function do_action( $hook ) {
	if ( empty( $GLOBALS['wp_actions'][ $hook ] ) ) {
		return;
	}
	foreach ( $GLOBALS['wp_actions'][ $hook ] as $callback ) {
		call_user_func_array( $callback, func_get_args() );
	}
}

function __( $text, $domain = null ) {
	return (string) $text;
}

function esc_html__( $text, $domain = null ) {
	return (string) $text;
}

function esc_html( $text ) {
	return (string) $text;
}

function home_url( $path = '' ) {
	return 'https://shop.example.com' . $path;
}

function add_query_arg( $key, $value, $url = '' ) {
	$separator = ( false === strpos( $url, '?' ) ) ? '?' : '&';
	return $url . $separator . rawurlencode( $key ) . '=' . rawurlencode( $value );
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wp_options'] ) ? $GLOBALS['wp_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['wp_options'][ $name ] = $value;
	return true;
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['http_log'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['http_response'];
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function wp_send_json( $data, $status_code = 200 ) {
	throw new RuntimeException( 'wp_send_json called unexpectedly: ' . json_encode( $data ) );
}

function wc_get_order( $id ) {
	return isset( $GLOBALS['wp_orders'][ (int) $id ] ) ? $GLOBALS['wp_orders'][ (int) $id ] : false;
}

function wc_add_notice( $message, $type = 'success' ) {
	$GLOBALS['wp_notices'][] = array( $type, $message );
}

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Stand-in for WC_Payment_Gateway.
 */
class WC_Payment_Gateway {
	public $id;
	public $method_title;
	public $method_description;
	public $has_fields;
	public $supports            = array();
	public $title;
	public $description;
	public $enabled;
	public $form_fields         = array();
	public $settings            = array();
	public $table               = '';
	public $identifier          = '';

	public function init_settings() {
		$loaded                     = get_option( 'woocommerce_' . $this->id . '_settings', array() );
		$this->settings             = is_array( $loaded ) ? $loaded : array();
		$this->settings['enabled']  = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'no';
	}

	public function get_option( $key, $default = '' ) {
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
	}

	public function process_admin_options() {
		return true;
	}

	public function admin_options() {
	}
}

/**
 * Stand-in for the WooCommerce order class, so type hints are satisfied.
 */
class WC_Order {
}

/**
 * Minimal order stand-in.
 */
class Fake_Order extends WC_Order {
	public $id              = 42;
	public $meta            = array();
	public $total           = 0;
	public $status          = 'pending';
	public $paid            = false;
	public $payment_method  = 'waypoint_wipay';
	public $notes           = array();
	public $refunds         = array();
	public $completed_txn   = '';

	public function get_id() {
		return $this->id;
	}

	public function get_meta( $key, $single = true ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	public function save() {
	}

	public function get_total( $context = null ) {
		return $this->total;
	}

	public function get_status() {
		return $this->status;
	}

	public function update_status( $status, $note = '' ) {
		$this->status = $status;
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
	}

	public function has_status( $statuses ) {
		return in_array( $this->status, (array) $statuses, true );
	}

	public function is_paid() {
		return $this->paid;
	}

	public function payment_complete( $transaction_id = '' ) {
		$this->paid          = true;
		$this->status        = 'processing';
		$this->completed_txn = $transaction_id;
	}

	public function add_order_note( $note ) {
		$this->notes[] = (string) $note;
		return 1;
	}

	public function get_payment_method() {
		return $this->payment_method;
	}

	public function get_refunds() {
		return $this->refunds;
	}

	public function get_items( $type = 'line_item' ) {
		return array();
	}

	public function get_currency() {
		return 'TTD';
	}

	public function get_billing_email() {
		return 'buyer@example.com';
	}
}

require dirname( __DIR__ ) . '/wipay-waypoint.php';

/* Fire plugins_loaded so Waypoint_WiPay_Gateway is declared. */
do_action( 'plugins_loaded' );

if ( ! class_exists( 'Waypoint_WiPay_Gateway' ) ) {
	fwrite( STDERR, "Waypoint_WiPay_Gateway was not declared\n" );
	exit( 1 );
}

/* ------------------------------------------------------------------ */
/* Tiny assertion helpers                                              */
/* ------------------------------------------------------------------ */

$GLOBALS['assertions'] = 0;
$GLOBALS['failures']   = 0;

function suite( $name ) {
	echo "\n== {$name} ==\n";
}

function ok( $condition, $label ) {
	$GLOBALS['assertions']++;
	if ( $condition ) {
		echo "  PASS  {$label}\n";
		return;
	}
	$GLOBALS['failures']++;
	echo "  FAIL  {$label}\n";
}

function same( $expected, $actual, $label ) {
	$GLOBALS['assertions']++;
	if ( $expected === $actual ) {
		echo "  PASS  {$label}\n";
		return;
	}
	$GLOBALS['failures']++;
	echo "  FAIL  {$label}\n";
	echo '        expected: ' . var_export( $expected, true ) . "\n";
	echo '        actual:   ' . var_export( $actual, true ) . "\n";
}

function error_code( $result, $label ) {
	same( $label, is_wp_error( $result ) ? $result->get_error_code() : 'no-error', $label );
}

/* ------------------------------------------------------------------ */
/* Platform and host map                                               */
/* ------------------------------------------------------------------ */

suite( 'Platform and host map' );

same( 'https://ttsb.wipayfinancial.com/plugins/payments/request', Waypoint_WiPay_Platforms::payments_endpoint( 'TT', 'sandbox' ), 'TT sandbox payments endpoint' );
same( 'https://tt.wipayfinancial.com/plugins/payments/request', Waypoint_WiPay_Platforms::payments_endpoint( 'TT', 'live' ), 'TT live payments endpoint' );
same( 'https://jmsb.wipayfinancial.com/plugins/payments/request', Waypoint_WiPay_Platforms::payments_endpoint( 'JM', 'sandbox' ), 'JM sandbox payments endpoint' );
same( 'https://bbsb.wipayfinancial.com/plugins/payments/request', Waypoint_WiPay_Platforms::payments_endpoint( 'BB', 'sandbox' ), 'BB sandbox payments endpoint' );
same( 'https://gysb.wipayfinancial.com/plugins/payments/request', Waypoint_WiPay_Platforms::payments_endpoint( 'GY', 'sandbox' ), 'GY sandbox payments endpoint' );
same( 'https://gdsb.wipayfinancial.com/plugins/payments/request', Waypoint_WiPay_Platforms::payments_endpoint( 'GD', 'sandbox' ), 'GD sandbox payments endpoint' );

same( '', Waypoint_WiPay_Platforms::payments_endpoint( 'XX', 'sandbox' ), 'unknown platform yields no endpoint' );
same( '', Waypoint_WiPay_Platforms::payments_endpoint( 'TT', 'staging' ), 'unknown environment yields no endpoint' );
same( '', Waypoint_WiPay_Platforms::host( '', '' ), 'empty inputs yield no host' );

same( 'https://ttsb.wipayfinancial.com', Waypoint_WiPay_Platforms::wapi_base( 'TT', 'sandbox' ), 'WAPI reuses the country host' );
same( '', Waypoint_WiPay_Platforms::wapi_base( 'XX', 'sandbox' ), 'unknown platform yields no WAPI base' );

ok( Waypoint_WiPay_Platforms::is_supported( 'tt' ), 'is_supported is case insensitive' );
ok( ! Waypoint_WiPay_Platforms::is_supported( 'US' ), 'an unsupported code is rejected' );
same( array( 'BB', 'GD', 'GY', 'JM', 'TT' ), Waypoint_WiPay_Platforms::codes(), 'exactly five platforms are offered' );
same( Waypoint_WiPay_Platforms::codes(), array_keys( Waypoint_WiPay_Platforms::labels() ), 'labels cover every code' );

/* ------------------------------------------------------------------ */
/* Webhook signature                                                   */
/* ------------------------------------------------------------------ */

suite( 'Webhook signature verification' );

$secret  = 'signing-secret-value';
$body    = '{"id":"evt_1","event":"payment.success","data":{"order_id":"42"}}';
$now     = 1770000000;
$stamp   = (string) $now;
$good    = 'sha256=' . hash_hmac( 'sha256', $stamp . '.' . $body, $secret );

ok( Waypoint_WiPay_Webhooks::verify_signature( $body, $good, $stamp, $secret, $now ), 'a correctly signed body verifies' );
ok( Waypoint_WiPay_Webhooks::verify_signature( $body, strtoupper( $good ), $stamp, $secret, $now ), 'an uppercase hex signature verifies' );

ok( ! Waypoint_WiPay_Webhooks::verify_signature( $body . 'x', $good, $stamp, $secret, $now ), 'a tampered body is rejected' );
ok( ! Waypoint_WiPay_Webhooks::verify_signature( $body, $good, $stamp, 'other-secret', $now ), 'a wrong secret is rejected' );
ok( ! Waypoint_WiPay_Webhooks::verify_signature( $body, $good, $stamp, '', $now ), 'an empty secret is rejected' );
ok( ! Waypoint_WiPay_Webhooks::verify_signature( $body, $good, $stamp, $secret, $now + 301 ), 'a timestamp older than five minutes is rejected' );
ok( ! Waypoint_WiPay_Webhooks::verify_signature( $body, $good, $stamp, $secret, $now - 301 ), 'a timestamp more than five minutes in the future is rejected' );
ok( Waypoint_WiPay_Webhooks::verify_signature( $body, $good, $stamp, $secret, $now + 300 ), 'a timestamp exactly at the tolerance boundary is accepted' );

ok( ! Waypoint_WiPay_Webhooks::verify_signature( $body, str_replace( 'sha256=', 'sha1=', $good ), $stamp, $secret, $now ), 'a non-sha256 signature is rejected' );
ok( ! Waypoint_WiPay_Webhooks::verify_signature( $body, substr( $good, 0, -1 ), $stamp, $secret, $now ), 'a truncated signature is rejected' );
ok( ! Waypoint_WiPay_Webhooks::verify_signature( $body, 'sha256=' . str_repeat( 'a', 64 ), $stamp, $secret, $now ), 'a well-formed but wrong signature is rejected' );
ok( ! Waypoint_WiPay_Webhooks::verify_signature( $body, $good, '', $secret, $now ), 'a missing timestamp is rejected' );
ok( ! Waypoint_WiPay_Webhooks::verify_signature( $body, $good, 'not-a-timestamp', $secret, $now ), 'a non-numeric timestamp is rejected' );
ok( ! Waypoint_WiPay_Webhooks::verify_signature( $body, '', $stamp, $secret, $now ), 'a missing signature is rejected' );

/* Replaying a captured signature with a fresh timestamp must fail. */
$replayed = 'sha256=' . hash_hmac( 'sha256', $stamp . '.' . $body, $secret );
ok( ! Waypoint_WiPay_Webhooks::verify_signature( $body, $replayed, (string) ( $now + 100 ), $secret, $now + 100 ), 'a signature cannot be replayed against a different timestamp' );

/* ------------------------------------------------------------------ */
/* Webhook deduplication                                               */
/* ------------------------------------------------------------------ */

suite( 'Webhook deduplication' );

ok( ! Waypoint_WiPay_Webhooks::was_processed( 'evt_abc' ), 'an unknown event has not been processed' );
ok( ! Waypoint_WiPay_Webhooks::was_processed( '' ), 'an empty event id is never processed' );

Waypoint_WiPay_Webhooks::mark_processed( 'evt_abc' );
ok( Waypoint_WiPay_Webhooks::was_processed( 'evt_abc' ), 'a recorded event is recognised as processed' );
ok( ! Waypoint_WiPay_Webhooks::was_processed( 'evt_other' ), 'a different event is still unprocessed' );

/* Expired entries are pruned, bounding the stored option. */
$stale = time() - ( Waypoint_WiPay_Webhooks::RETENTION + 10 );
update_option( Waypoint_WiPay_Webhooks::OPTION_EVENTS, array( 'evt_stale' => $stale ) );
Waypoint_WiPay_Webhooks::mark_processed( 'evt_new' );
ok( ! Waypoint_WiPay_Webhooks::was_processed( 'evt_stale' ), 'an expired event id is pruned' );
ok( Waypoint_WiPay_Webhooks::was_processed( 'evt_new' ), 'the new event id is retained' );

/* The store is capped so it cannot grow without bound. */
$bulk = array();
for ( $i = 0; $i <= Waypoint_WiPay_Webhooks::MAX_EVENTS; $i++ ) {
	$bulk[ 'evt_bulk_' . $i ] = time() - $i;
}
update_option( Waypoint_WiPay_Webhooks::OPTION_EVENTS, $bulk );
Waypoint_WiPay_Webhooks::mark_processed( 'evt_cap' );
ok( count( (array) get_option( Waypoint_WiPay_Webhooks::OPTION_EVENTS ) ) <= Waypoint_WiPay_Webhooks::MAX_EVENTS, 'the event store is capped' );

update_option( Waypoint_WiPay_Webhooks::OPTION_EVENTS, array() );

same( 'https://shop.example.com/?wc-api=waypoint_wipay_webhook', Waypoint_WiPay_Webhooks::endpoint_url(), 'the advertised endpoint points at the WooCommerce API route' );

/* ------------------------------------------------------------------ */
/* WAPI client                                                         */
/* ------------------------------------------------------------------ */

suite( 'WAPI client' );

ok( ! Waypoint_WiPay_WAPI::is_configured(), 'the WAPI key is unconfigured by default' );
error_code( Waypoint_WiPay_WAPI::transaction( '', 'TT', 'sandbox' ), 'waypoint_wipay_bad_transaction' );
error_code( Waypoint_WiPay_WAPI::transaction( '../../etc/passwd', 'TT', 'sandbox' ), 'waypoint_wipay_bad_transaction' );
error_code( Waypoint_WiPay_WAPI::refund( 'bad/id', 'TT', 'sandbox' ), 'waypoint_wipay_bad_transaction' );
error_code( Waypoint_WiPay_WAPI::transaction( 'SB-1-1-ok', 'XX', 'sandbox' ), 'waypoint_wipay_wapi_host' );
same( 0, count( $GLOBALS['http_log'] ), 'no HTTP request was sent while unconfigured' );

/* ------------------------------------------------------------------ */
/* Refund guards                                                       */
/* ------------------------------------------------------------------ */

suite( 'Refund guards' );

$gateway = new Waypoint_WiPay_Gateway();

$order              = new Fake_Order();
$order->total       = 100.00;
$GLOBALS['wp_orders'][42] = $order;

error_code( $gateway->process_refund( 999 ), 'waypoint_wipay_no_order' );

$order->meta['_waypoint_wipay_transaction_id'] = '';
error_code( $gateway->process_refund( 42, 100.00 ), 'waypoint_wipay_no_transaction' );

$order->meta['_waypoint_wipay_transaction_id'] = 'SB-12-1-order42';
error_code( $gateway->process_refund( 42, 50.00 ), 'waypoint_wipay_partial_refund' );
error_code( $gateway->process_refund( 42, 100.02 ), 'waypoint_wipay_partial_refund' );

/* A full refund with no WAPI key must not silently succeed. */
error_code( $gateway->process_refund( 42, 100.00 ), 'waypoint_wipay_no_wapi_key' );
same( 0, count( $GLOBALS['http_log'] ), 'no HTTP request was sent without a WAPI key' );

/* A full refund with a key reaches the documented endpoint. */
define( 'WAYPOINT_WIPAY_WAPI_KEY', 'wapi_test_key_123' );
ok( Waypoint_WiPay_WAPI::is_configured(), 'the WAPI key is picked up from the constant' );

$GLOBALS['http_log'] = array();
$result              = $gateway->process_refund( 42, 100.00, 'customer request' );
same( true, $result, 'a full refund returns success when WiPay accepts the request' );
same( 1, count( $GLOBALS['http_log'] ), 'exactly one request was sent' );
same( 'https://ttsb.wipayfinancial.com/wapi/transactions/SB-12-1-order42/refund', $GLOBALS['http_log'][0]['url'], 'the refund hits the sandbox WAPI endpoint' );
same( 'POST', $GLOBALS['http_log'][0]['args']['method'], 'the refund uses POST' );
same( 'wapi_test_key_123', $GLOBALS['http_log'][0]['args']['headers']['X-WAPI-Key'], 'the WAPI key is sent as a header' );
ok( empty( $GLOBALS['http_log'][0]['args']['body'] ), 'the refund sends no body, matching the documented contract' );
ok( count( $order->notes ) > 0, 'a note records that the refund is awaiting confirmation' );
ok( false !== strpos( end( $order->notes ), 'awaiting confirmation' ), 'the note says the refund is not yet confirmed' );

/* An error response is surfaced rather than swallowed. */
$GLOBALS['http_log']  = array();
$GLOBALS['http_response'] = array(
	'response' => array( 'code' => 400 ),
	'body'     => '{"message":"Insufficient balance"}',
);
$result = $gateway->process_refund( 42 );
ok( is_wp_error( $result ), 'a rejected refund returns an error' );
ok( false !== strpos( $result->get_error_message(), 'Insufficient balance' ), 'the WiPay message is passed through' );

$GLOBALS['http_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => '{"message":"Refund request successfully created."}',
);

/* ------------------------------------------------------------------ */
/* Webhook completion guards                                           */
/* ------------------------------------------------------------------ */

suite( 'Order completion guards' );

$paid_order              = new Fake_Order();
$paid_order->paid        = true;
$paid_order->status      = 'completed';
ok( false === $gateway->apply_verified_success( $paid_order, 'SB-1' ), 'an already-paid order is never completed again' );
same( '', $paid_order->completed_txn, 'an already-paid order is left untouched' );
same( 'completed', $paid_order->status, 'an already-paid order keeps its status' );

$pending              = new Fake_Order();
$pending->status      = 'pending';
$pending->total       = 100.00;
ok( true === $gateway->apply_verified_success( $pending, 'SB-2' ), 'a pending order completes on a verified result' );
same( 'processing', $pending->status, 'the completed order moves to processing' );
same( 'SB-2', $pending->completed_txn, 'the transaction id is recorded on the order' );

$refunded              = new Fake_Order();
$refunded->status      = 'refunded';
ok( false === $gateway->apply_verified_success( $refunded, 'SB-3' ), 'a refunded order is flagged, not completed' );
ok( count( $refunded->notes ) > 0, 'the late payment is recorded for manual review' );

$cancel_later              = new Fake_Order();
$cancel_later->status      = 'cancelled';
ok( false === $gateway->apply_verified_success( $cancel_later, 'SB-4' ), 'a cancelled order is flagged, not completed' );
same( 'order_not_payable', $cancel_later->get_meta( '_waypoint_wipay_late_payment' ), 'the reason is stored as order_not_payable' );

$failed              = new Fake_Order();
$failed->status      = 'pending';
ok( true === $gateway->apply_failed_result( $failed, 'WiPay reported a decline.' ), 'a pending order can be marked failed' );
same( 'failed', $failed->status, 'the pending order is now failed' );

$paid_failure              = new Fake_Order();
$paid_failure->paid        = true;
$paid_failure->status      = 'completed';
ok( false === $gateway->apply_failed_result( $paid_failure ), 'a paid order is never marked failed' );
same( 'completed', $paid_failure->status, 'the paid order status is untouched' );

$shipped              = new Fake_Order();
$shipped->status      = 'completed';
ok( false === $gateway->apply_failed_result( $shipped ), 'a shipped order is never moved backwards' );
same( 'completed', $shipped->status, 'the shipped order status is untouched' );

/* ------------------------------------------------------------------ */
/* Report                                                              */
/* ------------------------------------------------------------------ */

echo "\n----------------------------------------\n";
echo "Assertions: {$GLOBALS['assertions']}\n";
echo "Failures:   {$GLOBALS['failures']}\n";

if ( $GLOBALS['failures'] > 0 ) {
	echo "RESULT: FAILED\n";
	exit( 1 );
}

echo "RESULT: PASSED\n";
exit( 0 );
