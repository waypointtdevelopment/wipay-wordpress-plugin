<?php
/**
 * Transaction reconciliation for Waypoint WiPay.
 *
 * Exposes a WooCommerce order action that fetches the transaction's current
 * state from WiPay and records the comparison as an order note, so a merchant
 * can settle a dispute or investigate a missing payment without guessing.
 *
 * @package Waypoint_WiPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual "Reconcile with WiPay" order action.
 */
final class Waypoint_WiPay_Reconciliation {

	/** Meta key holding the last reconciliation snapshot. */
	const META_LAST = '_waypoint_wipay_reconciliation';

	/**
	 * Register the order action.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_order_actions', array( __CLASS__, 'add_order_action' ) );
		add_action( 'woocommerce_order_action_waypoint_wipay_reconcile', array( __CLASS__, 'run' ) );
	}

	/**
	 * Add the action to the order edit screen's Actions list.
	 *
	 * Using an order action rather than a meta box keeps this working on both
	 * classic order screens and High-Performance Order Storage.
	 *
	 * @param array $actions Available actions.
	 * @return array
	 */
	public static function add_order_action( $actions ) {
		$actions['waypoint_wipay_reconcile'] = __( 'Reconcile with WiPay', 'waypoint-wipay' );

		return $actions;
	}

	/**
	 * Fetch and record the transaction's current state.
	 *
	 * Never completes or refunds an order on its own: reconciliation reports
	 * the difference and leaves the decision to the merchant.
	 *
	 * @param WC_Order $order Order being edited.
	 * @return void
	 */
	public static function run( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( 'waypoint_wipay' !== $order->get_payment_method() ) {
			$order->add_order_note( __( 'Reconciliation skipped: this order was not paid with Waypoint WiPay.', 'waypoint-wipay' ) );
			return;
		}

		if ( ! Waypoint_WiPay_WAPI::is_configured() ) {
			$order->add_order_note( __( 'Reconciliation unavailable: set WAYPOINT_WIPAY_WAPI_KEY in wp-config.php first.', 'waypoint-wipay' ) );
			return;
		}

		$transaction_id = (string) $order->get_meta( '_waypoint_wipay_transaction_id' );

		if ( '' === $transaction_id ) {
			$order->add_order_note( __( 'Reconciliation skipped: no WiPay transaction id was recorded for this order.', 'waypoint-wipay' ) );
			return;
		}

		$environment = (string) $order->get_meta( '_waypoint_wipay_environment' );
		if ( ! in_array( $environment, array( 'sandbox', 'live' ), true ) ) {
			$environment = 'sandbox';
		}

		$country = self::country();

		$transaction = Waypoint_WiPay_WAPI::transaction( $transaction_id, $country, $environment );

		if ( is_wp_error( $transaction ) ) {
			$order->add_order_note(
				/* translators: %s: error returned by WiPay. */
				sprintf( __( 'Reconciliation failed: %s', 'waypoint-wipay' ), $transaction->get_error_message() )
			);
			return;
		}

		$status       = isset( $transaction['status'] ) && is_scalar( $transaction['status'] ) ? (string) $transaction['status'] : '';
		$status_desc  = isset( $transaction['status_desc'] ) && is_scalar( $transaction['status_desc'] ) ? (string) $transaction['status_desc'] : '';
		$total        = isset( $transaction['total'] ) ? (string) $transaction['total'] : '';
		$credited     = isset( $transaction['total_credited'] ) ? (string) $transaction['total_credited'] : '';
		$currency     = isset( $transaction['currency'] ) && is_scalar( $transaction['currency'] ) ? (string) $transaction['currency'] : '';
		$order_total  = number_format( (float) $order->get_total( 'edit' ), 2, '.', '' );

		$comparison = self::compare( $status, $total, $order_total, $order );

		$summary = sprintf(
			/* translators: 1: WiPay status, 2: status description, 3: WiPay total, 4: order total. */
			__( 'WiPay reconciliation: status %1$s%2$s, WiPay total %3$s, order total %4$s.', 'waypoint-wipay' ),
			$status,
			'' !== $status_desc ? ' (' . $status_desc . ')' : '',
			'' !== $total ? $total . ' ' . $currency : 'unknown',
			$order_total
		);

		$order->add_order_note( $summary . ' ' . $comparison );

		$order->update_meta_data( self::META_LAST, array(
			'timestamp'       => time(),
			'transaction_id'  => $transaction_id,
			'environment'     => $environment,
			'status'          => $status,
			'status_desc'     => $status_desc,
			'total'           => $total,
			'total_credited'  => $credited,
			'order_total'     => $order_total,
		) );
		$order->save();
	}

	/**
	 * Describe how WiPay's view compares with WooCommerce's.
	 *
	 * @param string   $status      WiPay status.
	 * @param string   $total       WiPay total.
	 * @param string   $order_total WooCommerce total.
	 * @param WC_Order $order       Order.
	 * @return string
	 */
	private static function compare( $status, $total, $order_total, $order ) {
		if ( '' !== $total && '' !== $order_total && 0 !== strcmp( $total, $order_total ) ) {
			return __( 'Amounts differ - review before settling.', 'waypoint-wipay' );
		}

		if ( 'success' === $status && ! $order->is_paid() && ! $order->has_status( array( 'refunded', 'cancelled', 'failed' ) ) ) {
			return __( 'WiPay reports success but this order is not paid. Review and complete it manually if the payment is genuine.', 'waypoint-wipay' );
		}

		if ( 'success' !== $status && $order->is_paid() ) {
			return __( 'This order is marked paid but WiPay does not report success. Investigate before fulfilling.', 'waypoint-wipay' );
		}

		return __( 'No discrepancy detected.', 'waypoint-wipay' );
	}

	/**
	 * The configured WiPay platform.
	 *
	 * @return string
	 */
	private static function country() {
		$settings = (array) get_option( 'woocommerce_waypoint_wipay_settings', array() );
		$country  = isset( $settings['country_code'] ) ? strtoupper( (string) $settings['country_code'] ) : 'TT';

		return Waypoint_WiPay_Platforms::is_supported( $country ) ? $country : 'TT';
	}
}

Waypoint_WiPay_Reconciliation::init();
