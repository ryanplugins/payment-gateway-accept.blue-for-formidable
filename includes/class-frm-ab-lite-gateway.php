<?php
/**
 * Accept.Blue Gateway Utilities
 *
 * Admin-facing payment management: transaction list column, refund handling,
 * and status hooks.
 *
 * @package FrmAcceptBlue
 */

defined( 'ABSPATH' ) || exit;

class Frm_AB_Lite_Gateway {

	public static function init() {
		// Admin: show gateway name in payments list
		add_filter( 'frm_payment_gateway_label', [ __CLASS__, 'gateway_label' ], 10, 2 );

		// Shortcode: display accept.blue transaction details
		add_shortcode( 'frm_ab_lite_charge', [ __CLASS__, 'charge_shortcode' ] );
	}

	// -------------------------------------------------------------------------
	// Admin
	// -------------------------------------------------------------------------

	public static function gateway_label( $label, $payment ) {
		if ( isset( $payment->paysys ) && $payment->paysys === 'acceptblue' ) {
			return 'Accept.Blue';
		}
		return $label;
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	/**
	 * [frm_ab_lite_charge entry="123" show="amount"]
	 *
	 * Display accept.blue charge data for a given Formidable entry.
	 * Supported show values: amount, status, receipt_id, created_at, paysys
	 */
	public static function charge_shortcode( $atts ) {
		$atts = shortcode_atts(
			[
				'entry' => '',
				'show'  => 'amount',
			],
			$atts,
			'frm_ab_lite_charge'
		);

		if ( empty( $atts['entry'] ) ) {
			return '';
		}

		global $wpdb;
		$payment = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT * FROM {$wpdb->prefix}frm_ab_lite_payments WHERE item_id = %d AND paysys = 'acceptblue' ORDER BY id DESC LIMIT 1",
			intval( $atts['entry'] )
		) );

		if ( ! $payment ) {
			return '';
		}

		$field = sanitize_key( $atts['show'] );
		return isset( $payment->$field ) ? esc_html( $payment->$field ) : '';
	}
}

Frm_AB_Lite_Gateway::init();
