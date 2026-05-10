<?php
/**
 * Dashboard and entry payment views.
 * Transactions panel, revenue dashboard, and refund/capture/void actions
 * are available in the Pro version.
 *
 * @package Formidable_AcceptBlue_Lite
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Frm_AB_Lite_Dashboard {

	public static function init(): void {
		add_action( 'admin_enqueue_scripts',              [ __CLASS__, 'enqueue' ] );
		add_action( 'wp_ajax_frm_ab_lite_capture',        [ __CLASS__, 'ajax_capture' ] );
		add_action( 'wp_ajax_frm_ab_lite_void',           [ __CLASS__, 'ajax_void' ] );
		add_action( 'wp_ajax_frm_ab_lite_refund_dashboard', [ __CLASS__, 'ajax_refund' ] );
		add_action( 'wp_ajax_frm_ab_lite_adjust_capture', [ __CLASS__, 'ajax_adjust_capture' ] );
	}

	// ── Pro stubs — return friendly error for all action AJAX calls ───────────

	public static function ajax_capture(): void {
		wp_send_json_error( __( 'Capture is available in the Pro version.', 'frm-acceptblue-lite' ) );
	}

	public static function ajax_void(): void {
		wp_send_json_error( __( 'Void is available in the Pro version.', 'frm-acceptblue-lite' ) );
	}

	public static function ajax_refund(): void {
		wp_send_json_error( __( 'Refunds are available in the Pro version.', 'frm-acceptblue-lite' ) );
	}

	public static function ajax_adjust_capture(): void {
		wp_send_json_error( __( 'Adjust & Capture is available in the Pro version.', 'frm-acceptblue-lite' ) );
	}

	// ── Admin styles ──────────────────────────────────────────────────────────

	public static function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'frm-ab-lite-' ) ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', '
			.frm-ab-lite-kpi-strip{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0;}
			.frm-ab-lite-kpi{background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px 20px;min-width:130px;text-align:center;border-top:3px solid #ccc;}
			.frm-ab-lite-kpi strong{display:block;font-size:1.7em;line-height:1.2;}
			.frm-ab-lite-kpi span{color:#666;font-size:.82em;}
			.frm-ab-lite-kpi--green{border-top-color:#28a745;} .frm-ab-lite-kpi--green strong{color:#28a745;}
			.frm-ab-lite-kpi--blue{border-top-color:#0073aa;}  .frm-ab-lite-kpi--blue strong{color:#0073aa;}
			.frm-ab-lite-kpi--red{border-top-color:#dc3545;}   .frm-ab-lite-kpi--red strong{color:#dc3545;}
		' );
	}

} // end class

Frm_AB_Lite_Dashboard::init();
