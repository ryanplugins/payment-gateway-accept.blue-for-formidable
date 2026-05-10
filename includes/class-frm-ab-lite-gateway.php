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

		// Admin: add Refund button in payment detail
		add_action( 'frm_payment_detail_actions', [ __CLASS__, 'payment_detail_actions' ], 10, 1 );

		// Handle refund AJAX
		add_action( 'wp_ajax_frm_ab_lite_refund', [ __CLASS__, 'ajax_refund' ] );

		// Hook into Formidable's payment status hooks
		add_action( 'frm_payment_status_complete', [ __CLASS__, 'on_payment_complete' ] );

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

	public static function payment_detail_actions( $payment ) {
		if ( ! isset( $payment->paysys ) || $payment->paysys !== 'acceptblue' ) {
			return;
		}
		if ( $payment->status !== 'complete' ) {
			return;
		}
		?>
		<span class="frm_ab_lite_refund_wrap" style="margin-left:10px;">
			<input type="number" step="0.01" min="0"
				id="frm_ab_lite_refund_amount"
				placeholder="<?php esc_attr_e( 'Amount (blank = full)', 'frm-acceptblue-lite' ); ?>"
				style="width:160px;" />
			<button type="button" class="button button-secondary"
				onclick="frmAbRefund( <?php echo intval( $payment->id ); ?>, '<?php echo esc_js( $payment->receipt_id ); ?>' )">
				<?php esc_html_e( 'Refund via Accept.Blue', 'frm-acceptblue-lite' ); ?>
			</button>
		</span>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	public static function ajax_refund() {
		wp_send_json_error( 'Refunds are available in the Pro version.' );
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Runs after every completed accept.blue payment.
	 * Extend via the frm_ab_lite_payment_complete action instead.
	 */
	public static function on_payment_complete( $atts ) {
		if ( ! isset( $atts['payment'] ) ) {
			return;
		}
		if ( ( $atts['payment']->paysys ?? '' ) !== 'acceptblue' ) {
			return;
		}
		// Placeholder — add custom post-payment logic here or use the
		// frm_ab_lite_payment_complete action hook in your theme/child plugin.
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
		$payment = $wpdb->get_row( $wpdb->prepare(
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
