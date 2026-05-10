<?php
/**
 * Accept.Blue Fraud Shield
 *
 * Client-side and server-side fraud prevention layer:
 *  1. Velocity controls — block repeated submissions from the same IP/email
 *  2. Card BIN blocklist — block specific BIN prefixes
 *  3. Country blocklist — block billing country codes
 *  4. Amount threshold — flag or block charges over a configurable limit
 *  5. Duplicate detection override tracking
 *  6. Admin settings page under Global Settings → Accept.Blue → Fraud Shield
 *
 * @package FrmAcceptBlue
 */

defined( 'ABSPATH' ) || exit;

class Frm_AB_Lite_Fraud {

	const OPTION_KEY = 'frm_ab_lite_fraud_settings';

	public static function init() {
		// Hook into charge processing — runs before the API call
		add_filter( 'frm_ab_lite_charge_args', array( __CLASS__, 'check_fraud' ), 5, 4 );

		// Settings section
		add_action( 'frm_ab_lite_settings_extra_rows', array( __CLASS__, 'render_settings_rows' ) );
		add_action( 'frm_update_settings',         array( __CLASS__, 'save_settings' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Main fraud check — runs via frm_ab_lite_charge_args filter
	// Returns modified $charge_args or calls frm_ab_lite_add_error() and returns false
	// ─────────────────────────────────────────────────────────────────────────

	// ─────────────────────────────────────────────────────────────────────────
	// Settings UI (injected into Global Settings → Accept.Blue tab)
	// ─────────────────────────────────────────────────────────────────────────

	public static function save_settings( $posted = array() ) {
		if ( ! isset( $_POST['frm_ab_lite_settings']['fraud'] ) ) return;
		$raw = isset( $_POST['frm_ab_lite_settings']['fraud'] ) ? wp_unslash( $_POST['frm_ab_lite_settings']['fraud'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$existing = get_option( Frm_AB_Lite_Settings::OPTION_KEY, array() );
		$existing['fraud'] = array(
			'enabled'           => ! empty( $raw['enabled'] ) ? 1 : 0,
			'max_amount'        => floatval( $raw['max_amount'] ?? 0 ),
			'max_amount_block'  => ! empty( $raw['max_amount_block'] ) ? 1 : 0,
			'max_per_ip'        => max( 1, intval( $raw['max_per_ip'] ?? 10 ) ),
			'max_per_email'     => max( 1, intval( $raw['max_per_email'] ?? 5 ) ),
			'blocked_countries' => sanitize_text_field( $raw['blocked_countries'] ?? '' ),
		);
		update_option( Frm_AB_Lite_Settings::OPTION_KEY, $existing );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	public static function get_settings(): array {
		$all = get_option( Frm_AB_Lite_Settings::OPTION_KEY, array() );
		$defaults = array(
			'enabled'           => 0,
			'max_amount'        => 0,
			'max_amount_block'  => 1,
			'max_per_ip'        => 10,
			'max_per_email'     => 5,
			'blocked_countries' => '',
		);
		return wp_parse_args( $all['fraud'] ?? array(), $defaults );
	}

	private static function get_client_ip(): string {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
				return sanitize_text_field( explode( ',', $_SERVER[ $key ] )[0] );
			}
		}
		return '';
	}

	private static function get_attempt_transient_key( string $type, string $value ): string {
		return 'frm_ab_lite_fraud_' . $type . '_' . md5( $value );
	}

	private static function count_recent_by_ip( string $ip, int $minutes ): int {
		return (int) get_transient( self::get_attempt_transient_key( 'ip', $ip ) );
	}

	private static function count_recent_by_email( string $email, int $minutes ): int {
		return (int) get_transient( self::get_attempt_transient_key( 'email', $email ) );
	}

	private static function record_attempt( string $ip, string $email, float $amount ) {
		foreach ( array( array('ip',$ip), array('email',$email) ) as $pair ) {
			$key   = self::get_attempt_transient_key( $pair[0], $pair[1] );
			$count = (int) get_transient( $key );
			set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		}
	}

	private static function log_fraud_event( string $type, string $ip, string $email, float $amount, string $reason ) {
		Frm_AB_Lite_Logger::error( 'FRAUD SHIELD BLOCKED', [
			'type' => $type, 'ip' => $ip, 'email' => $email,
			'amount' => $amount, 'reason' => $reason
		] );

		// Also store in a WP option ring-buffer (last 50 events)
		$log  = get_option( 'frm_ab_lite_fraud_log', array() );
		array_unshift( $log, array(
			'time'   => current_time( 'mysql' ),
			'type'   => $type,
			'ip'     => $ip,
			'email'  => $email,
			'amount' => $amount,
			'reason' => $reason,
		) );
		update_option( 'frm_ab_lite_fraud_log', array_slice( $log, 0, 50 ) );
	}
}

Frm_AB_Lite_Fraud::init();
