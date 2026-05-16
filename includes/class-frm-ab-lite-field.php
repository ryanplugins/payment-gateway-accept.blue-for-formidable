<?php
/**
 * Accept.Blue Credit Card Payment Field — v0.3 API (verified from source)
 *
 * Constructor: new HostedTokenization(sourceKey, options)
 *   options.target     — CSS selector: appends iframe, creates _loaded promise
 *   options.styles     — object passed to SET_OPTIONS (applied after load)
 *   options.showZip    — bool
 *   options.requireCvv2 — bool
 *
 * Methods (all await _loaded internally):
 *   .setOptions({ styles, showZip, requireCvv2 })  — chainable
 *   .setStyles({ card, input, label, ... })        — chainable, sends SET_STYLE msg
 *   .getNonceToken()   → Promise<string>
 *   .getSurcharge()    → Promise<{ surcharge, binType }>
 *   .resetForm()       — chainable
 *
 * Events via .on(event, fn):
 *   'ready'   — iFrame fully loaded and visible
 *   'change'  — field value changed
 *   'input'   — user typed
 *   'challenge' — 3DS challenge visible
 *
 * @package FrmAcceptBlue
 *
 * @package FrmAcceptBlue
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Frm_AB_Lite_Field {

	const FIELD_TYPE = 'acceptbluelite_payment';

	public static function init() {
		add_filter( 'frm_available_fields',    array( __CLASS__, 'register_field' ) );
		add_action( 'frm_form_fields',         array( __CLASS__, 'render_field' ), 10, 3 );
		add_filter( 'frm_include_credit_card', '__return_true' );
		add_filter( 'frm_single_input_fields', array( __CLASS__, 'add_single_input_type' ) );
		add_action( 'wp_enqueue_scripts',      array( __CLASS__, 'enqueue_assets' ) );
		// Also load on admin pages so action-picker icon CSS is available in the form editor
		add_action( 'admin_enqueue_scripts',   array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_field( $fields ) {
		$fields[ self::FIELD_TYPE ] = array(
			'name' => __( 'Accept.Blue — Card Payment', 'payment-gateway-accept-blue-for-formidable' ),
			'icon' => 'frm_icon_font frm_credit_card_icon',
		);
		return $fields;
	}

	public static function add_single_input_type( $types ) {
		$types[] = self::FIELD_TYPE;
		return $types;
	}

	/**
	 * Build the styles object for HostedTokenization setOptions({ styles }) / setStyles().
	 *
	 * VERIFIED accept.blue v0.3 keys (from iFrame source):
	 *   card, expiryMonth, expiryYear, cvv2
	 * NO other keys (input, label, error, number etc.) are supported.
	 */
	public static function get_style_object( string $preset ): array {
		switch ( $preset ) {

			case 'light':
				return [
					'card'        => 'background-color:#f7f7f7;border:2px solid #d0d5dd;padding:10px;font-size:16px;border-radius:5px;',
					'expiryMonth' => 'background-color:#f7f7f7;border:2px solid #d0d5dd;padding:10px;font-size:16px;border-radius:5px;',
					'expiryYear'  => 'background-color:#f7f7f7;border:2px solid #d0d5dd;padding:10px;font-size:16px;border-radius:5px;',
					'cvv2'        => 'background-color:#f7f7f7;border:2px solid #d0d5dd;padding:10px;font-size:16px;border-radius:5px;',
				];

			case 'dark':
				return [
					'card'        => 'background-color:#1c1c2e;border:2px solid #3a3a5c;padding:10px;font-size:16px;color:#e8e8ff;border-radius:5px;',
					'expiryMonth' => 'background-color:#1c1c2e;border:2px solid #3a3a5c;padding:10px;font-size:16px;color:#e8e8ff;border-radius:5px;',
					'expiryYear'  => 'background-color:#1c1c2e;border:2px solid #3a3a5c;padding:10px;font-size:16px;color:#e8e8ff;border-radius:5px;',
					'cvv2'        => 'background-color:#1c1c2e;border:2px solid #3a3a5c;padding:10px;font-size:16px;color:#e8e8ff;border-radius:5px;',
				];

			default: // 'default' — use accept.blue built-in look (no override)
				return [
					'card'        => 'background-color:#f7f7f7;border:2px solid #ccc;padding:10px;font-size:16px;border-radius:5px;',
					'expiryMonth' => 'background-color:#f7f7f7;border:2px solid #ccc;padding:10px;font-size:16px;border-radius:5px;',
					'expiryYear'  => 'background-color:#f7f7f7;border:2px solid #ccc;padding:10px;font-size:16px;border-radius:5px;',
					'cvv2'        => 'background-color:#f7f7f7;border:2px solid #ccc;padding:10px;font-size:16px;border-radius:5px;',
				];
		}
	}

	// ── Front-end render ──────────────────────────────────────────────────────

	public static function render_field( $field, $field_name, $atts ) {
		if ( $field['type'] !== self::FIELD_TYPE ) return;

		$settings         = Frm_AB_Lite_Settings::get_settings();
		$field_id         = $field['id'];
		$tokenization_key = trim( $settings['tokenization_key'] ?? '' );
		$test_mode        = ! empty( $settings['test_mode'] );

		$script_url = $test_mode
			? 'https://tokenization.sandbox.accept.blue/tokenization/v0.3/'
			: 'https://tokenization.accept.blue/tokenization/v0.3/';

		$container_id    = 'frm_ab_lite_card_container_' . $field_id;
		$nonce_id        = 'frm_ab_lite_card_nonce_'     . $field_id;
		$error_id        = 'frm_ab_lite_card_error_'     . $field_id;
		$surcharge_id    = 'frm_ab_lite_surcharge_'      . $field_id;

		// ── Read action settings for this form ───────────────────────────────
		$iframe_style      = 'default';
		$show_surcharge    = false;
		$show_card_details = true;   // default ON
		$surcharge_label   = 'Surcharge';
		$amount_type       = 'fixed';
		$amount_fixed      = 0.0;
		$amount_field      = '';
		$form_id = $field['form_id'] ?? 0;
		if ( $form_id ) {
			foreach ( (array) FrmFormAction::get_action_for_form( $form_id, 'all' ) as $fa ) {
				if ( ( $fa->post_excerpt ?? '' ) === 'acceptblue' && is_array( $fa->post_content ) ) {
					$form_action    = $fa; // ← assign so actionId/currency/capture are available below
					$iframe_style   = $fa->post_content['iframe_style']      ?? 'default';
					$show_surcharge = ! empty( $fa->post_content['show_surcharge'] );
					$show_card_details = isset( $fa->post_content['show_card_details'] ) ? (bool) $fa->post_content['show_card_details'] : true;
					$surcharge_label   = isset( $fa->post_content['surcharge_label'] ) ? $fa->post_content['surcharge_label'] : 'Surcharge';
					$amount_type    = $fa->post_content['amount_type']       ?? 'fixed';
					$amount_fixed   = floatval( $fa->post_content['amount_fixed'] ?? 0 );
					$amount_field   = $fa->post_content['amount_field']      ?? '';
					// Recurring settings for modal display
					$recurring_enabled    = ! empty( $fa->post_content['recurring_enabled'] );
					$recurring_frequency  = $fa->post_content['recurring_frequency']  ?? 'monthly';
					$recurring_title      = $fa->post_content['recurring_title']      ?? '';
					$recurring_duration   = intval( $fa->post_content['recurring_duration']  ?? 0 );
					$recurring_start      = $fa->post_content['recurring_start']      ?? '';
					$schedule_type        = $fa->post_content['schedule_type']        ?? 'subscription';
					$installment_count    = intval( $fa->post_content['installment_count']   ?? 3 );
					$trial_period_type    = $fa->post_content['trial_period_type']    ?? 'none';
					$trial_days           = intval( $fa->post_content['trial_days']           ?? 0 );
					$three_ds_enabled      = ! empty( $fa->post_content['three_ds_enabled'] );
					$three_ds_frictionless = ! empty( $fa->post_content['three_ds_frictionless'] );
					// Field IDs for verify3DS billing data
					$email_field_id        = $fa->post_content['email_field']          ?? '';
					$name_field_id         = $fa->post_content['name_field']           ?? '';
					$avs_zip_field_id      = $fa->post_content['avs_zip_field']        ?? '';
					$avs_address_field_id  = $fa->post_content['avs_address_field']    ?? '';
					$billing_first_id      = $fa->post_content['billing_first_name']   ?? '';
					$billing_last_id       = $fa->post_content['billing_last_name']    ?? '';
					$billing_street_id     = $fa->post_content['billing_street']       ?? '';
					$billing_city_id       = $fa->post_content['billing_city']         ?? '';
					$billing_state_id      = $fa->post_content['billing_state']        ?? '';
					$billing_zip_id        = $fa->post_content['billing_zip']          ?? '';
					// Per-action tokenization key override
					$override_tok_key = trim( $fa->post_content['override_tokenization_key'] ?? '' );
					if ( $override_tok_key ) {
						$tokenization_key = $override_tok_key;
					}
					break;
				}
			}
		}

				// Recurring defaults
		$recurring_enabled    = false; // Lite: recurring is a Pro-only feature.
		$recurring_frequency  = isset( $recurring_frequency )  ? $recurring_frequency  : 'monthly';
		$recurring_title      = isset( $recurring_title )      ? $recurring_title      : '';
		$recurring_duration   = isset( $recurring_duration )   ? $recurring_duration   : 0;
		$recurring_start      = isset( $recurring_start )      ? $recurring_start      : '';
		$schedule_type        = isset( $schedule_type )        ? $schedule_type        : 'subscription';
		$installment_count    = isset( $installment_count )    ? $installment_count    : 3;
		$trial_period_type    = isset( $trial_period_type )    ? $trial_period_type    : 'none';
		$trial_days           = isset( $trial_days )           ? $trial_days           : 0;
		$three_ds_enabled      = isset( $three_ds_enabled )      ? $three_ds_enabled      : false;
		$three_ds_frictionless = isset( $three_ds_frictionless )  ? $three_ds_frictionless : false;
		$email_field_id        = isset( $email_field_id )        ? $email_field_id        : '';
		$name_field_id         = isset( $name_field_id )         ? $name_field_id         : '';
		$avs_zip_field_id      = isset( $avs_zip_field_id )      ? $avs_zip_field_id      : '';
		$avs_address_field_id  = isset( $avs_address_field_id )  ? $avs_address_field_id  : '';
		$billing_first_id      = isset( $billing_first_id )      ? $billing_first_id      : '';
		$billing_last_id       = isset( $billing_last_id )       ? $billing_last_id       : '';
		$billing_street_id     = isset( $billing_street_id )     ? $billing_street_id     : '';
		$billing_city_id       = isset( $billing_city_id )       ? $billing_city_id       : '';
		$billing_state_id      = isset( $billing_state_id )      ? $billing_state_id      : '';
		$billing_zip_id        = isset( $billing_zip_id )        ? $billing_zip_id        : '';

		$style_obj = self::get_style_object( $iframe_style );

		if ( empty( $tokenization_key ) ) {
			printf(
				'<div id="%s" class="frm_ab_lite_error_msg frm_error" style="margin:0 0 8px;display:block;">⚠ %s</div>
				<input type="hidden" name="%s" id="%s" value="" />',
				esc_attr( $error_id ),
				esc_html__( 'Accept.Blue: Hosted Tokenization Key not configured. Go to Formidable → Global Settings → Accept.Blue.', 'payment-gateway-accept-blue-for-formidable' ),
				esc_attr( $field_name ),
				esc_attr( $nonce_id )
			);
			return;
		}
		?>
		<div class="frm_ab_lite_payment_wrap frm_ab_lite_card_wrap"
			 id="frm_ab_lite_wrap_<?php echo esc_attr( $field_id ); ?>"
			 data-amount-type="<?php echo esc_attr( $amount_type ); ?>"
			 data-amount-fixed="<?php echo esc_attr( number_format( $amount_fixed, 2, '.', '' ) ); ?>"
			 data-amount-field="<?php echo esc_attr( $amount_field ); ?>">

			<!-- Error shown ABOVE sandbox notice — Fix #1 -->
			<div id="<?php echo esc_attr( $error_id ); ?>"
				 class="frm_ab_lite_error_msg frm_error"
				 role="alert"></div>

			<?php if ( $test_mode ) : ?>
				<div class="frm_ab_lite_test_badge">
					⚠ <?php esc_html_e( 'SANDBOX / TEST MODE — No real charges will be made', 'payment-gateway-accept-blue-for-formidable' ); ?>
					<?php if ( $three_ds_enabled ) : ?>
						<span style="display:block;margin-top:5px;font-weight:600;color:#92400e;">
							🔒 <?php esc_html_e( '3DS Test Cards:', 'payment-gateway-accept-blue-for-formidable' ); ?>
						</span>
						<span style="font-weight:400;display:block;margin-top:2px;">
							Visa: <code>4012 0000 3333 0026</code>
							&nbsp; MasterCard: <code>5100 0600 0000 0002</code>
						</span>
					<?php else : ?>
						<span style="font-weight:400;">
							<?php esc_html_e( 'Test cards:', 'payment-gateway-accept-blue-for-formidable' ); ?>
							Visa: <code>4761 5300 0111 1118</code>
							&nbsp; Discover: <code>6011 2087 0111 7775</code>
						</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<!-- 3DS loading overlay: shown between scroll-to-top and challenge appearing -->
			<?php if ( $three_ds_enabled ) : ?>
			<div id="frm_ab_lite_3ds_loader_<?php echo esc_attr( $field_id ); ?>"
				 style="display:none;position:fixed;inset:0;z-index:999998;
				        background:rgba(0,0,0,0.55);align-items:center;justify-content:center;">
				<div style="background:#fff;border-radius:12px;padding:32px 40px;
				            text-align:center;box-shadow:0 8px 32px rgba(0,0,0,0.25);min-width:220px;">
					<div class="frm_ab_lite_spinner"></div>
					<p style="margin:16px 0 4px;font-weight:600;color:#1a3a5c;font-size:0.95em;">
						<?php esc_html_e( 'Verifying your card…', 'payment-gateway-accept-blue-for-formidable' ); ?>
					</p>
					<p style="margin:0;font-size:0.8em;color:#6b7280;">
						<?php esc_html_e( 'Your bank is being contacted. Please wait.', 'payment-gateway-accept-blue-for-formidable' ); ?>
					</p>
				</div>
			</div>
			<?php endif; ?>

			<!-- Submit overlay: shown while payment is being processed and form submits -->
			<div id="frm_ab_lite_submit_loader_<?php echo esc_attr( $field_id ); ?>" class="frm-ab-lite-submit-overlay" style="display:none;" aria-live="assertive" role="status">
				<div class="frm-ab-lite-submit-overlay__card">
					<div class="frm_ab_lite_spinner frm_ab_lite_spinner--lg"></div>
					<p class="frm-ab-lite-submit-overlay__title"><?php esc_html_e( 'Processing payment…', 'payment-gateway-accept-blue-for-formidable' ); ?></p>
					<p class="frm-ab-lite-submit-overlay__sub"><?php esc_html_e( 'Please wait and do not close this page.', 'payment-gateway-accept-blue-for-formidable' ); ?></p>
				</div>
			</div>

			<input type="hidden" id="frm_ab_lite_trans_key_<?php echo esc_attr( $field_id ); ?>" name="frm_ab_lite_trans_key" value="">
			<!-- iFrame injected here by HostedTokenization({ target }) -->
			<div id="<?php echo esc_attr( $container_id ); ?>" class="frm_ab_lite_iframe_container"></div>

			<!-- Surcharge display (shown after getSurcharge() resolves on card change) -->
			<div id="<?php echo esc_attr( $surcharge_id ); ?>" class="frm_ab_lite_surcharge_info"></div>

			<!-- Hidden nonce field — populated by getNonceToken() before submit -->
			<input type="hidden"
				id="<?php echo esc_attr( $nonce_id ); ?>"
				name="<?php echo esc_attr( $field_name ); ?>"
				class="frm_ab_lite_nonce_field"
				value="" />

			<!-- Hidden surcharge amount passed to PHP -->
			<input type="hidden" id="<?php echo esc_attr( $surcharge_id ); ?>_amount" name="frm_ab_lite_surcharge_amount" value="0" />

			<!-- Hidden vault nonce for recurring payment method attach -->
			<input type="hidden" id="frm_ab_lite_vault_nonce_<?php echo esc_attr( $field_id ); ?>" name="frm_ab_lite_vault_nonce" value="" />
			<input type="hidden" id="frm_ab_lite_three_ds_data_<?php echo esc_attr( $field_id ); ?>" name="frm_ab_lite_three_ds_data" value="" />
			<input type="hidden" id="frm_ab_lite_three_ds_result_<?php echo esc_attr( $field_id ); ?>" name="frm_ab_lite_three_ds_result" value="" />
			<?php // 3DS challenge rendered by SDK — no custom modal needed ?>
			<!-- ── Confirmation Modal ──────────────────────────────────────── -->
			<div id="frm_ab_lite_confirm_modal_<?php echo esc_attr( $field_id ); ?>"
				 class="frm_ab_lite_modal_overlay"
				 style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:99999;align-items:center;justify-content:center;">
				<div class="frm_ab_lite_modal_box" style="background:#fff;border-radius:12px;padding:28px 30px;max-width:420px;width:90%;box-shadow:0 12px 48px rgba(0,0,0,.22);">
					<h3 style="margin:0 0 20px;font-size:1.25em;font-weight:700;color:#1a1a2e;"><?php esc_html_e( 'Confirm Payment', 'payment-gateway-accept-blue-for-formidable' ); ?></h3>

					<!-- Card details populated by getData() -->
					<div id="frm_ab_lite_confirm_card_<?php echo esc_attr($field_id); ?>"
						 style="display:flex;align-items:center;gap:8px;background:#f4f6fa;border:1px solid #e0e4ef;border-radius:8px;padding:11px 14px;margin-bottom:16px;font-size:0.95em;color:#333;">
						<?php esc_html_e( 'Card details secured by accept.blue', 'payment-gateway-accept-blue-for-formidable' ); ?>
					</div>

					<table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:0.97em;">
						<tr>
							<td style="padding:7px 0;color:#555;font-weight:500;"><?php esc_html_e( 'Amount', 'payment-gateway-accept-blue-for-formidable' ); ?></td>
							<td id="frm_ab_lite_confirm_amount_<?php echo esc_attr($field_id); ?>" style="text-align:right;font-weight:600;color:#1a1a2e;"></td>
						</tr>
						<tr id="frm_ab_lite_confirm_surcharge_row_<?php echo esc_attr($field_id); ?>" style="display:none;">
							<td id="frm_ab_lite_confirm_surcharge_label_<?php echo esc_attr($field_id); ?>" style="padding:7px 0;color:#555;font-weight:500;"><?php echo esc_html( $surcharge_label ); ?></td>
							<td id="frm_ab_lite_confirm_surcharge_<?php echo esc_attr($field_id); ?>" style="text-align:right;color:#c0392b;font-weight:500;"></td>
						</tr>
						<tr id="frm_ab_lite_confirm_total_row_<?php echo esc_attr($field_id); ?>" style="display:none;">
							<td colspan="2" style="padding:0;"><div style="height:1px;background:#e8e8e8;margin:6px 0;"></div></td>
						</tr>
						<tr id="frm_ab_lite_confirm_total_row2_<?php echo esc_attr($field_id); ?>" style="display:none;">
							<td style="padding:7px 0 0;font-weight:700;font-size:1.05em;color:#1a1a2e;"><?php esc_html_e( 'Total', 'payment-gateway-accept-blue-for-formidable' ); ?></td>
							<td id="frm_ab_lite_confirm_total_<?php echo esc_attr($field_id); ?>" style="text-align:right;font-weight:700;font-size:1.15em;color:#1a1a2e;padding-top:7px;"></td>
						</tr>
					</table>

									<?php if ( ! empty( $recurring_enabled ) ) :
						$freq_labels    = [ 'daily'=>'Daily','weekly'=>'Weekly','biweekly'=>'Bi-Weekly','monthly'=>'Monthly','bimonthly'=>'Bi-Monthly','quarterly'=>'Quarterly','biannually'=>'Bi-Annually','annually'=>'Annually' ];
						$freq_label     = $freq_labels[ $recurring_frequency ] ?? ucfirst( $recurring_frequency );
						$is_installment = ( $schedule_type === 'installment' );
						$has_trial      = ( $trial_period_type === 'days' && $trial_days > 0 );
						// Row style constants
						$rs  = 'display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(188,212,240,0.5);';
						$rsl = 'color:#6b7a8d;font-size:0.82em;font-weight:500;text-transform:uppercase;letter-spacing:0.04em;';
						$rsv = 'color:#1a3a5c;font-weight:600;font-size:0.92em;text-align:right;';
					?>
				<?php if ( $is_installment ) : ?>
				<!-- ── INSTALLMENT NOTICE ── -->
				<div style="background:linear-gradient(135deg,#f0f7ff 0%,#e8f0fb 100%);border:1px solid #bcd4f0;border-radius:10px;overflow:hidden;margin-bottom:16px;">
					<div style="background:#1a4a7a;padding:9px 14px;display:flex;align-items:center;gap:8px;">
						<span style="font-size:1.1em;">&#x1F4C5;</span>
						<strong style="color:#fff;font-size:0.9em;letter-spacing:0.03em;"><?php esc_html_e( 'INSTALLMENT PLAN', 'payment-gateway-accept-blue-for-formidable' ); ?></strong>
					</div>
					<div style="padding:4px 14px 0;">
						<?php if ( $recurring_title ) : ?>
						<div style="<?php echo esc_attr( $rs ); ?>border-bottom:none;padding-bottom:4px;">
							<span style="color:#1a3a5c;font-weight:700;font-size:0.97em;"><?php echo esc_html( $recurring_title ); ?></span>
						</div>
						<div style="height:1px;background:rgba(188,212,240,0.5);margin-bottom:2px;"></div>
						<?php endif; ?>
						<div style="<?php echo esc_attr( $rs ); ?>">
							<span style="<?php echo esc_attr( $rsl ); ?>"><?php esc_html_e( 'Payments', 'payment-gateway-accept-blue-for-formidable' ); ?></span>
							<span style="<?php echo esc_attr( $rsv ); ?>"><?php echo esc_html( $installment_count ); ?></span>
						</div>
						<div style="<?php echo esc_attr( $rs ); ?>">
							<span style="<?php echo esc_attr( $rsl ); ?>"><?php esc_html_e( 'Frequency', 'payment-gateway-accept-blue-for-formidable' ); ?></span>
							<span style="<?php echo esc_attr( $rsv ); ?>"><?php echo esc_html( $freq_label ); ?></span>
						</div>
						<div style="<?php echo esc_attr( $rs ); ?>">
							<span style="<?php echo esc_attr( $rsl ); ?>"><?php esc_html_e( 'Per payment', 'payment-gateway-accept-blue-for-formidable' ); ?></span>
							<span style="<?php echo esc_attr( $rsv ); ?>"><span id="frm_ab_lite_installment_per_<?php echo esc_attr($field_id); ?>">…</span></span>
						</div>
						<?php if ( $has_trial ) : ?>
						<div style="<?php echo esc_attr( $rs ); ?>">
							<span style="<?php echo esc_attr( $rsl ); ?>"><?php esc_html_e( 'Trial', 'payment-gateway-accept-blue-for-formidable' ); ?></span>
							<span style="background:#e8f5e9;color:#2e7d32;font-weight:600;font-size:0.82em;padding:2px 8px;border-radius:20px;"><?php // translators: %d is the number of free trial days.
								echo esc_html( sprintf( __( '%d days free', 'payment-gateway-accept-blue-for-formidable' ), $trial_days ) ); ?></span>
						</div>
						<?php endif; ?>
						<div style="background:#1a4a7a;margin:8px -14px 0;padding:9px 14px;display:flex;justify-content:space-between;align-items:center;">
							<span style="color:rgba(255,255,255,0.8);font-size:0.8em;font-weight:500;"><?php esc_html_e( 'Total charged', 'payment-gateway-accept-blue-for-formidable' ); ?></span>
							<strong style="color:#fff;font-size:1em;"><span id="frm_ab_lite_installment_total_<?php echo esc_attr($field_id); ?>">…</span></strong>
						</div>
					</div>
				</div>

				<?php else : ?>
				<!-- ── SUBSCRIPTION NOTICE ── -->
				<div style="background:linear-gradient(135deg,#f0f7ff 0%,#e8f0fb 100%);border:1px solid #bcd4f0;border-radius:10px;overflow:hidden;margin-bottom:16px;">
					<div style="background:#0073aa;padding:9px 14px;display:flex;align-items:center;gap:8px;">
						<span style="font-size:1.1em;">&#x1F501;</span>
						<strong style="color:#fff;font-size:0.9em;letter-spacing:0.03em;"><?php esc_html_e( 'RECURRING PAYMENT', 'payment-gateway-accept-blue-for-formidable' ); ?></strong>
					</div>
					<div style="padding:4px 14px 10px;">
						<?php if ( $recurring_title ) : ?>
						<div style="<?php echo esc_attr( $rs ); ?>border-bottom:none;padding-bottom:4px;">
							<span style="color:#1a3a5c;font-weight:700;font-size:0.97em;"><?php echo esc_html( $recurring_title ); ?></span>
						</div>
						<div style="height:1px;background:rgba(188,212,240,0.5);margin-bottom:2px;"></div>
						<?php endif; ?>
						<div style="<?php echo esc_attr( $rs ); ?>">
							<span style="<?php echo esc_attr( $rsl ); ?>"><?php esc_html_e( 'Amount', 'payment-gateway-accept-blue-for-formidable' ); ?></span>
							<span style="<?php echo esc_attr( $rsv ); ?>"><span id="frm_ab_lite_sub_amount_<?php echo esc_attr($field_id); ?>">…</span> / <?php echo esc_html( strtolower( $freq_label ) ); ?></span>
						</div>
						<div style="<?php echo esc_attr( $rs ); ?>">
							<span style="<?php echo esc_attr( $rsl ); ?>"><?php esc_html_e( 'Frequency', 'payment-gateway-accept-blue-for-formidable' ); ?></span>
							<span style="<?php echo esc_attr( $rsv ); ?>"><?php echo esc_html( $freq_label ); ?></span>
						</div>
						<div style="<?php echo esc_attr( $rs ); ?><?php echo $has_trial ? '' : 'border-bottom:none;'; ?>">
							<span style="<?php echo esc_attr( $rsl ); ?>"><?php esc_html_e( 'Duration', 'payment-gateway-accept-blue-for-formidable' ); ?></span>
							<span style="<?php echo esc_attr( $rsv ); ?>">
								<?php if ( $recurring_duration > 0 ) : ?>
									<?php
									// translators: %d is the number of payments in the recurring schedule.
								echo esc_html( sprintf( _n( '%d payment', '%d payments', $recurring_duration, 'payment-gateway-accept-blue-for-formidable' ), $recurring_duration ) );
									?>
								<?php else : ?>
									<?php esc_html_e( 'Until cancelled', 'payment-gateway-accept-blue-for-formidable' ); ?>
								<?php endif; ?>
							</span>
						</div>
						<?php if ( $has_trial ) : ?>
						<div style="<?php echo esc_attr( $rs ); ?>border-bottom:none;">
							<span style="<?php echo esc_attr( $rsl ); ?>"><?php esc_html_e( 'Trial', 'payment-gateway-accept-blue-for-formidable' ); ?></span>
							<span style="background:#e8f5e9;color:#2e7d32;font-weight:600;font-size:0.82em;padding:2px 8px;border-radius:20px;"><?php // translators: %d is the number of free trial days.
								echo esc_html( sprintf( __( '%d days free', 'payment-gateway-accept-blue-for-formidable' ), $trial_days ) ); ?></span>
						</div>
						<?php endif; ?>
					</div>
				</div>
				<?php endif; ?>
				<?php endif; ?>
				<p style="font-size:0.84em;color:#888;margin:0 0 20px;line-height:1.5;">
					<?php if ( ! empty( $recurring_enabled ) ) : ?>
						<?php esc_html_e( 'By clicking Pay Now, you authorise this charge and the recurring schedule shown above.', 'payment-gateway-accept-blue-for-formidable' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'By clicking Pay Now, you authorise this charge to your card. This action cannot be undone.', 'payment-gateway-accept-blue-for-formidable' ); ?>
					<?php endif; ?>
				</p>
					<div style="display:flex;gap:10px;">
						<button type="button" id="frm_ab_lite_confirm_pay_<?php echo esc_attr($field_id); ?>"
							style="flex:1;background:#0073aa;color:#fff;border:none;border-radius:7px;padding:12px;font-size:1em;cursor:pointer;font-weight:600;letter-spacing:0.02em;">
							<?php esc_html_e( '🔒 Pay Now', 'payment-gateway-accept-blue-for-formidable' ); ?>
						</button>
						<button type="button" id="frm_ab_lite_confirm_cancel_<?php echo esc_attr($field_id); ?>"
							style="flex:1;background:#fff;color:#555;border:1px solid #d0d0d0;border-radius:7px;padding:12px;font-size:1em;cursor:pointer;font-weight:500;">
							<?php esc_html_e( 'Cancel', 'payment-gateway-accept-blue-for-formidable' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>

		<?php
		// Enqueue the external card JS and pass this field's config via wp_localize_script.
		// Using an external file prevents WordPress filters (wptexturize, wpautop) from
		// mangling operators like && and || inside the inline script.
		wp_enqueue_script( 'frm-acceptblue-lite-card' );

		$field_cfg = array(
			'fieldId'         => (string) $field_id,
			'sourceKey'       => $tokenization_key,
			// Key is intentionally 'sdkUrl' not 'scriptUrl'.
			// Formidable Forms v1.16+ scans wp_localize_script data for a 'scriptUrl'
			// key and calls wp_enqueue_style() with its value, treating it as a
			// payment-gateway stylesheet URL — which produces a MIME-type error and
			// causes the raw SDK JavaScript source to appear as text in the card div.
			'sdkUrl'          => $script_url,
			'containerId'     => '#' . $container_id,
			'nonceId'         => $nonce_id,
			'errorId'         => $error_id,
			'surchargeId'     => $surcharge_id,
			'testMode'        => $test_mode,
			'styleObj'        => $style_obj,
			'showSurcharge'   => $show_surcharge,
			'showCardDetails'  => $show_card_details,
			'surchargeLabel'   => $surcharge_label,
			'debugLog'         => Frm_AB_Lite_Settings::is_debug_enabled(),
			'recurringEnabled'    => $recurring_enabled,
			'scheduleType'        => $schedule_type,
			'installmentCount'    => $installment_count,
			'vaultNonceId'     => 'frm_ab_lite_vault_nonce_' . $field_id,
			'threeDsDataId'    => 'frm_ab_lite_three_ds_data_' . $field_id,
			'paayEnabled'          => ! empty( $settings['paay_api_key'] ),
			'paayApiKey'           => $settings['paay_api_key'] ?? '',
			'threeDsEnabled'       => $three_ds_enabled,
			'threeDsLoaderId'      => $three_ds_enabled ? 'frm_ab_lite_3ds_loader_' . $field_id : '',
			'submitLoaderId'       => 'frm_ab_lite_submit_loader_' . $field_id,
			'threeDsFrictionless'  => $three_ds_frictionless,
			'threeDsResultId'      => 'frm_ab_lite_three_ds_result_' . $field_id,
			// Field IDs for verify3DS billing payload (item_meta[id] DOM selectors)
			'fieldEmail'           => $email_field_id,
			'fieldName'            => $name_field_id,
			'fieldAvsZip'          => $avs_zip_field_id,
			'fieldAvsAddress'      => $avs_address_field_id,
			'fieldBillingFirst'    => $billing_first_id,
			'fieldBillingLast'     => $billing_last_id,
			'fieldBillingStreet'   => $billing_street_id,
			'fieldBillingCity'     => $billing_city_id,
			'fieldBillingState'    => $billing_state_id,
			'fieldBillingZip'      => $billing_zip_id,
			'amountType'      => $amount_type,
			'amountFixed'     => $amount_fixed,
			'amountFieldId'   => $amount_field,
			'formId'          => $form_id,
			'actionId'        => isset( $form_action ) ? $form_action->ID : 0,
			'currency'        => isset( $form_action->post_content['currency'] ) ? $form_action->post_content['currency'] : 'USD',
			'capture'         => isset( $form_action->post_content['capture'] ) ? (bool) $form_action->post_content['capture'] : true,
			'precheckNonce'   => wp_create_nonce( 'frm_ab_lite_precheck_nonce' ),
			'i18n'            => array(
				'loadFailed'    => __( 'Payment form failed to load. Please refresh the page.', 'payment-gateway-accept-blue-for-formidable' ),
				'notReady'      => __( 'Payment form not ready. Please refresh the page.', 'payment-gateway-accept-blue-for-formidable' ),
				'noToken'       => __( 'Could not connect to payment processor. Please check your API key in Accept.Blue settings.', 'payment-gateway-accept-blue-for-formidable' ),
				'cardFailed'    => __( 'Card validation failed. Please check your card details and try again.', 'payment-gateway-accept-blue-for-formidable' ),
				'formError'     => __( 'Payment form error. Please refresh and try again.', 'payment-gateway-accept-blue-for-formidable' ),
				'scriptBlocked' => __( 'Payment script blocked. Please disable your ad blocker and refresh.', 'payment-gateway-accept-blue-for-formidable' ),
				'cardSecured'   => __( 'Card details secured by accept.blue', 'payment-gateway-accept-blue-for-formidable' ),
			),
		);

		// wp_localize_script appends configs to an array so multiple card fields on
		// the same page each get their own entry in frmAbCardConfigs[].
		$existing = wp_scripts()->get_data( 'frm-acceptblue-lite-card', 'data' );
		if ( $existing ) {
			// Already localised - append this field's config via inline script.
			wp_add_inline_script(
				'frm-acceptblue-lite-card',
				'frmAbCardConfigs.push(' . wp_json_encode( $field_cfg ) . ');'
			);
		} else {
			wp_localize_script(
				'frm-acceptblue-lite-card',
				'frmAbCardConfigs',
				array( $field_cfg )
			);
		}
	}

	/**
	 * Enqueue front-end CSS and register the card field JS.
	 * JS configs are appended per-field via wp_localize_script() in render_field().
	 */
	public static function enqueue_assets() {
		// Use minified assets in production; fall back to source when WP_DEBUG is enabled.
		$is_debug  = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$css_file  = $is_debug ? 'assets/frm-acceptblue.src.css'      : 'assets/frm-acceptblue.min.css';
		$js_file   = $is_debug ? 'assets/frm-acceptblue-card.src.js'  : 'assets/frm-acceptblue-card.min.js';

		$settings   = Frm_AB_Lite_Settings::get_settings();
		$test_mode  = ! empty( $settings['test_mode'] );
		$sdk_url    = $test_mode
			? 'https://tokenization.sandbox.accept.blue/tokenization/v0.3/'
			: 'https://tokenization.accept.blue/tokenization/v0.3/';

		// Only enqueue the frontend stylesheet on the frontend — not in wp-admin.
		// Admin icon styles are handled by admin_css() in class-frm-ab-lite-admin-panel.php.
		//
		// CSS handle uses a unique suffix '-css' to avoid collisions with any asset
		// pipeline in Formidable Forms or other plugins that might iterate over
		// handles matching the plugin slug and treat them as payment-gateway scripts.
		if ( ! is_admin() ) {
			wp_enqueue_style(
				'payment-gateway-accept-blue-for-formidable-css',
				FRM_AB_LITE_URL . $css_file,
				array(),
				FRM_AB_LITE_VERSION
			);
		}

		// Pre-register the external Accept.Blue tokenization SDK as a SCRIPT handle
		// before our field JS runs.  This serves two purposes:
		//
		//  1. Prevents WordPress's asset deduplication / Formidable's payment-gateway
		//     asset hooks from treating the SDK URL as a stylesheet (which produces a
		//     MIME-type error and causes the raw JS source to appear as visible text
		//     in the card-container div instead of an iframe).
		//
		//  2. Allows wp_script_is() checks elsewhere to detect the SDK without
		//     triggering a second injection.
		//
		// We do NOT call wp_enqueue_script() for this handle — our field JS loads it
		// dynamically via loadScript() so it can skip injection when
		// window.HostedTokenization is already defined (BFCache, etc.).
		if ( ! wp_script_is( 'accept-blue-hosted-tokenization', 'registered' ) ) {
			wp_register_script(
				'accept-blue-hosted-tokenization',
				$sdk_url,
				array(),
				FRM_AB_LITE_VERSION,
				true   // footer
			);
		}

		wp_register_script(
			'frm-acceptblue-lite-card',
			FRM_AB_LITE_URL . $js_file,
			array(),
			FRM_AB_LITE_VERSION,
			true  // load in footer
		);
		// The script is enqueued on demand in render_field() when a card field is present.
	}
}

Frm_AB_Lite_Field::init();
