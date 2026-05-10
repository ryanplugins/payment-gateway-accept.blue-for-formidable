<?php
/**
 * Webhook receiver for accept.blue charge events.
 * Recurring schedule functionality is available in the Pro version.
 *
 * @package Formidable_AcceptBlue_Lite
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Frm_AB_Lite_Recurring {

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_webhook_route' ] );
	}

	// ── Webhook REST endpoint ─────────────────────────────────────────────────

	/**
	 * Register the REST API webhook endpoint.
	 * Authenticate via secret token embedded in the URL: ?token=SECRET
	 * Configure the full URL in accept.blue portal → Control Panel → Webhooks.
	 */
	public static function register_webhook_route(): void {
		register_rest_route( 'frm-ab-lite/v1', '/webhook', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_webhook' ],
			'permission_callback' => '__return_true',
		] );
	}

	/**
	 * Return the full webhook URL including the security token.
	 * Paste this URL into the accept.blue Webhook URL field.
	 */
	public static function get_webhook_url(): string {
		$settings = class_exists( 'Frm_AB_Lite_Settings' ) ? Frm_AB_Lite_Settings::get_settings() : [];
		$token    = $settings['webhook_token'] ?? '';
		$base     = rest_url( 'frm-ab-lite/v1/webhook' );
		return $token ? add_query_arg( 'token', rawurlencode( $token ), $base ) : $base;
	}

	/**
	 * Generate and persist a new webhook secret token.
	 */
	public static function generate_webhook_token(): string {
		$token    = wp_generate_password( 32, false );
		$settings = class_exists( 'Frm_AB_Lite_Settings' ) ? Frm_AB_Lite_Settings::get_settings() : [];
		$settings['webhook_token'] = $token;
		update_option( Frm_AB_Lite_Settings::OPTION_KEY, $settings );
		return $token;
	}

	/**
	 * Handle incoming accept.blue webhook.
	 * Authentication: verify secret token in ?token= query param.
	 */
	public static function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		$settings      = class_exists( 'Frm_AB_Lite_Settings' ) ? Frm_AB_Lite_Settings::get_settings() : [];
		$stored_token  = isset( $settings['webhook_token'] ) ? trim( $settings['webhook_token'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$request_token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( ! empty( $stored_token ) ) {
			if ( empty( $request_token ) ) {
				Frm_AB_Lite_Logger::error( 'Webhook rejected: missing token.' );
				return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 401 );
			}
			if ( ! hash_equals( $stored_token, $request_token ) ) {
				Frm_AB_Lite_Logger::error( 'Webhook rejected: invalid token.' );
				return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 401 );
			}
		}

		$payload = $request->get_json_params();
		if ( empty( $payload ) || empty( $payload['event_type'] ) ) {
			return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
		}

		$event_type = sanitize_text_field( $payload['event_type'] );

		/** Generic hook for all accept.blue webhook events. */
		do_action( 'frm_ab_lite_webhook', $event_type, $payload );

		switch ( $event_type ) {
			case 'charge.succeeded':
				self::on_webhook_charge_succeeded( $payload );
				break;
			case 'charge.failed':
				self::on_webhook_charge_failed( $payload );
				break;
			// schedule.* events require Pro version
		}

		return new WP_REST_Response( [ 'received' => true ], 200 );
	}

	// ── Webhook charge handlers ───────────────────────────────────────────────

	private static function on_webhook_charge_succeeded( array $payload ): void {
		$charge_id = $payload['data']['id'] ?? null;
		if ( ! $charge_id ) return;

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'frm_ab_lite_payments',
			[ 'status' => 'complete' ],
			[ 'receipt_id' => $charge_id, 'paysys' => 'acceptblue' ],
			[ '%s' ], [ '%s', '%s' ]
		);
		do_action( 'frm_ab_lite_webhook_charge_succeeded', $payload );
	}

	private static function on_webhook_charge_failed( array $payload ): void {
		$charge_id = $payload['data']['id'] ?? null;
		if ( ! $charge_id ) return;

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'frm_ab_lite_payments',
			[ 'status' => 'failed' ],
			[ 'receipt_id' => $charge_id, 'paysys' => 'acceptblue' ],
			[ '%s' ], [ '%s', '%s' ]
		);
		do_action( 'frm_ab_lite_webhook_charge_failed', $payload );
	}

} // end class

Frm_AB_Lite_Recurring::init();
