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
		// Fraud Shield is a Pro feature. The filter is registered so Pro can
		// override it; Lite passes all requests through unchanged.
		add_filter( 'frm_ab_lite_charge_args', array( __CLASS__, 'check_fraud' ), 5, 4 );

	}



	// ─────────────────────────────────────────────────────────────────────────
	// Fraud check — Pro feature, pass-through in Lite
	// ─────────────────────────────────────────────────────────────────────────

	public static function check_fraud( $charge_args, $amount = 0, $email = '', $form_id = 0 ) {
		// Fraud Shield is available in the Pro version.
		// Lite passes the charge args through unchanged.
		return $charge_args;
	}

}

Frm_AB_Lite_Fraud::init();
