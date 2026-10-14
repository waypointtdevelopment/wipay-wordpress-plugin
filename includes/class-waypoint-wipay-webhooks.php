<?php
/**
 * WiPay webhook receiver for Waypoint WiPay.
 *
 * WiPay delivers signed server-to-server events for payment outcomes,
 * chargebacks, refunds and confirmed fraud. This endpoint verifies the
 * signature over the raw request body before acting on anything.
 *
 * Register this endpoint on the Developer page of the WiPay dashboard.
 *
 * @package Waypoint_WiPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receives, authenticates and applies WiPay webhook events.
 */
final class Waypoint_WiPay_Webhooks {

	/** Option holding recently acknowledged event ids. */
	const OPTION_EVENTS = 'waypoint_wipay_webhook_events';

	/** Seconds a timestamp may differ from server time before rejection. */
	const TOLERANCE = 300;

	/** How long acknowledged event ids are retained for deduplication. */
	const RETENTION = 604800;

	/** Maximum acknowledged event ids retained. */
	const MAX_EVENTS = 500;

	/**
	 * Register the WooCommerce API endpoint.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_api_waypoint_wipay_webhook', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Public URL to paste into the WiPay dashboard.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return add_query_arg( 'wc-api', 'waypoint_wipay_webhook', home_url( '/' ) );
	}

	/**
	 * The signing secret, read from a server-side constant.
	 *
	 * @return string Empty when not configured.
	 */
	public static function secret() {
		return defined( 'WAYPOINT_WIPAY_WEBHOOK_SECRET' ) ? trim( (string) WAYPOINT_WIPAY_WEBHOOK_SECRET ) : '';
	}

	/**
	 * Read a request header, tolerating both underscore and hyphen forms.
	 *
	 * @param string $name Header name in HTTP_ form.
	 * @return string
	 */
	private static function header( $name ) {
		if ( isset( $_SERVER[ $name ] ) && is_scalar( $_SERVER[ $name ] ) ) {
			return (string) wp_unslash( $_SERVER[ $name ] );
		}

		return '';
	}

	/**
	 * Verify a webhook signature.
	 *
	 * WiPay signs `timestamp + "." + raw_body` with HMAC-SHA256 using the
	 * endpoint signing secret and returns it as `sha256=<hex>`. The raw body
	 * must be used verbatim: re-encoding JSON would change the bytes.
	 *
	 * This method is pure so it can be unit tested without WordPress.
	 *
	 * @param string      $raw_body        Raw request body.
	 * @param string      $signature_header Value of X-WiPay-Webhook-Signature.
	 * @param string      $timestamp       Value of X-WiPay-Webhook-Timestamp.
	 * @param string      $secret          Signing secret.
	 * @param int|null    $now             Unix time, for testing.
	 * @return bool
	 */
	public static function verify_signature( $raw_body, $signature_header, $timestamp, $secret, $now = null ) {
		$secret = (string) $secret;
		if ( '' === $secret ) {
			return false;
		}

		$signature_header = trim( (string) $signature_header );
		if ( ! preg_match( '/^sha256=([a-f0-9]{64})$/i', $signature_header, $matches ) ) {
			return false;
		}

		$timestamp = (string) $timestamp;
		if ( ! preg_match( '/^[0-9]{1,12}$/', $timestamp ) ) {
			return false;
		}

		$now = ( null === $now ) ? time() : (int) $now;
		if ( abs( $now - (int) $timestamp ) > self::TOLERANCE ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . (string) $raw_body, $secret );

		return hash_equals( $expected, strtolower( $matches[1] ) );
	}

	/**
	 * Whether this event id has already been acknowledged.
	 *
	 * @param string $event_id Event id.
	 * @return bool
	 */
	public static function was_processed( $event_id ) {
		if ( '' === (string) $event_id ) {
			return false;
		}

		$events = (array) get_option( self::OPTION_EVENTS, array() );

		return isset( $events[ (string) $event_id ] );
	}

	/**
	 * Record an event id so a WiPay retry is not applied twice.
	 *
	 * @param string $event_id Event id.
	 * @return void
	 */
	public static function mark_processed( $event_id ) {
		if ( '' === (string) $event_id ) {
			return;
		}

		$events = (array) get_option( self::OPTION_EVENTS, array() );
		$events[ (string) $event_id ] = time();

		$cutoff = time() - self::RETENTION;
		foreach ( $events as $id => $timestamp ) {
			if ( (int) $timestamp < $cutoff ) {
				unset( $events[ $id ] );
			}
		}

		if ( count( $events ) > self::MAX_EVENTS ) {
			asort( $events );
			$events = array_slice( $events, -self::MAX_EVENTS, null, true );
		}

		update_option( self::OPTION_EVENTS, $events, false );
	}

	/**
	 * Entry point for the WooCommerce API route.
	 *
	 * @return void
	 */
	public static function handle() {
		$secret = self::secret();

		if ( '' === $secret ) {
			/*
			 * Non-2xx so WiPay keeps retrying while the store owner sets
			 * WAYPOINT_WIPAY_WEBHOOK_SECRET. Events are not lost.
			 */
			wp_send_json( array( 'error' => 'webhook_secret_not_configured' ), 503 );
		}

		$raw_body = (string) file_get_contents( 'php://input' );

		$signature = self::header( 'HTTP_X_WIPAY_WEBHOOK_SIGNATURE' );
		$timestamp = self::header( 'HTTP_X_WIPAY_WEBHOOK_TIMESTAMP' );
		$event_id  = self::header( 'HTTP_X_WIPAY_WEBHOOK_ID' );

		if ( ! self::verify_signature( $raw_body, $signature, $timestamp, $secret ) ) {
			wp_send_json( array( 'error' => 'invalid_signature' ), 401 );
		}

		$event = json_decode( $raw_body, true );

		if ( ! is_array( $event ) ) {
			wp_send_json( array( 'error' => 'malformed_payload' ), 400 );
		}

		if ( '' === $event_id && isset( $event['id'] ) && is_scalar( $event['id'] ) ) {
			$event_id = (string) $event['id'];
		}

		/* A retry of an event already applied is a success, not a failure. */
		if ( self::was_processed( $event_id ) ) {
			wp_send_json( array( 'received' => true, 'duplicate' => true ) );
		}

		$applied = false;

		try {
			$applied = self::dispatch( $event );
		} catch ( Throwable $exception ) {
			$applied = false;
		}

		if ( ! $applied ) {
			/* Non-2xx asks WiPay to retry; the event id stays unrecorded. */
			wp_send_json( array( 'error' => 'temporary_failure' ), 500 );
		}

		if ( '' !== $event_id ) {
			self::mark_processed( $event_id );
		}

		wp_send_json( array( 'received' => true ) );
	}

	/**
	 * Apply a verified event to the matching order.
	 *
	 * Returns true whenever the event has been conclusively handled, including
	 * events deliberately ignored. Only a genuine failure returns false, which
	 * asks WiPay to retry.
	 *
	 * @param array $event Decoded webhook payload.
	 * @return bool
	 */
	public static function dispatch( array $event ) {
		$event_name = isset( $event['event'] ) && is_scalar( $event['event'] ) ? (string) $event['event'] : '';
		$data       = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();
		$meta       = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();

		$order = self::find_order( $data );
		if ( ! $order ) {
			/* Nothing to act on; retrying will not help. */
			return true;
		}

		$transaction_id = isset( $data['transaction_id'] ) && is_scalar( $data['transaction_id'] )
			? (string) $data['transaction_id']
			: '';

		$gateway = self::gateway();

		switch ( $event_name ) {
			case 'payment.success':
				return self::handle_success( $order, $transaction_id, $gateway );

			case 'payment.failed':
			case 'payment.error':
				if ( $gateway ) {
					$gateway->apply_failed_result(
						$order,
						__( 'WiPay reported that this payment attempt did not succeed.', 'waypoint-wipay' )
					);
				}
				return true;

			case 'payment.chargeback_pending':
				return self::hold_for_chargeback( $order );

			case 'payment.chargeback_processed':
				$order->add_order_note( __( 'WiPay reports this chargeback has been processed. Reconcile the outcome in your WiPay account.', 'waypoint-wipay' ) );
				return true;

			case 'payment.chargeback_released':
				return self::release_chargeback_hold( $order );

			case 'payment.refund_requested':
				$order->add_order_note( __( 'WiPay received a refund request for this order. It is awaiting confirmation.', 'waypoint-wipay' ) );
				return true;

			case 'payment.refunded':
				return self::mark_refunded( $order );

			case 'payment.refund_rejected':
				$reason = isset( $meta['reason'] ) && is_scalar( $meta['reason'] ) ? (string) $meta['reason'] : '';
				$order->add_order_note(
					'' !== $reason
						/* translators: %s: reason reported by WiPay. */
						? sprintf( __( 'WiPay rejected the refund request: %s', 'waypoint-wipay' ), $reason )
						: __( 'WiPay rejected the refund request. The order remains refunded in WooCommerce - reconcile it manually.', 'waypoint-wipay' )
				);
				return true;

			case 'payment.fraud_confirmed':
				if ( ! $order->has_status( array( 'refunded', 'cancelled' ) ) ) {
					$order->update_status( 'cancelled', __( 'WiPay confirmed fraud on this transaction. The order was cancelled for manual review.', 'waypoint-wipay' ) );
				}
				return true;

			case 'payment.created':
			default:
				/*
				 * Unknown or informational events are acknowledged so a new
				 * WiPay event type does not cause an endless retry loop.
				 */
				return true;
		}
	}

	/**
	 * Resolve the order an event belongs to.
	 *
	 * Only an order created by this gateway is ever touched, and only when the
	 * event carries a transaction id that matches the one recorded when
	 * checkout started.
	 *
	 * @param array $data Event data.
	 * @return WC_Order|false
	 */
	private static function find_order( array $data ) {
		if ( ! isset( $data['order_id'] ) || ! is_scalar( $data['order_id'] ) ) {
			return false;
		}

		$order_id = (string) $data['order_id'];
		if ( ! preg_match( '/^[1-9][0-9]*$/', $order_id ) ) {
			return false;
		}

		$order = wc_get_order( (int) $order_id );

		if ( ! $order || 'waypoint_wipay' !== $order->get_payment_method() ) {
			return false;
		}

		if ( ! $order->get_meta( '_waypoint_wipay_started' ) ) {
			return false;
		}

		$expected = (string) $order->get_meta( '_waypoint_wipay_transaction_id' );
		$provided = isset( $data['transaction_id'] ) && is_scalar( $data['transaction_id'] ) ? (string) $data['transaction_id'] : '';

		/* A mismatch means the event is about a different attempt: ignore it. */
		if ( '' !== $expected && ( '' === $provided || ! hash_equals( $expected, $provided ) ) ) {
			return false;
		}

		return $order;
	}

	/**
	 * Complete a paid order from a signed webhook.
	 *
	 * @param WC_Order                  $order          Order.
	 * @param string                    $transaction_id WiPay transaction id.
	 * @param Waypoint_WiPay_Gateway|null $gateway      Gateway.
	 * @return bool
	 */
	private static function handle_success( $order, $transaction_id, $gateway ) {
		if ( ! $gateway ) {
			return false;
		}

		if ( '' === $transaction_id ) {
			return false;
		}

		$gateway->apply_verified_success( $order, $transaction_id, 'webhook' );

		return true;
	}

	/**
	 * Put a paid order on hold while a chargeback is outstanding.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function hold_for_chargeback( $order ) {
		if ( $order->has_status( array( 'refunded', 'cancelled' ) ) ) {
			return true;
		}

		if ( 'on-hold' === $order->get_status() ) {
			return true;
		}

		$order->update_meta_data( '_waypoint_wipay_chargeback_hold', $order->get_status() );
		$order->update_status( 'on-hold', __( 'WiPay reports a chargeback is pending on this transaction. Do not fulfil this order.', 'waypoint-wipay' ) );

		return true;
	}

	/**
	 * Undo a chargeback hold when WiPay releases the dispute.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function release_chargeback_hold( $order ) {
		$held_from = (string) $order->get_meta( '_waypoint_wipay_chargeback_hold' );

		if ( '' === $held_from ) {
			$order->add_order_note( __( 'WiPay released the chargeback. This order was not held by this plugin, so its status was left unchanged.', 'waypoint-wipay' ) );
			return true;
		}

		$order->delete_meta_data( '_waypoint_wipay_chargeback_hold' );

		$allowed = array( 'pending', 'processing', 'completed', 'on-hold' );
		if ( in_array( $held_from, $allowed, true ) && 'on-hold' === $order->get_status() ) {
			$order->update_status( $held_from, __( 'WiPay released the chargeback; the previous order status was restored.', 'waypoint-wipay' ) );
		} else {
			$order->add_order_note( __( 'WiPay released the chargeback. The order status had changed since the hold, so it was left unchanged.', 'waypoint-wipay' ) );
		}

		$order->save();

		return true;
	}

	/**
	 * Record a refund confirmed by WiPay, idempotently.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function mark_refunded( $order ) {
		if ( $order->has_status( 'refunded' ) ) {
			return true;
		}

		$note = __( 'WiPay confirmed this transaction was refunded.', 'waypoint-wipay' );

		/*
		 * If a WooCommerce refund record already exists (the merchant refunded
		 * through WooCommerce), do not add a second one - only the status is
		 * missing. Otherwise record the full amount as refunded.
		 */
		if ( count( $order->get_refunds() ) > 0 ) {
			$order->update_status( 'refunded', $note );
			return true;
		}

		$refund = wc_create_refund( array(
			'order_id'       => $order->get_id(),
			'amount'         => (float) $order->get_total( 'edit' ),
			'reason'         => __( 'WiPay refund confirmed', 'waypoint-wipay' ),
			'refund_payment' => false,
		) );

		if ( is_wp_error( $refund ) || ! $refund ) {
			$order->update_status( 'refunded', $note );
			return true;
		}

		if ( ! $order->has_status( 'refunded' ) ) {
			$order->update_status( 'refunded', $note );
		} else {
			$order->add_order_note( $note );
		}

		return true;
	}

	/**
	 * The gateway instance, when WooCommerce has loaded it.
	 *
	 * @return Waypoint_WiPay_Gateway|null
	 */
	private static function gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return null;
		}

		$gateways = WC()->payment_gateways->payment_gateways();

		return isset( $gateways['waypoint_wipay'] ) ? $gateways['waypoint_wipay'] : null;
	}
}

Waypoint_WiPay_Webhooks::init();
