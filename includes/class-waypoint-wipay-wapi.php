<?php
/**
 * WiPay WAPI client for Waypoint WiPay.
 *
 * WAPI is the authenticated API surface used for refund requests and
 * transaction retrieval. It is separate from the Payments API request
 * endpoint, which has no authentication header of its own.
 *
 * @package Waypoint_WiPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal WAPI client. Reads credentials from a server-side PHP constant so
 * they are never stored in plugin settings or exposed to browser code.
 */
final class Waypoint_WiPay_WAPI {

	/**
	 * WAPI key, sent as X-WAPI-Key.
	 *
	 * Environment-specific: sandbox keys are prefixed wapi_test_ and cannot
	 * authenticate against live, and vice versa.
	 *
	 * @return string Empty when not configured.
	 */
	public static function key() {
		if ( ! defined( 'WAYPOINT_WIPAY_WAPI_KEY' ) ) {
			return '';
		}

		return trim( (string) WAYPOINT_WIPAY_WAPI_KEY );
	}

	/**
	 * Whether refunds and reconciliation are available.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::key();
	}

	/**
	 * Send a WAPI request.
	 *
	 * @param string      $method      HTTP method.
	 * @param string      $path        Path beginning with /wapi/.
	 * @param string      $country     Platform code.
	 * @param string      $environment live or sandbox.
	 * @param array|null  $body        Optional JSON body.
	 * @return array|WP_Error Parsed JSON on success (possibly empty), or the error.
	 */
	public static function request( $method, $path, $country, $environment, $body = null ) {
		$base = Waypoint_WiPay_Platforms::wapi_base( $country, $environment );

		if ( '' === $base ) {
			return new WP_Error( 'waypoint_wipay_wapi_host', __( 'WiPay is not configured for this platform.', 'waypoint-wipay' ) );
		}

		if ( ! self::is_configured() ) {
			return new WP_Error( 'waypoint_wipay_wapi_key', __( 'The WiPay WAPI key is not configured.', 'waypoint-wipay' ) );
		}

		$args = array(
			'method'  => strtoupper( (string) $method ),
			'timeout' => 25,
			'headers' => array(
				'Accept'     => 'application/json',
				'X-WAPI-Key' => self::key(),
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $base . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$parsed = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $parsed ) && isset( $parsed['message'] ) && is_scalar( $parsed['message'] )
				? (string) $parsed['message']
				: '';

			return new WP_Error(
				'waypoint_wipay_wapi_http',
				'' !== $message
					/* translators: %d: HTTP status code, %s: WiPay's message. */
					? sprintf( __( 'WiPay rejected the request (%d): %s', 'waypoint-wipay' ), $code, $message )
					/* translators: %d: HTTP status code. */
					: sprintf( __( 'WiPay rejected the request (HTTP %d).', 'waypoint-wipay' ), $code ),
				array( 'status' => $code )
			);
		}

		return is_array( $parsed ) ? $parsed : array();
	}

	/**
	 * Submit a full refund request for a transaction.
	 *
	 * WiPay documents this as creating a *refund request*, not confirming that
	 * money has been returned, and documents no amount parameter, so partial
	 * refunds are deliberately not attempted. The outcome arrives later as a
	 * payment.refunded or payment.refund_rejected webhook event.
	 *
	 * @param string $transaction_id WiPay transaction id.
	 * @param string $country        Platform code.
	 * @param string $environment    live or sandbox.
	 * @return array|WP_Error
	 */
	public static function refund( $transaction_id, $country, $environment ) {
		$transaction_id = (string) $transaction_id;

		if ( '' === $transaction_id || ! preg_match( '/^[A-Za-z0-9._-]+$/', $transaction_id ) ) {
			return new WP_Error( 'waypoint_wipay_bad_transaction', __( 'This order has no usable WiPay transaction id.', 'waypoint-wipay' ) );
		}

		return self::request(
			'POST',
			'/wapi/transactions/' . rawurlencode( $transaction_id ) . '/refund',
			$country,
			$environment
		);
	}

	/**
	 * Retrieve the current state of a transaction for reconciliation.
	 *
	 * @param string $transaction_id WiPay transaction id.
	 * @param string $country        Platform code.
	 * @param string $environment    live or sandbox.
	 * @return array|WP_Error
	 */
	public static function transaction( $transaction_id, $country, $environment ) {
		$transaction_id = (string) $transaction_id;

		if ( '' === $transaction_id || ! preg_match( '/^[A-Za-z0-9._-]+$/', $transaction_id ) ) {
			return new WP_Error( 'waypoint_wipay_bad_transaction', __( 'This order has no usable WiPay transaction id.', 'waypoint-wipay' ) );
		}

		$response = self::request(
			'GET',
			'/wapi/transactions/' . rawurlencode( $transaction_id ),
			$country,
			$environment
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			return $response['data'];
		}

		return $response;
	}
}
