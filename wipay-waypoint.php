<?php
/**
 * Plugin Name: Waypoint WiPay for WooCommerce
 * Plugin URI: https://waypointt.com/
 * Description: Hosted card checkout for WooCommerce using WiPay's Payments API.
 * Version: 1.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * Author: Waypoint
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: waypoint-wipay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WAYPOINT_WIPAY_VERSION', '1.1.0' );
require_once __DIR__ . '/includes/class-waypoint-wipay-platforms.php';
require_once __DIR__ . '/includes/class-waypoint-wipay-wapi.php';
require_once __DIR__ . '/includes/class-waypoint-wipay-webhooks.php';
require_once __DIR__ . '/includes/class-waypoint-wipay-reconciliation.php';
define( 'WAYPOINT_WIPAY_ID', 'waypoint_wipay' );
define( 'WAYPOINT_WIPAY_PLUGIN_FILE', __FILE__ );

add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

add_action( 'woocommerce_blocks_loaded', static function () {
	if ( ! class_exists( '\\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
		return;
	}

	require_once __DIR__ . '/includes/class-waypoint-wipay-blocks-support.php';

	add_action( 'woocommerce_blocks_payment_method_type_registration', static function ( $payment_method_registry ) {
		$payment_method_registry->register( new Waypoint_WiPay_Blocks_Support() );
	} );
} );

add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	final class Waypoint_WiPay_Gateway extends WC_Payment_Gateway {
		private const SANDBOX_ACCOUNT = '1234567890';
		private const SANDBOX_KEY = '123';
		private const META_STARTED = '_waypoint_wipay_started';
		private const META_TOTAL = '_waypoint_wipay_total';
		private const META_ENVIRONMENT = '_waypoint_wipay_environment';
		private const META_TRANSACTION = '_waypoint_wipay_transaction_id';
		private const META_LATE_PAYMENT = '_waypoint_wipay_late_payment';
		private const META_HASH_REJECTED = '_waypoint_wipay_hash_rejected';

		public function __construct() {
			$this->id                 = WAYPOINT_WIPAY_ID;
			$this->method_title       = __( 'Waypoint WiPay', 'waypoint-wipay' );
			$this->method_description = __( 'Redirect customers to WiPay hosted checkout. This independent plugin is not endorsed by WiPay.', 'waypoint-wipay' );
			$this->has_fields         = false;
			$this->supports           = array( 'products', 'refunds' );
			$this->init_form_fields();
			$this->init_settings();

			$this->title       = $this->get_option( 'title', __( 'Credit card (WiPay)', 'waypoint-wipay' ) );
			$this->description = $this->get_option( 'description', __( 'You will complete payment on WiPay’s secure hosted checkout.', 'waypoint-wipay' ) );
			$this->enabled     = $this->get_option( 'enabled', 'no' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_api_' . strtolower( __CLASS__ ), array( $this, 'handle_return' ) );
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title'   => __( 'Enable/Disable', 'waypoint-wipay' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable WiPay hosted checkout', 'waypoint-wipay' ),
					'default' => 'no',
				),
				'title' => array(
					'title'   => __( 'Title', 'waypoint-wipay' ),
					'type'    => 'text',
					'default' => __( 'Credit card', 'waypoint-wipay' ),
				),
				'description' => array(
					'title'   => __( 'Checkout description', 'waypoint-wipay' ),
					'type'    => 'textarea',
					'default' => __( 'You will complete payment on WiPay’s secure hosted checkout.', 'waypoint-wipay' ),
				),
				'environment' => array(
					'title'   => __( 'Environment', 'waypoint-wipay' ),
					'type'    => 'select',
					'default' => 'sandbox',
					'options' => array( 'sandbox' => __( 'Sandbox', 'waypoint-wipay' ), 'live' => __( 'Live', 'waypoint-wipay' ) ),
				),
				'country_code' => array(
					'title'   => __( 'WiPay platform', 'waypoint-wipay' ),
					'type'    => 'select',
					'default' => 'TT',
					'options' => array(
						'TT' => __( 'Trinidad and Tobago', 'waypoint-wipay' ),
						'JM' => __( 'Jamaica', 'waypoint-wipay' ),
						'BB' => __( 'Barbados', 'waypoint-wipay' ),
						'GY' => __( 'Guyana', 'waypoint-wipay' ),
						'GD' => __( 'Grenada', 'waypoint-wipay' ),
					),
				),
				'live_account_number' => array(
					'title'       => __( 'Live WiPay account number', 'waypoint-wipay' ),
					'type'        => 'text',
					'default'     => '',
					'description' => __( 'Used only in Live mode. Keep live checkout disabled until your own sandbox review is complete.', 'waypoint-wipay' ),
				),
				'fee_structure' => array(
					'title'   => __( 'Fee structure', 'waypoint-wipay' ),
					'type'    => 'select',
					'default' => 'merchant_absorb',
					'options' => array(
						'merchant_absorb' => __( 'Merchant absorbs', 'waypoint-wipay' ),
						'customer_pay'    => __( 'Customer pays', 'waypoint-wipay' ),
						'split'           => __( 'Split', 'waypoint-wipay' ),
					),
				),
				'webhook_url' => array(
					'title'       => __( 'Webhook endpoint', 'waypoint-wipay' ),
					'type'        => 'title',
					'description' => sprintf(
						/* translators: %s: the store's webhook URL. */
						__( 'Register this URL on the WiPay dashboard Developer page to receive signed payment, chargeback and refund events: %s', 'waypoint-wipay' ),
						Waypoint_WiPay_Webhooks::endpoint_url()
					),
				),
				'debug' => array(
					'title'       => __( 'Diagnostics', 'waypoint-wipay' ),
					'type'        => 'checkbox',
					'label'       => __( 'Write redacted diagnostic events to the WooCommerce logger', 'waypoint-wipay' ),
					'default'     => 'no',
					'description' => __( 'Request bodies, customer details, API keys, and full callback URLs are never logged.', 'waypoint-wipay' ),
				),
			);
		}

		public function admin_options() {
			parent::admin_options();
			echo '<p>' . esc_html__( 'Set the live API key as WAYPOINT_WIPAY_API_KEY in wp-config.php. It is never stored in plugin settings or sent to browser code. Sandbox uses WiPay’s published test account and key.', 'waypoint-wipay' ) . '</p>';
			echo '<p>' . esc_html__( 'Refunds and reconciliation need a WAPI key: define WAYPOINT_WIPAY_WAPI_KEY in wp-config.php. Signed webhooks need WAYPOINT_WIPAY_WEBHOOK_SECRET. Both stay server-side and are never stored in these settings.', 'waypoint-wipay' ) . '</p>';
			echo '<p>' . esc_html__( 'Waypoint maintains this independent plugin. WiPay does not sponsor, endorse, or accept responsibility for it. Use at your own risk; see LICENSE.', 'waypoint-wipay' ) . '</p>';
		}

		private function secret_for( $environment ) {
			if ( 'sandbox' === $environment ) {
				return self::SANDBOX_KEY;
			}
			return defined( 'WAYPOINT_WIPAY_API_KEY' ) ? (string) WAYPOINT_WIPAY_API_KEY : '';
		}

		private function endpoint( $country, $environment ) {
			$host = Waypoint_WiPay_Platforms::host( $country, $environment );

			return '' === $host ? '' : 'https://' . $host . '/plugins/payments/request';
		}

		private function log_event( $message, array $context = array() ) {
			if ( 'yes' !== $this->get_option( 'debug', 'no' ) || ! function_exists( 'wc_get_logger' ) ) {
				return;
			}
			$context['source'] = 'waypoint-wipay';
			wc_get_logger()->info( $message . ' ' . wp_json_encode( $context ), $context );
		}

		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || $order->is_paid() || in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
				wc_add_notice( __( 'This order cannot be sent to WiPay. Please contact the store.', 'waypoint-wipay' ), 'error' );
				return array( 'result' => 'failure' );
			}

			$environment = $this->get_option( 'environment', 'sandbox' );
			$country     = strtoupper( $this->get_option( 'country_code', 'TT' ) );
			$endpoint    = $this->endpoint( $country, $environment );
			$api_key     = $this->secret_for( $environment );
			$account     = 'sandbox' === $environment ? self::SANDBOX_ACCOUNT : preg_replace( '/\D+/', '', (string) $this->get_option( 'live_account_number', '' ) );
			$total       = number_format( (float) $order->get_total( 'edit' ), 2, '.', '' );

			if ( ! $endpoint || ! $api_key || ! $account || ! is_email( $order->get_billing_email() ) ) {
				wc_add_notice( __( 'WiPay is not configured correctly. Please contact the store.', 'waypoint-wipay' ), 'error' );
				return array( 'result' => 'failure' );
			}
			if ( 'TT' === $country && 'TTD' === strtoupper( $order->get_currency() ) && (float) $total < 5.00 ) {
				wc_add_notice( __( 'WiPay requires a minimum payment of TTD 5.00.', 'waypoint-wipay' ), 'error' );
				return array( 'result' => 'failure' );
			}

			$order->update_meta_data( self::META_STARTED, time() );
			$order->update_meta_data( self::META_TOTAL, $total );
			$order->update_meta_data( self::META_ENVIRONMENT, $environment );
			$order->delete_meta_data( self::META_TRANSACTION );
			$order->delete_meta_data( self::META_LATE_PAYMENT );
			if ( 'failed' === $order->get_status() ) {
				$order->update_status( 'pending' );
			}
			$order->save();

			$payload = array(
				'account_number' => (int) $account,
				'country_code'   => $country,
				'currency'       => strtoupper( $order->get_currency() ),
				'environment'    => $environment,
				'fee_structure'  => $this->get_option( 'fee_structure', 'merchant_absorb' ),
				'method'         => 'credit_card_co',
				'order_id'       => (string) $order->get_id(),
				'origin'         => 'WaypointWCP',
				'response_url'   => add_query_arg( 'wc-api', strtolower( __CLASS__ ), home_url( '/' ) ),
				'total'          => $total,
				'email'          => $order->get_billing_email(),
				'version'        => WAYPOINT_WIPAY_VERSION,
			);
			$this->add_customer_prefill( $payload, $order );

			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout'     => 25,
					'headers'     => array(
						'Accept'       => 'application/json',
						'Content-Type' => 'application/json; charset=utf-8',
					),
					'body'        => wp_json_encode( $payload ),
					'redirect'    => 0,
					'data_format' => 'body',
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				$this->log_event( 'payment_request_failed', array( 'order_id' => $order->get_id(), 'code' => is_wp_error( $response ) ? 'network_error' : (int) wp_remote_retrieve_response_code( $response ) ) );
				wc_add_notice( __( 'WiPay could not start checkout. Please try again or contact the store.', 'waypoint-wipay' ), 'error' );
				return array( 'result' => 'failure' );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$url  = isset( $body['url'] ) ? esc_url_raw( $body['url'] ) : '';
			$txn  = isset( $body['transaction_id'] ) ? sanitize_text_field( (string) $body['transaction_id'] ) : '';
			$host   = $url ? wp_parse_url( $url, PHP_URL_HOST ) : '';
			$scheme = $url ? wp_parse_url( $url, PHP_URL_SCHEME ) : '';
			if ( ! $url || 'https' !== strtolower( (string) $scheme ) || ! $txn || ! $host || ! preg_match( '/(^|\.)wipayfinancial\.com$/i', $host ) ) {
				$this->log_event( 'invalid_checkout_response', array( 'order_id' => $order->get_id() ) );
				wc_add_notice( __( 'WiPay returned an invalid checkout response. Please contact the store.', 'waypoint-wipay' ), 'error' );
				return array( 'result' => 'failure' );
			}

			$order->update_meta_data( self::META_TRANSACTION, $txn );
			$order->save();
			$this->log_event( 'checkout_started', array( 'order_id' => $order->get_id() ) );
			return array( 'result' => 'success', 'redirect' => $url );
		}

		/**
		 * Completes an order from an already-authenticated result.
		 *
		 * Shares the guard used by the browser callback: an order that is no
		 * longer payable, or whose stock is short, is flagged for manual
		 * review instead of being completed automatically.
		 *
		 * @param WC_Order $order          Order.
		 * @param string   $transaction_id WiPay transaction id.
		 * @param string   $source         What reported the result.
		 * @return bool Whether the order was completed.
		 */
		public function apply_verified_success( $order, $transaction_id, $source = 'notification' ) {
			if ( $order->is_paid() ) {
				return false;
			}

			if ( $this->flag_late_or_out_of_stock( $order ) ) {
				return false;
			}

			$order->payment_complete( $transaction_id );

			if ( function_exists( 'WC' ) && WC()->cart ) {
				WC()->cart->empty_cart();
			}

			$this->log_event( 'payment_verified', array( 'order_id' => $order->get_id(), 'source' => $source ) );

			return true;
		}

		/**
		 * Marks a started, unpaid order as failed.
		 *
		 * Paid, cancelled and refunded orders are never moved backwards.
		 *
		 * @param WC_Order $order Order.
		 * @param string   $note  Customer-visible reason.
		 * @return bool Whether the status changed.
		 */
		public function apply_failed_result( $order, $note = '' ) {
			if ( $order->is_paid() ) {
				return false;
			}

			if ( ! in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) {
				return false;
			}

			$order->update_status(
				'failed',
				'' !== $note ? $note : __( 'WiPay reported that this started payment attempt did not succeed.', 'waypoint-wipay' )
			);

			return true;
		}

		/**
		 * Handles a WooCommerce refund request.
		 *
		 * WiPay's refund operation is an authenticated WAPI call that creates
		 * a *refund request*. It documents no amount parameter, so only a full
		 * order refund is accepted here, and it does not confirm that funds
		 * have returned - that arrives later as a payment.refunded or
		 * payment.refund_rejected webhook event.
		 *
		 * @param int         $order_id Order id.
		 * @param float|null  $amount   Requested amount, or null for the full total.
		 * @param string      $reason   Merchant reason.
		 * @return true|\WP_Error
		 */
		public function process_refund( $order_id, $amount = null, $reason = '' ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				return new \WP_Error( 'waypoint_wipay_no_order', __( 'Order not found.', 'waypoint-wipay' ) );
			}

			$transaction_id = (string) $order->get_meta( self::META_TRANSACTION );

			if ( '' === $transaction_id ) {
				return new \WP_Error( 'waypoint_wipay_no_transaction', __( 'This order has no WiPay transaction to refund.', 'waypoint-wipay' ) );
			}

			$total = (float) $order->get_total( 'edit' );

			if ( null !== $amount && abs( (float) $amount - $total ) > 0.01 ) {
				return new \WP_Error(
					'waypoint_wipay_partial_refund',
					__( 'WiPay supports full order refunds only. Refund the whole order here, or record a partial adjustment outside WooCommerce.', 'waypoint-wipay' )
				);
			}

			if ( ! Waypoint_WiPay_WAPI::is_configured() ) {
				return new \WP_Error(
					'waypoint_wipay_no_wapi_key',
					__( 'Set WAYPOINT_WIPAY_WAPI_KEY in wp-config.php to refund from WooCommerce.', 'waypoint-wipay' )
				);
			}

			$environment = (string) $order->get_meta( self::META_ENVIRONMENT );

			if ( ! in_array( $environment, array( 'sandbox', 'live' ), true ) ) {
				$environment = $this->get_option( 'environment', 'sandbox' );
			}

			$country = strtoupper( $this->get_option( 'country_code', 'TT' ) );

			$result = Waypoint_WiPay_WAPI::refund( $transaction_id, $country, $environment );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$order->add_order_note(
				__( 'WiPay refund request submitted and awaiting confirmation. Confirm the outcome in your WiPay account before treating funds as returned.', 'waypoint-wipay' )
			);
			$this->log_event( 'refund_requested', array( 'order_id' => $order->get_id() ) );

			return true;
		}
		private function add_customer_prefill( array &$payload, WC_Order $order ) {
			$optional = array(
				'fname'   => array( $order->get_billing_first_name(), 30 ),
				'lname'   => array( $order->get_billing_last_name(), 30 ),
				'addr1'   => array( $order->get_billing_address_1(), 50 ),
				'addr2'   => array( $order->get_billing_address_2(), 50 ),
				'city'    => array( $order->get_billing_city(), 30 ),
				'zipcode' => array( $order->get_billing_postcode(), 10 ),
				'country' => array( strtoupper( $order->get_billing_country() ), 2 ),
			);
			$has_first_name = '' !== trim( (string) $order->get_billing_first_name() );
			$has_address_1  = '' !== trim( (string) $order->get_billing_address_1() );
			foreach ( $optional as $field => $value ) {
				if ( ( 'lname' === $field && ! $has_first_name ) || ( 'addr2' === $field && ! $has_address_1 ) ) {
					continue;
				}
				$text = sanitize_text_field( (string) $value[0] );
				if ( '' !== $text ) {
					$payload[ $field ] = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $value[1] ) : substr( $text, 0, $value[1] );
				}
			}
			$phone = preg_replace( '/[^+0-9]/', '', (string) $order->get_billing_phone() );
			if ( preg_match( '/^\+[1-9][0-9]{1,14}$/', $phone ) ) {
				$payload['phone'] = $phone;
			}
		}

		public function flag_late_or_out_of_stock( WC_Order $order ) {
			if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
				if ( $order->get_meta( self::META_LATE_PAYMENT ) === 'order_not_payable' ) {
					return true;
				}
				$order->update_meta_data( self::META_LATE_PAYMENT, 'order_not_payable' );
				$order->add_order_note( __( 'WiPay reported a successful payment after this order was no longer payable. Review the WiPay transaction and arrange fulfillment or refund manually.', 'waypoint-wipay' ) );
				$order->save();
				return true;
			}

			$required = array();
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( $product && $product->managing_stock() && ! $product->backorders_allowed() ) {
					$product_id = $product->get_stock_managed_by_id();
					$required[ $product_id ] = ( $required[ $product_id ] ?? 0 ) + (float) $item->get_quantity();
				}
			}
			foreach ( $required as $product_id => $quantity ) {
				$product = wc_get_product( $product_id );
				if ( $product && null !== $product->get_stock_quantity() && (float) $product->get_stock_quantity() < $quantity ) {
					if ( $order->get_meta( self::META_LATE_PAYMENT ) === 'stock_unavailable' ) {
						return true;
					}
					$order->update_meta_data( self::META_LATE_PAYMENT, 'stock_unavailable' );
					$order->add_order_note( __( 'WiPay reported a successful payment but current stock is insufficient. Review this order before fulfillment; do not silently oversell.', 'waypoint-wipay' ) );
					$order->save();
					return true;
				}
			}
			return false;
		}

		public function handle_return() {
			$order_id = isset( $_GET['order_id'] ) ? wp_unslash( $_GET['order_id'] ) : '';
			$txn      = isset( $_GET['transaction_id'] ) ? sanitize_text_field( wp_unslash( $_GET['transaction_id'] ) ) : '';
			$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
			$hash     = isset( $_GET['hash'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['hash'] ) ) ) : '';

			if ( ! is_string( $order_id ) || ! preg_match( '/^[1-9][0-9]*$/', $order_id ) ) {
				wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
				exit;
			}
			$order = wc_get_order( (int) $order_id );
			if ( ! $order || (string) $order->get_id() !== $order_id || WAYPOINT_WIPAY_ID !== $order->get_payment_method() || ! $order->get_meta( self::META_STARTED ) || ! $txn || ! hash_equals( (string) $order->get_meta( self::META_TRANSACTION ), $txn ) ) {
				$this->log_event( 'callback_rejected', array( 'order_id' => (int) $order_id ) );
				wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
				exit;
			}

			if ( 'success' === $status ) {
				$environment = (string) $order->get_meta( self::META_ENVIRONMENT );
				$pinned      = (string) $order->get_meta( self::META_TOTAL );
				$api_key     = $this->secret_for( $environment );
				$expected    = ( $pinned && $api_key ) ? md5( $txn . number_format( (float) $pinned, 2, '.', '' ) . $api_key ) : '';
				if ( ! $expected || ! preg_match( '/^[a-f0-9]{32}$/', $hash ) || ! hash_equals( $expected, $hash ) ) {
					$this->log_event( 'callback_hash_rejected', array( 'order_id' => $order->get_id() ) );
					if ( 'yes' !== $order->get_meta( self::META_HASH_REJECTED ) ) {
						$order->update_meta_data( self::META_HASH_REJECTED, 'yes' );
						$order->add_order_note( __( 'WiPay returned a success response that failed hash verification. The order remains unpaid.', 'waypoint-wipay' ) );
						$order->save();
					}
				} elseif ( ! $order->is_paid() && ! $this->flag_late_or_out_of_stock( $order ) ) {
					$order->payment_complete( $txn );
					if ( function_exists( 'WC' ) && WC()->cart ) {
						WC()->cart->empty_cart();
					}
					$this->log_event( 'payment_verified', array( 'order_id' => $order->get_id() ) );
				}
			} elseif ( in_array( $status, array( 'failed', 'cancelled', 'error' ), true ) && ! $order->is_paid() && in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) {
				$order->update_status( 'failed', __( 'WiPay reported that this started payment attempt did not succeed.', 'waypoint-wipay' ) );
			}

			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}
	}

	add_filter( 'woocommerce_payment_gateways', static function ( $gateways ) {
		$gateways[] = 'Waypoint_WiPay_Gateway';
		return $gateways;
	} );
}, 11 );

