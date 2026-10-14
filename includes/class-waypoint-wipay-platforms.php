<?php
/**
 * WiPay platform and host mapping for Waypoint WiPay.
 *
 * Shared by the gateway (payment requests) and the WAPI client (refunds and
 * reconciliation) so the territory/host map exists in exactly one place.
 *
 * @package Waypoint_WiPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps WiPay processing platforms to their documented live and sandbox hosts.
 */
final class Waypoint_WiPay_Platforms {

	/**
	 * WiPay platform code => human label.
	 *
	 * The key is WiPay's country_code, which selects the processing platform.
	 * It is deliberately not the shopper's billing country.
	 *
	 * @var array<string, string>
	 */
	private static $platforms = array(
		'TT' => 'Trinidad and Tobago',
		'JM' => 'Jamaica',
		'BB' => 'Barbados',
		'GY' => 'Guyana',
		'GD' => 'Grenada',
	);

	/**
	 * Platform => host map.
	 *
	 * The TT pair is published on WiPay's Platforms and Environments page. The
	 * others follow the documented host layout and were carried over from the
	 * sandbox-tested gateway; re-confirm them before release.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static $hosts = array(
		'TT' => array( 'live' => 'tt.wipayfinancial.com', 'sandbox' => 'ttsb.wipayfinancial.com' ),
		'JM' => array( 'live' => 'jm.wipayfinancial.com', 'sandbox' => 'jmsb.wipayfinancial.com' ),
		'BB' => array( 'live' => 'bb.wipayfinancial.com', 'sandbox' => 'bbsb.wipayfinancial.com' ),
		'GY' => array( 'live' => 'gy.wipayfinancial.com', 'sandbox' => 'gysb.wipayfinancial.com' ),
		'GD' => array( 'live' => 'gd.wipayfinancial.com', 'sandbox' => 'gdsb.wipayfinancial.com' ),
	);

	/**
	 * Supported platform codes in display order.
	 *
	 * @return string[]
	 */
	public static function codes() {
		return array( 'BB', 'GD', 'GY', 'JM', 'TT' );
	}

	/**
	 * Platform code => label, ordered for the settings dropdown.
	 *
	 * @return array<string, string>
	 */
	public static function labels() {
		$labels = array();
		foreach ( self::codes() as $code ) {
			$labels[ $code ] = isset( self::$platforms[ $code ] ) ? self::$platforms[ $code ] : $code;
		}
		return $labels;
	}

	/**
	 * Whether the code is a WiPay platform this gateway knows about.
	 *
	 * @param string $country Platform code.
	 * @return bool
	 */
	public static function is_supported( $country ) {
		return isset( self::$hosts[ strtoupper( (string) $country ) ] );
	}

	/**
	 * Hostname for a platform and environment, or an empty string when the
	 * combination is unknown. Never guesses: an unrecognised platform reports
	 * "not configured" rather than silently routing to another territory.
	 *
	 * @param string $country     Platform code.
	 * @param string $environment live or sandbox.
	 * @return string
	 */
	public static function host( $country, $environment ) {
		$country     = strtoupper( (string) $country );
		$environment = (string) $environment;

		if ( ! isset( self::$hosts[ $country ][ $environment ] ) ) {
			return '';
		}

		return self::$hosts[ $country ][ $environment ];
	}

	/**
	 * Full Payments API request endpoint for a platform and environment.
	 *
	 * @param string $country     Platform code.
	 * @param string $environment live or sandbox.
	 * @return string Empty when the combination is unknown.
	 */
	public static function payments_endpoint( $country, $environment ) {
		$host = self::host( $country, $environment );

		return '' === $host ? '' : 'https://' . $host . '/plugins/payments/request';
	}

	/**
	 * Base URL for WAPI calls (refunds and transaction lookup).
	 *
	 * WAPI reuses the country host; callers append the /wapi/... path.
	 *
	 * @param string $country     Platform code.
	 * @param string $environment live or sandbox.
	 * @return string Empty when the combination is unknown.
	 */
	public static function wapi_base( $country, $environment ) {
		$host = self::host( $country, $environment );

		return '' === $host ? '' : 'https://' . $host;
	}
}
