<?php
/**
 * Accept.Blue Form Action
 *
 * Formidable API contract:
 *  - Constructor: $this->FrmFormAction(...)
 *  - form() signature: form( $form_action, $args = array() )
 *  - Payment fires via: frm_trigger_{slug}_action hook
 *
 * @package FrmAcceptBlue
 */

defined( 'ABSPATH' ) || exit;

class Frm_AB_Lite_Form_Action extends FrmFormAction {

	const ACTION_SLUG = 'acceptblue';

	public function __construct() {
		$this->FrmFormAction(
			self::ACTION_SLUG,
			__( 'Accept.Blue Payment', 'payment-gateway-accept-blue-for-formidable' ),
			array(
				'classes'  => 'frm_creditcard_icon',
				'color'    => '#279EDA',  // accept.blue brand blue — Formidable 6.x uses this to colour the inner circle
				'limit'    => 99,
				'active'   => true,
				'priority' => 10,
				'event'    => array( 'create', 'update' ),
			)
		);
	}

	/**
	 * Prepare/sanitize action settings before Formidable saves them to post_content.
	 * Without this, Formidable's default sanitizer may strip or mangle settings.
	 */
	/**
	 * Formidable calls prepare() OR frm_action_options_before_save to sanitize settings.
	 * Accept both 1-arg and 2-arg signatures.
	 */
	public function prepare( $options, $args = array() ) {
		if ( is_object( $options ) ) {
			// Formidable sometimes passes the action object — extract post_content
			$options = is_array( $options->post_content ) ? $options->post_content : array();
		}
		if ( ! is_array( $options ) ) return array();
		// String fields
		$string_fields = array(
			'payment_field', 'amount_type', 'amount_field', 'currency',
			'name_field', 'email_field', 'customer_identifier_field',
			'customer_number_field', 'avs_address_field', 'avs_zip_field',
			'iframe_style', 'iframe_custom_css', 'description', 'surcharge_label',
			'billing_first_name', 'billing_last_name', 'billing_street',
			'billing_street2', 'billing_city', 'billing_state', 'billing_zip',
			'billing_country', 'billing_phone',
			'li_sku', 'li_description', 'li_quantity', 'li_cost_type',
			'li_cost_field', 'li_qty_type',
			'recurring_frequency', 'recurring_title', 'trial_period_type', 'schedule_type',
		);
		// API credential fields — use wp_strip_all_tags (not sanitize_text_field)
		// to preserve base64 chars (+, /, =) and other non-HTML characters that
		// sanitize_text_field would incorrectly strip from API keys.
		$credential_fields = array(
			'override_api_key', 'override_pin', 'override_tokenization_key',
		);
		// Numeric fields
		$numeric_fields = array(
			'amount_fixed', 'li_cost_fixed', 'li_qty_fixed', 'li_tax_rate',
			'recurring_duration', 'trial_days', 'installment_count',
		);
		// Boolean (checkbox) fields — present = true, absent = false
		$bool_fields = array(
			'capture', 'show_surcharge', 'show_card_details', 'save_card',
			'li_enabled', 'recurring_enabled',
			'three_ds_enabled', 'three_ds_frictionless',
		);

		$clean = array();
		foreach ( $string_fields as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( $options[ $key ] );
			}
		}
		// API credential fields: preserve all printable non-HTML characters
		foreach ( $credential_fields as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$clean[ $key ] = trim( wp_strip_all_tags( $options[ $key ] ) );
			}
		}
		foreach ( $numeric_fields as $key ) {
			if ( isset( $options[ $key ] ) && $options[ $key ] !== '' ) {
				$clean[ $key ] = $options[ $key ]; // keep as string for form display
			}
		}
		foreach ( $bool_fields as $key ) {
			// Hidden field sends "0" when unchecked, checkbox sends "1" when checked
			// Last value wins (checkbox "1" overrides hidden "0" in POST array)
			$clean[ $key ] = isset( $options[ $key ] ) && intval( $options[ $key ] ) === 1 ? 1 : 0;
		}

		// Recurring start date
		if ( isset( $options['recurring_start'] ) ) {
			$clean['recurring_start'] = sanitize_text_field( $options['recurring_start'] );
		}

		return $clean;
	}

	/**
	 * Return default settings — Formidable uses this when creating a new action.
	 */
	public function get_defaults() {
		return array(
			'capture'              => 1,
			'three_ds_enabled'     => 0,
			'three_ds_frictionless'=> 0,
			'show_card_details'=> 1,
			'show_surcharge'   => 0,
			'amount_type'      => 'fixed',
			'currency'         => 'USD',
			'surcharge_label'  => 'Surcharge',
			'li_enabled'       => 0,
			'li_qty_type'      => 'fixed',
			'li_qty_fixed'     => 1,
			'li_cost_type'     => 'fixed',
			'recurring_enabled'   => 0,
			'recurring_frequency' => 'monthly',
			'recurring_duration'  => 0,
			'trial_period_type'   => 'none',  // 'none' | 'days'
			'trial_days'          => 0,
			'schedule_type'        => 'subscription', // 'subscription' | 'installment'
			'installment_count'    => 3,
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Admin UI
	// ─────────────────────────────────────────────────────────────────────────

	public function form( $form_action, $args = array() ) {
		$form     = isset( $args['form'] ) ? $args['form'] : null;
		$fields   = ( $form && isset( $form->id ) ) ? FrmField::get_all_for_form( $form->id ) : array();
		$s        = is_array( $form_action->post_content ) ? $form_action->post_content : array();
		$ac       = $this; // action_control alias

		// Helper to get saved setting with default
		$g = function( $key, $default = '' ) use ( $s ) {
			return isset( $s[ $key ] ) ? $s[ $key ] : $default;
		};

		// Field type filter for dropdown — card fields only
		$ab_field_types = array( Frm_AB_Lite_Field::FIELD_TYPE );
		?>
				<div class="frm-ab-lite-pro-notice" style="
			display:flex;
			align-items:center;
			gap:14px;
			background:linear-gradient(135deg,#1d2327 0%,#2c3a47 100%);
			color:#fff;
			padding:14px 20px;
			border-radius:8px;
			margin-bottom:18px;
			box-shadow:0 3px 12px rgba(0,0,0,.18);
			font-size:13.5px;
			line-height:1.5;
		">
			<span style="font-size:22px;flex-shrink:0;">&#128274;</span>
			<span>
				<strong style="font-size:14px;">Payment gateway: accept.blue for Formidable</strong><br>
				Recurring billing, refunds, webhooks, fraud shield, and more are available in the
				<a href="https://www.patreon.com/posts/formidable-blue-157799373" target="_blank" rel="noopener"
				   style="color:#7dd3fc;font-weight:700;text-decoration:none;">
					&#8599; Pro version
				</a>.
			</span>
		</div>
		<table class="form-table frm-no-margin">
		<tbody>

		<!-- ════ PAYMENT FIELD ════ -->
		<tr>
			<th><label><?php esc_html_e( 'Payment Field', 'payment-gateway-accept-blue-for-formidable' ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( $ac->get_field_name( 'payment_field' ) ); ?>">
					<option value=""><?php esc_html_e( '— Select accept.blue card field —', 'payment-gateway-accept-blue-for-formidable' ); ?></option>
					<?php foreach ( $fields as $f ) :
						if ( ! in_array( $f->type, $ab_field_types, true ) ) continue;
						?>
						<option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $g('payment_field'), $f->id ); ?>><?php echo esc_html( $f->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>

</tbody>
<tbody>
<tr>
		<td colspan="2" style="padding:10px 14px;background:#f0f6fc;border-left:3px solid #2271b1;">
			<strong><?php esc_html_e( 'Auth-Only / Capture', 'payment-gateway-accept-blue-for-formidable' ); ?></strong> &mdash; 
			<?php esc_html_e( 'Available in the Pro version. Upgrade to enable authorise-only payments and manual capture.', 'payment-gateway-accept-blue-for-formidable' ); ?>
		</td>
</tr>
</tbody>
<tbody>
<tr>
		<td colspan="2" style="padding:10px 14px;background:#f0f6fc;border-left:3px solid #2271b1;">
			<strong><?php esc_html_e( '3-D Secure 2 (3DS2)', 'payment-gateway-accept-blue-for-formidable' ); ?></strong> &mdash; 
			<?php esc_html_e( 'Available in the Pro version. Upgrade to enable EMV 3DS2 strong customer authentication.', 'payment-gateway-accept-blue-for-formidable' ); ?>
		</td>
</tr>
</tbody>
<tbody>

		<!-- ════ SHOW SURCHARGE ════ -->
		<tr>
			<th><?php esc_html_e( 'Show Surcharge', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $ac->get_field_name( 'show_surcharge' ) ); ?>" value="0" />
				<label>
					<input type="checkbox"
						name="<?php echo esc_attr( $ac->get_field_name( 'show_surcharge' ) ); ?>"
						value="1"
						<?php checked( 1, $g( 'show_surcharge', 0 ) ); ?>
						onchange="document.getElementById('frm_ab_lite_surcharge_label_row').style.display=this.checked?'':'none';" />
					<?php esc_html_e( 'Display surcharge amount below the card field', 'payment-gateway-accept-blue-for-formidable' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Calls getSurcharge() from the accept.blue iFrame and shows the applicable surcharge to the customer. Only applicable if your merchant account has surcharging configured.', 'payment-gateway-accept-blue-for-formidable' ); ?>
				</p>
			</td>
		</tr>

		<!-- ════ SURCHARGE LABEL ════ -->
		<tr id="frm_ab_lite_surcharge_label_row" <?php echo empty( $g('show_surcharge') ) ? 'style="display:none;"' : ''; ?>>
			<th><?php esc_html_e( 'Surcharge Label', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<input type="text"
					name="<?php echo esc_attr( $ac->get_field_name( 'surcharge_label' ) ); ?>"
					value="<?php echo esc_attr( $g( 'surcharge_label', 'Surcharge' ) ); ?>"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'Surcharge', 'payment-gateway-accept-blue-for-formidable' ); ?>" />
				<p class="description">
					<?php esc_html_e( 'Label shown next to the surcharge amount in the confirmation modal and below the card field.', 'payment-gateway-accept-blue-for-formidable' ); ?>
				</p>
			</td>
		</tr>

		<!-- ════ SHOW CARD DETAILS IN MODAL ════ -->
		<tr>
			<th><?php esc_html_e( 'Show Card Details', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $ac->get_field_name( 'show_card_details' ) ); ?>" value="0" />
				<label>
					<input type="checkbox"
						name="<?php echo esc_attr( $ac->get_field_name( 'show_card_details' ) ); ?>"
						value="1"
						<?php checked( 1, $g( 'show_card_details', 1 ) ); ?> />
					<?php esc_html_e( 'Show card details (card number, brand, expiry) in the payment confirmation modal', 'payment-gateway-accept-blue-for-formidable' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'When checked, the confirmation modal will display the masked card number and expiry date retrieved from the accept.blue iFrame via getData(). Uncheck to show only the amount.', 'payment-gateway-accept-blue-for-formidable' ); ?>
				</p>
			</td>
		</tr>

		<!-- ════ AMOUNT ════ -->
		<tr>
			<th><?php esc_html_e( 'Charge Amount', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<label><input type="radio" name="<?php echo esc_attr( $ac->get_field_name( 'amount_type' ) ); ?>" value="fixed" <?php checked( $g('amount_type','fixed'), 'fixed' ); ?> /> <?php esc_html_e( 'Fixed:', 'payment-gateway-accept-blue-for-formidable' ); ?></label>
				<input type="number" step="0.01" min="0.01" style="width:100px;" name="<?php echo esc_attr( $ac->get_field_name( 'amount_fixed' ) ); ?>" value="<?php echo esc_attr( $g('amount_fixed') ); ?>" placeholder="<?php esc_attr_e( '25.00', 'payment-gateway-accept-blue-for-formidable' ); ?>" />
				<br /><br />
				<label><input type="radio" name="<?php echo esc_attr( $ac->get_field_name( 'amount_type' ) ); ?>" value="field" <?php checked( $g('amount_type','fixed'), 'field' ); ?> /> <?php esc_html_e( 'From field:', 'payment-gateway-accept-blue-for-formidable' ); ?></label>
				<select name="<?php echo esc_attr( $ac->get_field_name( 'amount_field' ) ); ?>">
					<option value=""><?php esc_html_e( '— Select field —', 'payment-gateway-accept-blue-for-formidable' ); ?></option>
					<?php foreach ( $fields as $f ) : ?>
						<option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $g('amount_field'), $f->id ); ?>><?php echo esc_html( $f->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>

		<!-- ════ CURRENCY ════ -->
		<tr>
			<th><label><?php esc_html_e( 'Currency', 'payment-gateway-accept-blue-for-formidable' ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( $ac->get_field_name( 'currency' ) ); ?>">
					<?php foreach ( array( 'USD' => 'USD — US Dollar', 'CAD' => 'CAD — Canadian Dollar', 'GBP' => 'GBP — British Pound', 'EUR' => 'EUR — Euro', 'AUD' => 'AUD — Australian Dollar' ) as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $g('currency','USD'), $code ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>

		<!-- ════ NAME ON CARD ════ -->
		<tr>
			<th><?php esc_html_e( 'Name on Card / Account', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<select name="<?php echo esc_attr( $ac->get_field_name( 'name_field' ) ); ?>">
					<option value=""><?php esc_html_e( '— Select field —', 'payment-gateway-accept-blue-for-formidable' ); ?></option>
					<?php foreach ( $fields as $f ) : ?>
						<option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $g('name_field'), $f->id ); ?>><?php echo esc_html( $f->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Maps to the accept.blue "name" field.', 'payment-gateway-accept-blue-for-formidable' ); ?></p>
			</td>
		</tr>

		<!-- ════ EMAIL ════ -->
		<tr>
			<th><?php esc_html_e( 'Email', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<select name="<?php echo esc_attr( $ac->get_field_name( 'email_field' ) ); ?>">
					<option value=""><?php esc_html_e( '— Select field —', 'payment-gateway-accept-blue-for-formidable' ); ?></option>
					<?php foreach ( $fields as $f ) : ?>
						<option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $g('email_field'), $f->id ); ?>><?php echo esc_html( $f->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Customer email sent to accept.blue with the charge.', 'payment-gateway-accept-blue-for-formidable' ); ?></p>
			</td>
		</tr>

		<!-- ════ CUSTOMER IDENTIFIER ════ -->
		<tr>
			<th><?php esc_html_e( 'Customer Identifier', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<select name="<?php echo esc_attr( $ac->get_field_name( 'customer_identifier_field' ) ); ?>">
					<option value=""><?php esc_html_e( '— Select field —', 'payment-gateway-accept-blue-for-formidable' ); ?></option>
					<?php foreach ( $fields as $f ) : ?>
						<option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $g('customer_identifier_field'), $f->id ); ?>><?php echo esc_html( $f->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Something that identifies the customer, e.g. their name or company. Recommended: map to a "Full Name" or "Company" field.', 'payment-gateway-accept-blue-for-formidable' ); ?></p>
			</td>
		</tr>

		<!-- ════ CUSTOMER NUMBER ════ -->
		<tr>
			<th><?php esc_html_e( 'Customer Number', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<select name="<?php echo esc_attr( $ac->get_field_name( 'customer_number_field' ) ); ?>">
					<option value=""><?php esc_html_e( '— Select field —', 'payment-gateway-accept-blue-for-formidable' ); ?></option>
					<?php foreach ( $fields as $f ) : ?>
						<option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $g('customer_number_field'), $f->id ); ?>><?php echo esc_html( $f->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'A custom identifier for this customer. Recommended: map to an "Account Number", "Member ID", or hidden auto-ID field.', 'payment-gateway-accept-blue-for-formidable' ); ?></p>
			</td>
		</tr>

		<!-- ════ AVS ADDRESS ════ -->
		<tr>
			<th><?php esc_html_e( 'AVS Street Address', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<select name="<?php echo esc_attr( $ac->get_field_name( 'avs_address_field' ) ); ?>">
					<option value=""><?php esc_html_e( '— Select field —', 'payment-gateway-accept-blue-for-formidable' ); ?></option>
					<?php foreach ( $fields as $f ) : ?>
						<option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $g('avs_address_field'), $f->id ); ?>><?php echo esc_html( $f->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Billing street address for Address Verification (AVS). Recommended for fraud prevention.', 'payment-gateway-accept-blue-for-formidable' ); ?></p>
			</td>
		</tr>

		<!-- ════ AVS ZIP ════ -->
		<tr>
			<th><?php esc_html_e( 'AVS ZIP / Postal Code', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<select name="<?php echo esc_attr( $ac->get_field_name( 'avs_zip_field' ) ); ?>">
					<option value=""><?php esc_html_e( '— Select field —', 'payment-gateway-accept-blue-for-formidable' ); ?></option>
					<?php foreach ( $fields as $f ) : ?>
						<option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $g('avs_zip_field'), $f->id ); ?>><?php echo esc_html( $f->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Billing ZIP/postal code for Address Verification (AVS). Strongly recommended for fraud prevention and best interchange rates.', 'payment-gateway-accept-blue-for-formidable' ); ?></p>
			</td>
		</tr>

		<!-- ════ IFRAME STYLE ════ -->
		<tr>
			<th><?php esc_html_e( 'iFrame Style', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<select id="frm_ab_lite_iframe_style_select" name="<?php echo esc_attr( $ac->get_field_name( 'iframe_style' ) ); ?>">
					<option value="default" <?php selected( $g('iframe_style','default'), 'default' ); ?>><?php esc_html_e( 'Default (accept.blue)', 'payment-gateway-accept-blue-for-formidable' ); ?></option>
					<option value="light"   <?php selected( $g('iframe_style','default'), 'light'   ); ?>><?php esc_html_e( 'Light',  'payment-gateway-accept-blue-for-formidable' ); ?></option>
					<option value="dark"    <?php selected( $g('iframe_style','default'), 'dark'    ); ?>><?php esc_html_e( 'Dark',   'payment-gateway-accept-blue-for-formidable' ); ?></option>
					<option value="custom"  <?php selected( $g('iframe_style','default'), 'custom'  ); ?>><?php esc_html_e( 'Custom CSS', 'payment-gateway-accept-blue-for-formidable' ); ?></option>
				</select>

				<div id="frm_ab_lite_custom_style_wrap" style="margin-top:10px;<?php echo $g('iframe_style') === 'custom' ? '' : 'display:none;'; ?>">
					<p class="description" style="margin-bottom:5px;">
						<?php esc_html_e( 'Enter CSS for the card iFrame container. Supported keys: card, input, label, number, expiry, cvv, zip, error.', 'payment-gateway-accept-blue-for-formidable' ); ?>
					</p>
					<textarea
						name="<?php echo esc_attr( $ac->get_field_name( 'iframe_custom_css' ) ); ?>"
						rows="6"
						class="large-text code"
						placeholder="<?php esc_attr_e( 'card: background: #fff; border: 1px solid #ccc; border-radius: 6px; padding: 12px;', 'payment-gateway-accept-blue-for-formidable' ); ?>"
						><?php echo esc_textarea( $g('iframe_custom_css') ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Format: key: css-value; — one rule per line. Example:', 'payment-gateway-accept-blue-for-formidable' ); ?><br>
						<code>card: background:#f9f9f9; border:1px solid #ddd; border-radius:8px;</code><br>
						<code>input: border:1px solid #aaa; border-radius:4px; color:#333;</code>
					</p>
				</div>
			</td>
		</tr>
		<tr><th colspan="2"><strong><?php esc_html_e( 'Billing Information', 'payment-gateway-accept-blue-for-formidable' ); ?></strong></th></tr>
		<?php
		$billing_fields = array(
			'billing_first_name' => __( 'First Name',   'payment-gateway-accept-blue-for-formidable' ),
			'billing_last_name'  => __( 'Last Name',    'payment-gateway-accept-blue-for-formidable' ),
			'billing_street'     => __( 'Street',       'payment-gateway-accept-blue-for-formidable' ),
			'billing_street2'    => __( 'Street 2',     'payment-gateway-accept-blue-for-formidable' ),
			'billing_city'       => __( 'City',         'payment-gateway-accept-blue-for-formidable' ),
			'billing_state'      => __( 'State',        'payment-gateway-accept-blue-for-formidable' ),
			'billing_zip'        => __( 'ZIP / Postal', 'payment-gateway-accept-blue-for-formidable' ),
			'billing_country'    => __( 'Country',      'payment-gateway-accept-blue-for-formidable' ),
			'billing_phone'      => __( 'Phone',        'payment-gateway-accept-blue-for-formidable' ),
		);
		foreach ( $billing_fields as $key => $label ) : ?>
		<tr>
			<th style="padding-left:20px;"><label><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( $ac->get_field_name( $key ) ); ?>">
					<option value=""><?php esc_html_e( '— None —', 'payment-gateway-accept-blue-for-formidable' ); ?></option>
					<?php foreach ( $fields as $f ) : ?>
						<option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $g( $key ), $f->id ); ?>><?php echo esc_html( $f->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php endforeach; ?>

		<!-- ════ LINE ITEMS (LEVEL 3) ════ -->
		<tr>
			<th><?php esc_html_e( 'Line Items (Level 3)', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $ac->get_field_name( 'li_enabled' ) ); ?>" value="0" />
				<label>
					<input type="checkbox" id="frm_ab_lite_li_enabled"
						name="<?php echo esc_attr( $ac->get_field_name( 'li_enabled' ) ); ?>"
						value="1" <?php checked( 1, $g('li_enabled', 0) ); ?>
						onchange="document.getElementById('frm_ab_lite_li_rows').style.display=this.checked?'':'none';" />
					<?php esc_html_e( 'Send Level 3 line item data with the charge', 'payment-gateway-accept-blue-for-formidable' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Level 3 data (SKU, quantity, tax) qualifies for lower interchange rates on B2B/corporate cards.', 'payment-gateway-accept-blue-for-formidable' ); ?></p>
			</td>
		</tr>
		<tbody id="frm_ab_lite_li_rows" <?php echo $g('li_enabled') ? '' : 'style="display:none;"'; ?>>

		<!-- SKU -->
		<tr>
			<th style="padding-left:20px;"><label for="frm_ab_lite_li_sku"><?php esc_html_e( 'SKU', 'payment-gateway-accept-blue-for-formidable' ); ?></label></th>
			<td>
				<input type="text" id="frm_ab_lite_li_sku" class="regular-text"
					name="<?php echo esc_attr( $ac->get_field_name( 'li_sku' ) ); ?>"
					value="<?php echo esc_attr( $g('li_sku') ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. PROD-001 or [field_key]', 'payment-gateway-accept-blue-for-formidable' ); ?>" />
				<p class="description"><?php esc_html_e( 'Product SKU / commodity code. Supports Formidable shortcodes.', 'payment-gateway-accept-blue-for-formidable' ); ?></p>
			</td>
		</tr>

		<!-- Description -->
		<tr>
			<th style="padding-left:20px;"><label for="frm_ab_lite_li_desc"><?php esc_html_e( 'Description', 'payment-gateway-accept-blue-for-formidable' ); ?></label></th>
			<td>
				<input type="text" id="frm_ab_lite_li_desc" class="large-text"
					name="<?php echo esc_attr( $ac->get_field_name( 'li_description' ) ); ?>"
					value="<?php echo esc_attr( $g('li_description') ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. Annual subscription or [product_name]', 'payment-gateway-accept-blue-for-formidable' ); ?>" />
				<p class="description"><?php esc_html_e( 'Line item description. Supports Formidable shortcodes.', 'payment-gateway-accept-blue-for-formidable' ); ?></p>
			</td>
		</tr>

		<!-- Unit Cost -->
		<tr>
			<th style="padding-left:20px;"><?php esc_html_e( 'Unit Cost', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<label><input type="radio" name="<?php echo esc_attr( $ac->get_field_name( 'li_cost_type' ) ); ?>" value="fixed"
					<?php checked( $g('li_cost_type','fixed'), 'fixed' ); ?>
					onchange="document.getElementById('frm_ab_lite_li_cost_fixed_wrap').style.display='';document.getElementById('frm_ab_lite_li_cost_field_wrap').style.display='none';" />
				<?php esc_html_e( 'Fixed:', 'payment-gateway-accept-blue-for-formidable' ); ?></label>
				<span id="frm_ab_lite_li_cost_fixed_wrap" <?php echo $g('li_cost_type','fixed')==='fixed' ? '' : 'style="display:none;"'; ?>>
					<input type="number" step="0.01" min="0" style="width:100px;"
						name="<?php echo esc_attr( $ac->get_field_name( 'li_cost_fixed' ) ); ?>"
						value="<?php echo esc_attr( $g('li_cost_fixed') ); ?>"
						placeholder="<?php esc_attr_e( '0.00', 'payment-gateway-accept-blue-for-formidable' ); ?>" />
				</span>
				<br /><br />
				<label><input type="radio" name="<?php echo esc_attr( $ac->get_field_name( 'li_cost_type' ) ); ?>" value="field"
					<?php checked( $g('li_cost_type','fixed'), 'field' ); ?>
					onchange="document.getElementById('frm_ab_lite_li_cost_field_wrap').style.display='';document.getElementById('frm_ab_lite_li_cost_fixed_wrap').style.display='none';" />
				<?php esc_html_e( 'From field:', 'payment-gateway-accept-blue-for-formidable' ); ?></label>
				<span id="frm_ab_lite_li_cost_field_wrap" <?php echo $g('li_cost_type','fixed')==='field' ? '' : 'style="display:none;"'; ?>>
					<select name="<?php echo esc_attr( $ac->get_field_name( 'li_cost_field' ) ); ?>">
						<option value=""><?php esc_html_e( '— Select field —', 'payment-gateway-accept-blue-for-formidable' ); ?></option>
						<?php foreach ( $fields as $f ) : ?>
							<option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $g('li_cost_field'), $f->id ); ?>><?php echo esc_html( $f->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
				<p class="description"><?php esc_html_e( 'Unit cost per item. If blank, calculated as amount / quantity.', 'payment-gateway-accept-blue-for-formidable' ); ?></p>
			</td>
		</tr>

		<!-- Quantity -->
		<tr>
			<th style="padding-left:20px;"><?php esc_html_e( 'Quantity', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<label><input type="radio" name="<?php echo esc_attr( $ac->get_field_name( 'li_qty_type' ) ); ?>" value="fixed"
					<?php checked( $g('li_qty_type','fixed'), 'fixed' ); ?>
					onchange="document.getElementById('frm_ab_lite_li_qty_fixed_wrap').style.display='';document.getElementById('frm_ab_lite_li_qty_field_wrap').style.display='none';var qi=document.querySelector('#frm_ab_lite_li_qty_fixed_wrap input');if(qi)qi.disabled=false;" />
				<?php esc_html_e( 'Fixed:', 'payment-gateway-accept-blue-for-formidable' ); ?></label>
				<span id="frm_ab_lite_li_qty_fixed_wrap" <?php echo $g('li_qty_type','fixed')==='fixed' ? '' : 'style="display:none;"'; ?>>
					<input type="number" step="1" min="1" style="width:80px;"
						name="<?php echo esc_attr( $ac->get_field_name( 'li_qty_fixed' ) ); ?>"
						value="<?php echo esc_attr( max( 1, intval( $g('li_qty_fixed', 1) ) ) ); ?>"
						placeholder="<?php esc_attr_e( '1', 'payment-gateway-accept-blue-for-formidable' ); ?>"
						<?php echo $g('li_qty_type','fixed') === 'fixed' ? '' : 'disabled'; ?> />
				</span>
				<br /><br />
				<label><input type="radio" name="<?php echo esc_attr( $ac->get_field_name( 'li_qty_type' ) ); ?>" value="field"
					<?php checked( $g('li_qty_type','fixed'), 'field' ); ?>
					onchange="document.getElementById('frm_ab_lite_li_qty_field_wrap').style.display='';document.getElementById('frm_ab_lite_li_qty_fixed_wrap').style.display='none';var qi=document.querySelector('#frm_ab_lite_li_qty_fixed_wrap input');if(qi)qi.disabled=true;" />
				<?php esc_html_e( 'From field:', 'payment-gateway-accept-blue-for-formidable' ); ?></label>
				<span id="frm_ab_lite_li_qty_field_wrap" <?php echo $g('li_qty_type','fixed')==='field' ? '' : 'style="display:none;"'; ?>>
					<select name="<?php echo esc_attr( $ac->get_field_name( 'li_quantity' ) ); ?>">
						<option value=""><?php esc_html_e( '— Select field —', 'payment-gateway-accept-blue-for-formidable' ); ?></option>
						<?php foreach ( $fields as $f ) : ?>
							<option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $g('li_quantity'), $f->id ); ?>><?php echo esc_html( $f->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
				<p class="description"><?php esc_html_e( 'Number of units. Fixed value or mapped to a form field.', 'payment-gateway-accept-blue-for-formidable' ); ?></p>
			</td>
		</tr>

		<!-- Tax Rate -->
		<tr>
			<th style="padding-left:20px;"><label for="frm_ab_lite_li_tax"><?php esc_html_e( 'Tax Rate (%)', 'payment-gateway-accept-blue-for-formidable' ); ?></label></th>
			<td>
				<input type="number" id="frm_ab_lite_li_tax" step="0.001" min="0" max="100" style="width:100px;"
					name="<?php echo esc_attr( $ac->get_field_name( 'li_tax_rate' ) ); ?>"
					value="<?php echo esc_attr( $g('li_tax_rate') ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. 8.5', 'payment-gateway-accept-blue-for-formidable' ); ?>" />
				<p class="description"><?php esc_html_e( 'Tax rate as a percentage (e.g. 8.5 = 8.5%). Leave blank if not applicable.', 'payment-gateway-accept-blue-for-formidable' ); ?></p>
			</td>
		</tr>

		</tbody><!-- /frm_ab_lite_li_rows -->

</tbody>
<tbody>
<tr>
		<td colspan="2" style="padding:10px 14px;background:#f0f6fc;border-left:3px solid #2271b1;">
			<strong><?php esc_html_e( 'Recurring Subscriptions & Installments', 'payment-gateway-accept-blue-for-formidable' ); ?></strong> &mdash; 
			<?php esc_html_e( 'Available in the Pro version. Upgrade to enable recurring billing, subscriptions, and instalment plans.', 'payment-gateway-accept-blue-for-formidable' ); ?>
		</td>
</tr>
</tbody>
<tbody>
		<!-- ════ OTHER OPTIONS ════ -->
		<tr>
			<th><?php esc_html_e( 'Save Card / Account', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $ac->get_field_name( 'save_card' ) ); ?>" value="0" />
				<label>
					<input type="checkbox"
						name="<?php echo esc_attr( $ac->get_field_name( 'save_card' ) ); ?>"
						value="1" <?php checked( 1, $g('save_card', 0) ); ?> />
					<?php esc_html_e( 'Save payment method to accept.blue vault', 'payment-gateway-accept-blue-for-formidable' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Description', 'payment-gateway-accept-blue-for-formidable' ); ?></th>
			<td>
				<input type="text" class="large-text"
					name="<?php echo esc_attr( $ac->get_field_name( 'description' ) ); ?>"
					value="<?php echo esc_attr( $g('description') ); ?>"
					placeholder="<?php esc_attr_e( 'Payment for order #[id]', 'payment-gateway-accept-blue-for-formidable' ); ?>" />
				<p class="description"><?php esc_html_e( 'Supports Formidable shortcodes like [id].', 'payment-gateway-accept-blue-for-formidable' ); ?></p>
			</td>
		</tr>

</tbody>
<tbody>
<tr>
		<td colspan="2" style="padding:10px 14px;background:#f0f6fc;border-left:3px solid #2271b1;">
			<strong><?php esc_html_e( 'Per-Form API Credential Override', 'payment-gateway-accept-blue-for-formidable' ); ?></strong> &mdash; 
			<?php esc_html_e( 'Available in the Pro version. Upgrade to enable per-form accept.blue API key and PIN override.', 'payment-gateway-accept-blue-for-formidable' ); ?>
		</td>
</tr>
</tbody>
<tbody>
		<script>
		(function(){
			function moveNotice() {
				var notice = document.querySelector('.frm-ab-lite-pro-notice');
				if (!notice) return false;

				// Walk up to find the action panel wrapper
				var el = notice;
				var panel = null;
				while (el.parentElement) {
					el = el.parentElement;
					// Formidable wraps each action in a div with class containing "frm_action"
					if (el.className && typeof el.className === 'string' &&
						(el.className.indexOf('frm_action') !== -1 ||
						 el.className.indexOf('frm-action') !== -1 ||
						 el.className.indexOf('postbox') !== -1)) {
						panel = el;
						break;
					}
				}
				if (!panel) panel = notice.parentElement;

				// Find the Action Name label row — insert notice before it
				var labels = panel.querySelectorAll('label, th, .frm_action_name');
				var actionNameRow = null;
				for (var i = 0; i < labels.length; i++) {
					var txt = labels[i].textContent || '';
					if (txt.indexOf('Action Name') !== -1) {
						// Go up to the row-level element (tr, div, or direct child of panel)
						var row = labels[i];
						while (row.parentElement && row.parentElement !== panel) {
							row = row.parentElement;
						}
						actionNameRow = row;
						break;
					}
				}

				if (actionNameRow && actionNameRow !== notice) {
					panel.insertBefore(notice, actionNameRow);
				} else {
					// Fallback: insert as first child of panel
					panel.insertBefore(notice, panel.firstChild);
				}
				return true;
			}

			// Try immediately, then retry with delays in case Formidable renders late
			if (!moveNotice()) {
				var delays = [100, 300, 700, 1500];
				delays.forEach(function(ms){
					setTimeout(moveNotice, ms);
				});
			}
		})();
		</script>

		</tbody>
		</table>
		<?php
	}
}

// ── Register ──────────────────────────────────────────────────────────────────

// =============================================================================
// Pre-submit payment AJAX — runs BEFORE Formidable creates the entry.
// JS intercepts the form submit, calls this endpoint, gets success/fail,
// then either shows the error (never submitting to Formidable) or sets
// paymentPassed=true and lets Formidable submit normally.
// On Formidable submission frm_ab_lite_process_payment detects the pre-auth
// transient and skips re-charging, just records the result.
// =============================================================================

add_action( 'wp_ajax_frm_ab_lite_precheck_payment',        'frm_ab_lite_precheck_payment_handler' );
add_action( 'wp_ajax_nopriv_frm_ab_lite_precheck_payment', 'frm_ab_lite_precheck_payment_handler' );

function frm_ab_lite_precheck_payment_handler() {
	check_ajax_referer( 'frm_ab_lite_precheck_nonce', 'nonce' );

	$nonce        = sanitize_text_field( wp_unslash( $_POST['ab_nonce']   ?? '' ) );
	$amount_raw   = sanitize_text_field( wp_unslash( $_POST['ab_amount']  ?? '0' ) );
	$form_id      = absint( $_POST['form_id']  ?? 0 );
	$action_id    = absint( $_POST['action_id'] ?? 0 );
	$currency     = sanitize_text_field( wp_unslash( $_POST['ab_currency'] ?? 'USD' ) );
	$capture      = ! empty( $_POST['ab_capture'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.NonceVerification.Missing

	$amount = floatval( preg_replace( '/[^0-9.]/', '', $amount_raw ) );

	if ( empty( $nonce ) ) {
		wp_send_json_error( array( 'message' => __( 'No payment token received. Please try again.', 'payment-gateway-accept-blue-for-formidable' ) ) );
	}
	if ( $amount <= 0 ) {
		wp_send_json_error( array( 'message' => __( 'Payment amount must be greater than zero.', 'payment-gateway-accept-blue-for-formidable' ) ) );
	}

	// ── API: use per-action override credentials if configured ───────────
	$api             = Frm_AB_Lite_Settings::get_api(); // default: global credentials
	$action_settings = array();
	$override_source = 'none';

	Frm_AB_Lite_Logger::info( sprintf(
		'[Accept.Blue] Precheck credential lookup | action_id=%d | form_id=%d | FrmFormAction_exists=%s',
		$action_id,
		$form_id,
		class_exists( 'FrmFormAction' ) ? 'yes' : 'no'
	) );

	if ( $action_id ) {

		// Path 1: FrmFormAction::get_action_for_form (returns unserialized post_content)
		if ( class_exists( 'FrmFormAction' ) && $form_id ) {
			$all_actions = (array) FrmFormAction::get_action_for_form( $form_id, 'all' );
			Frm_AB_Lite_Logger::info( sprintf(
				'[Accept.Blue] Precheck: found %d actions for form %d via FrmFormAction',
				count( $all_actions ),
				$form_id
			) );
			foreach ( $all_actions as $fa ) {
				Frm_AB_Lite_Logger::info( sprintf(
					'[Accept.Blue] Precheck: checking action ID=%d post_content_type=%s',
					$fa->ID ?? 0,
					gettype( $fa->post_content ?? null )
				) );
				if ( (int) ( $fa->ID ?? 0 ) === $action_id && is_array( $fa->post_content ) ) {
					$action_settings = $fa->post_content;
					$override_source = 'FrmFormAction';
					Frm_AB_Lite_Logger::info( sprintf(
						'[Accept.Blue] Precheck: matched action %d | settings_keys=%s',
						$action_id,
						implode( ',', array_keys( $action_settings ) )
					) );
					break;
				}
			}
		}

		// Path 2: get_post fallback
		if ( empty( $action_settings ) ) {
			$action_post = get_post( $action_id );
			Frm_AB_Lite_Logger::info( sprintf(
				'[Accept.Blue] Precheck: get_post(%d)=%s | content_type=%s',
				$action_id,
				$action_post ? 'found' : 'NOT FOUND',
				$action_post ? gettype( $action_post->post_content ) : 'n/a'
			) );
			if ( $action_post ) {
				$raw = maybe_unserialize( $action_post->post_content );
				Frm_AB_Lite_Logger::info( '[Accept.Blue] Precheck: maybe_unserialize type=' . gettype( $raw ) );
				if ( is_array( $raw ) ) {
					$action_settings = $raw;
					$override_source = 'get_post';
					Frm_AB_Lite_Logger::info( '[Accept.Blue] Precheck: fallback keys=' . implode( ',', array_keys( $action_settings ) ) );
				}
			}
		}

		// Apply override
		if ( ! empty( $action_settings ) ) {
			$override_key = trim( $action_settings['override_api_key'] ?? '' );
			$override_pin = trim( $action_settings['override_pin']     ?? '' );
			Frm_AB_Lite_Logger::info( sprintf(
				'[Accept.Blue] Precheck: override_api_key=%s (len=%d) | source=%s',
				$override_key ? 'SET' : 'EMPTY',
				strlen( $override_key ),
				$override_source
			) );
			if ( $override_key ) {
				$global = Frm_AB_Lite_Settings::get_settings();
				$api    = new Frm_AB_Lite_API( $override_key, $override_pin, (bool) $global['test_mode'] );
				Frm_AB_Lite_Logger::info( '[Accept.Blue] Precheck: ✅ OVERRIDE API in use (key prefix=' . substr( $override_key, 0, 8 ) . '...)' );
			} else {
				Frm_AB_Lite_Logger::info( '[Accept.Blue] Precheck: ℹ️ No override key — using GLOBAL API credentials.' );
			}
		} else {
			Frm_AB_Lite_Logger::info( '[Accept.Blue] Precheck: ⚠️ Action settings empty — using GLOBAL API credentials.' );
		}

	} else {
		Frm_AB_Lite_Logger::info( '[Accept.Blue] Precheck: No action_id in POST — using GLOBAL API credentials.' );
	}

	if ( ! $api ) {
		wp_send_json_error( array( 'message' => __( 'Payment gateway not configured. Contact site admin.', 'payment-gateway-accept-blue-for-formidable' ) ) );
	}

	$uid = get_current_user_id() ?: ( session_id() ?: uniqid( 'frm_ab_lite_', true ) );
	$key = 'frm_ab_lite_preauth_' . md5( $nonce . $uid );

	// ── Recurring: vault the card instead of charging it ─────────────────────
	// For recurring/installment plans the nonce must NOT be used for a direct
	// charge — it is a single-use token and must be consumed by the vault
	// (customer + payment-method) so that frm_ab_lite_process_payment can create
	// the recurring schedule without re-using the nonce.
	$recurring_enabled = false; // Lite: recurring is a Pro-only feature.

	if ( $recurring_enabled ) {

		Frm_AB_Lite_Logger::info( sprintf(
			'[Recurring] Precheck: vaulting card for recurring setup | Form: %d | Amount: $%s',
			$form_id, number_format( $amount, 2 )
		) );

		// Step 1 — create a customer record.
		// Billing details aren't available yet (entry not created), so we pass a
		// temporary customer_number. frm_ab_lite_setup_recurring_then_charge skips this
		// step entirely when pre-vaulted IDs are supplied, so the customer is only
		// ever created once per submission.
		$customer = $api->create_customer( [
			'customer_number' => 'precheck-f' . $form_id . '-' . substr( md5( $nonce ), 0, 8 ),
		] );
		if ( is_wp_error( $customer ) ) {
			Frm_AB_Lite_Logger::error( '[Recurring] Precheck Step 1 FAILED: ' . $customer->get_error_message() );
			wp_send_json_error( array( 'message' => $customer->get_error_message() ) );
		}
		$customer_id = intval( $customer['id'] ?? 0 );
		if ( ! $customer_id ) {
			Frm_AB_Lite_Logger::error( '[Recurring] Precheck Step 1: create_customer returned no ID.' );
			wp_send_json_error( array( 'message' => __( 'Could not create vault customer. Please try again.', 'payment-gateway-accept-blue-for-formidable' ) ) );
		}
		Frm_AB_Lite_Logger::info( '[Recurring] Precheck Step 1 ✓ customer_id=' . $customer_id );

		// Step 2 — attach nonce to customer vault → get payment_method_id
		$clean_nonce = ( 0 === strpos( $nonce, 'nonce-' ) ) ? substr( $nonce, 6 ) : $nonce;
		$pm_result   = $api->add_payment_method( $customer_id, $clean_nonce );
		if ( is_wp_error( $pm_result ) ) {
			Frm_AB_Lite_Logger::error( '[Recurring] Precheck Step 2 FAILED: ' . $pm_result->get_error_message() );
			wp_send_json_error( array( 'message' => $pm_result->get_error_message() ) );
		}
		$payment_method_id = intval( $pm_result['id'] ?? 0 );
		if ( ! $payment_method_id ) {
			Frm_AB_Lite_Logger::error( '[Recurring] Precheck Step 2: add_payment_method returned no ID.' );
			wp_send_json_error( array( 'message' => __( 'Could not vault payment method. Please try again.', 'payment-gateway-accept-blue-for-formidable' ) ) );
		}
		Frm_AB_Lite_Logger::info( '[Recurring] Precheck Step 2 ✓ payment_method_id=' . $payment_method_id );

		// Store vault IDs in transient — frm_ab_lite_process_payment reads these to
		// skip re-vaulting and go straight to schedule creation.
		set_transient( $key, array(
			'recurring_precheck' => true,
			'customer_id'        => $customer_id,
			'payment_method_id'  => $payment_method_id,
			'amount'             => $amount,
			'form_id'            => $form_id,
			'action_id'          => $action_id,
			'vaulted_at'         => time(),
		), 300 ); // 5-minute TTL — enough for form submission round-trip

		Frm_AB_Lite_Logger::info( sprintf(
			'[Recurring] Precheck ✓ vault ready | customer_id=%d | pm_id=%d | transient_key=%s',
			$customer_id, $payment_method_id, $key
		) );

		wp_send_json_success( array(
			'message'   => 'Card vaulted.',
			'trans_key' => $key,
		) );
	}

	// ── Non-recurring: standard pre-auth charge ───────────────────────────────
	$charge_args = array(
		'source'   => $nonce,
		'amount'   => $amount,
		'currency' => $currency,
		'capture'  => $capture,
		'transaction_details' => array( 'description' => 'Pre-auth via form #' . $form_id ),
	);

	Frm_AB_Lite_Logger::info( sprintf( '[Accept.Blue] Pre-submit charge | Form: %d | Amount: $%s', $form_id, number_format( $amount, 2 ) ) );

	$result = $api->create_charge( $charge_args );

	if ( is_wp_error( $result ) ) {
		$msg = $result->get_error_message();
		Frm_AB_Lite_Logger::error( 'Pre-submit charge FAILED (WP_Error): ' . $msg );
		wp_send_json_error( array( 'message' => $msg ) );
	}

	$api_status      = strtolower( $result['status']      ?? '' );
	$api_status_code = strtoupper( $result['status_code'] ?? '' );
	$api_is_error    = in_array( $api_status, array( 'error', 'declined' ), true )
	                || in_array( $api_status_code, array( 'E', 'D' ), true );

	if ( $api_is_error ) {
		$msg = $result['error_message'] ?? $result['message'] ?? sprintf( 'Payment %s (code: %s)', ucfirst( $api_status ), $api_status_code );
		Frm_AB_Lite_Logger::error( 'Pre-submit charge FAILED (API): ' . $msg );
		wp_send_json_error( array( 'message' => $msg ) );
	}

	$ref_num = $result['reference_number'] ?? $result['id'] ?? '';
	$result['_capture_requested'] = $capture;
	$mapped_status = frm_ab_lite_map_status( $result );

	Frm_AB_Lite_Logger::info( sprintf(
		'[Accept.Blue] Pre-auth map_status debug | top=%s | detail_toplevel=%s | detail_nested=%s | code=%s | capture=%s | mapped=%s',
		$result['status']                                            ?? 'n/a',
		$result['status_details']['status']                          ?? 'NULL',
		$result['transaction']['status_details']['status']           ?? 'NULL',
		$result['status_code']                                       ?? 'n/a',
		$capture ? 'true' : 'false',
		$mapped_status
	) );

	set_transient( $key, array(
		'reference_number' => $ref_num,
		'status'           => $mapped_status,
		'amount'           => $amount,
		'result'           => $result,
		'form_id'          => $form_id,
		'action_id'        => $action_id,
		'charged_at'       => time(),
	), 300 );

	Frm_AB_Lite_Logger::info( sprintf( '[Accept.Blue] Pre-submit charge SUCCESS | Ref: %s | Transient key: %s', $ref_num, $key ) );

	wp_send_json_success( array(
		'message'   => 'Payment authorised.',
		'trans_key' => $key,
		'ref'       => $ref_num,
	) );
}

add_filter( 'frm_registered_form_actions', function ( $actions ) {
	$actions[ Frm_AB_Lite_Form_Action::ACTION_SLUG ] = 'Frm_AB_Lite_Form_Action';
	return $actions;
} );



/**
 * Formidable hook that fires before saving any action's post_content to the DB.
 * Ensures our settings are preserved even if prepare() isn't called.
 */
add_filter( 'frm_action_options_before_save', function( $options, $action ) {
	if ( ( $action->post_excerpt ?? '' ) !== Frm_AB_Lite_Form_Action::ACTION_SLUG ) {
		return $options;
	}
	$instance = new Frm_AB_Lite_Form_Action();
	return $instance->prepare( is_array( $options ) ? $options : array() );
}, 10, 2 );

// ── Payment processing ────────────────────────────────────────────────────────
add_action(
	'frm_trigger_' . Frm_AB_Lite_Form_Action::ACTION_SLUG . '_action',
	'frm_ab_lite_process_payment',
	10, 4
);

function frm_ab_lite_process_payment( $action, $entry, $form, $event ) {
	if ( ! in_array( $event, array( 'create', 'update' ), true ) ) return;

	// Guard: prevent double-fire (Formidable can call this twice per submission
	// when both create+update events are enabled, or on JS re-submit cycle)
	static $fired = array();
	$fire_key = ( $entry->id ?? 0 ) . '_' . ( $action->ID ?? 0 );
	if ( isset( $fired[ $fire_key ] ) ) {
		Frm_AB_Lite_Logger::info( 'Duplicate trigger skipped for entry/action: ' . $fire_key );
		return;
	}
	$fired[ $fire_key ] = true;

	$s = is_array( $action->post_content ) ? $action->post_content : array();

	Frm_AB_Lite_Logger::info( sprintf( '[Accept.Blue] frm_ab_lite_process_payment fired | Event: %s | Entry: %d | Form: %d | Action: %d',
		$event,
		$entry->id ?? 0,
		$form->id  ?? 0,
		$action->ID ?? 0
	) );

	// Helper: get meta value by field_id from entry
	$meta = function( $field_id ) use ( $entry ) {
		if ( ! $field_id ) return '';
		$val = isset( $entry->metas[ $field_id ] ) ? $entry->metas[ $field_id ] : '';
		if ( empty( $val ) && isset( $_POST['item_meta'][ $field_id ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$val = isset( $_POST['item_meta'][ $field_id ] ) ? sanitize_text_field( wp_unslash( $_POST['item_meta'][ $field_id ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		return is_array( $val ) ? implode( ', ', $val ) : sanitize_text_field( $val );
	};

	// ── Precheck transient lookup (BEFORE nonce guard) ─────────────────────────
	// The JS precheck may have already vaulted the card (recurring) or charged it
	// (one-off). Either way the result is stored in a transient keyed by trans_key.
	// We must resolve this BEFORE the nonce guard so a recurring-vault precheck —
	// which has no reference_number — isn't mistaken for a missing nonce.
	$uid       = get_current_user_id() ?: session_id();
	$trans_key = sanitize_text_field( wp_unslash( $_POST['frm_ab_lite_trans_key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$preauth   = $trans_key ? get_transient( $trans_key ) : false;

	if ( is_array( $preauth ) ) {

		// ── Recurring-vault precheck: card vaulted, now create the schedule ──────
		if ( ! empty( $preauth['recurring_precheck'] ) ) {
			Frm_AB_Lite_Logger::info( sprintf(
				'[Recurring] Precheck vault found | customer_id=%d | pm_id=%d | Proceeding to schedule creation.',
				$preauth['customer_id']       ?? 0,
				$preauth['payment_method_id'] ?? 0
			) );
			delete_transient( $trans_key );

			// We still need amount, api, billing, etc. — resolve them now before
			// calling the recurring helper.
			$amount_s = ! empty( $s['amount_type'] ) ? $s['amount_type'] : 'fixed';
			$amount   = $amount_s === 'fixed'
				? floatval( $s['amount_fixed'] ?? 0 )
				: floatval( preg_replace( '/[^0-9.]/', '', $meta( $s['amount_field'] ?? '' ) ) );
			if ( $amount <= 0 ) {
				Frm_AB_Lite_Logger::error( '[Recurring] Precheck vault: amount <= 0 — aborting.' );
				frm_ab_lite_add_error( __( 'accept.blue: Amount must be greater than zero.', 'payment-gateway-accept-blue-for-formidable' ) );
				return;
			}

			$override_key = trim( $s['override_api_key'] ?? '' );
			$override_pin = trim( $s['override_pin']     ?? '' );
			if ( $override_key ) {
				$global = Frm_AB_Lite_Settings::get_settings();
				$api    = new Frm_AB_Lite_API( $override_key, $override_pin, (bool) $global['test_mode'] );
			} else {
				$api = Frm_AB_Lite_Settings::get_api();
			}
			if ( ! $api ) {
				Frm_AB_Lite_Logger::error( '[Recurring] Precheck vault: API not configured.' );
				frm_ab_lite_add_error( __( 'Payment gateway not configured. Contact site admin.', 'payment-gateway-accept-blue-for-formidable' ) );
				return;
			}

			$recurring_result = frm_ab_lite_setup_recurring_then_charge(
				$api, '', $amount, $s, [], [],
				$entry,
				(int) $preauth['customer_id'],
				(int) $preauth['payment_method_id']
			);

			if ( is_wp_error( $recurring_result ) ) {
				$error_msg = $recurring_result->get_error_message();
				Frm_AB_Lite_Logger::error( '[Recurring] ABORTED after precheck vault — ' . $error_msg );
				frm_ab_lite_record_payment( $entry, $action, [ 'id' => '', 'status' => 'failed', 'error' => $error_msg ], $amount );
				global $frm_ab_lite_payment_error;
				$frm_ab_lite_payment_error = $error_msg;
				$uid2 = get_current_user_id() ?: session_id();
				if ( $uid2 ) set_transient( 'frm_ab_lite_payment_error_' . $uid2, $error_msg, 60 );
				if ( ! session_id() && ! headers_sent() ) { session_start(); }
				$_SESSION['frm_ab_lite_error'] = $error_msg;
				// translators: %s is the payment error message.
				// translators: %s is the payment error message.
				frm_ab_lite_add_error( sprintf( __( 'Payment failed: %s', 'payment-gateway-accept-blue-for-formidable' ), $error_msg ) );
				do_action( 'frm_ab_lite_payment_failed', $error_msg, $entry );
				return;
			}

			$is_install = $recurring_result['installment_count'] > 0;
			frm_ab_lite_record_payment( $entry, $action, [
				'id'                 => '',
				'reference_number'   => '',
				'status'             => 'pending',
				'_resolved_status'   => 'pending',
				'schedule_id'        => $recurring_result['schedule_id'],
				'next_run_date'      => $recurring_result['next_run_date'],
				'schedule_type'      => $recurring_result['schedule_type'],
				'installment_count'  => $recurring_result['installment_count'],
				'per_payment_amount' => $recurring_result['per_payment_amount'],
				'_capture_requested' => true,
			], $is_install ? $recurring_result['per_payment_amount'] : $amount );
			do_action( 'frm_ab_lite_payment_complete', [], $entry, $form, $action );
			return;
		}

		// ── One-off charge precheck: reference_number already captured ────────
		if ( ! empty( $preauth['reference_number'] ) ) {
			Frm_AB_Lite_Logger::info( sprintf(
				'[Accept.Blue] Pre-auth result found | Ref: %s | Stored status: %s | Skipping re-charge.',
				$preauth['reference_number'],
				$preauth['status'] ?? 'unknown'
			) );
			delete_transient( $trans_key );
			$stored_status = $preauth['status'] ?? 'failed';
			$full_result   = $preauth['result'] ?? [];
			$full_result['_resolved_status'] = $stored_status;
			$full_result['reference_number'] = $full_result['reference_number'] ?? $preauth['reference_number'];
			frm_ab_lite_record_payment( $entry, $action, $full_result, $preauth['amount'] ?? 0 );
			return; // done — entry created, payment recorded, confirmations will fire normally
		}
	}

	// ── 1. Nonce (only needed when there is no precheck transient) ───────────────
	$payment_field_id = isset( $s['payment_field'] ) ? $s['payment_field'] : '';
	$nonce = $meta( $payment_field_id );

	Frm_AB_Lite_Logger::info( sprintf( '[Accept.Blue] Nonce field ID: %s | Nonce received: %s',
		$payment_field_id ?: '(not set)',
		$nonce ? substr( $nonce, 0, 16 ) . '...' : 'EMPTY'
	) );

	if ( empty( $nonce ) ) {
		Frm_AB_Lite_Logger::error( 'Charge FAILED: No nonce — aborting payment.' );
		frm_ab_lite_add_error( __( 'accept.blue: No payment token received. Please try again.', 'payment-gateway-accept-blue-for-formidable' ) );
		return;
	}

	// ── 2. Amount ─────────────────────────────────────────────────────────────
	$amount = 0.0;
	if ( ( isset( $s['amount_type'] ) ? $s['amount_type'] : 'fixed' ) === 'fixed' ) {
		$amount = floatval( isset( $s['amount_fixed'] ) ? $s['amount_fixed'] : 0 );
	} else {
		$amount = floatval( preg_replace( '/[^0-9.]/', '', $meta( isset( $s['amount_field'] ) ? $s['amount_field'] : '' ) ) );
	}

	// Surcharge is handled by accept.blue on their end based on card BIN — send base amount only.
	Frm_AB_Lite_Logger::info( sprintf( '[Accept.Blue] Amount: $%s | Currency: %s | Type: %s',
		number_format( $amount, 2 ),
		isset( $s['currency'] ) ? $s['currency'] : 'USD',
		isset( $s['amount_type'] ) ? $s['amount_type'] : 'fixed'
	) );

	if ( $amount <= 0 ) {
		Frm_AB_Lite_Logger::error( 'Charge FAILED: Amount <= 0 — aborting.' );
		frm_ab_lite_add_error( __( 'accept.blue: Amount must be greater than zero.', 'payment-gateway-accept-blue-for-formidable' ) );
		return;
	}

	// ── 3. API ───────────────────────────────────────────────────────────────
	$override_key = trim( $s['override_api_key'] ?? '' );
	$override_pin = trim( $s['override_pin']     ?? '' );
	Frm_AB_Lite_Logger::info( sprintf(
		'[Accept.Blue] process_payment credential check | override_api_key=%s (len=%d) | action_id=%d',
		$override_key ? 'SET' : 'EMPTY',
		strlen( $override_key ),
		$action->ID ?? 0
	) );
	if ( $override_key ) {
		$global = Frm_AB_Lite_Settings::get_settings();
		$api    = new Frm_AB_Lite_API( $override_key, $override_pin, (bool) $global['test_mode'] );
		Frm_AB_Lite_Logger::info( '[Accept.Blue] ✅ process_payment: OVERRIDE API in use (key prefix=' . substr( $override_key, 0, 8 ) . '...)' );
	} else {
		$api = Frm_AB_Lite_Settings::get_api();
		Frm_AB_Lite_Logger::info( '[Accept.Blue] ℹ️ process_payment: using GLOBAL API credentials.' );
	}
	if ( ! $api ) {
		frm_ab_lite_add_error( __( 'accept.blue: Gateway not configured. Contact site admin.', 'payment-gateway-accept-blue-for-formidable' ) );
		return;
	}

	// ── 4. Description ───────────────────────────────────────────────────────
	$raw_desc = isset( $s['description'] ) && $s['description'] !== '' ? $s['description'] : get_bloginfo('name') . ' Payment';
	$description = class_exists( 'FrmProFieldsHelper' )
		? FrmProFieldsHelper::get_default_value( $raw_desc, $entry, $form )
		: $raw_desc;

	// ── 5. Build charge args ─────────────────────────────────────────────────
	$charge_args = array(
		'source'   => $nonce,
		'amount'   => $amount,
		'currency' => isset( $s['currency'] ) ? $s['currency'] : 'USD',
	);

	// Name on card/account — Formidable name fields may return "First, Last" (comma-separated parts)
	$name_val = $meta( isset( $s['name_field'] ) ? $s['name_field'] : '' );
	if ( $name_val ) {
		// Clean up Formidable multi-part name fields: remove leading/trailing commas and extra spaces
		$name_val = trim( preg_replace( '/\s*,\s*/', ' ', $name_val ) );
		$name_val = trim( preg_replace( '/\s+/', ' ', $name_val ) );
	}
	if ( $name_val ) {
		$charge_args['name'] = sanitize_text_field( $name_val );
	}

	// Email
	$email_val = $meta( isset( $s['email_field'] ) ? $s['email_field'] : '' );
	if ( $email_val ) {
		$charge_args['customer']['email'] = sanitize_email( $email_val );
	}

	// Customer identifier (e.g. Full Name / Company)
	$cust_identifier = $meta( isset( $s['customer_identifier_field'] ) ? $s['customer_identifier_field'] : '' );
	if ( $cust_identifier ) {
		$charge_args['customer']['identifier'] = sanitize_text_field( $cust_identifier );
	}

	// Customer number (custom ID / account number)
	$cust_number = $meta( isset( $s['customer_number_field'] ) ? $s['customer_number_field'] : '' );
	if ( $cust_number ) {
		$charge_args['customer']['customer_number'] = sanitize_text_field( $cust_number );
	}

	// AVS Address (dedicated field, takes precedence over billing_street)
	$avs_address_val = $meta( isset( $s['avs_address_field'] ) ? $s['avs_address_field'] : '' );
	if ( $avs_address_val ) {
		$charge_args['avs_address'] = sanitize_text_field( $avs_address_val );
	}

	// AVS Zip (dedicated field, takes precedence over billing_zip)
	$avs_zip_field_val = $meta( isset( $s['avs_zip_field'] ) ? $s['avs_zip_field'] : '' );
	if ( $avs_zip_field_val ) {
		$charge_args['avs_zip'] = sanitize_text_field( $avs_zip_field_val );
	}

	// Save card
	if ( ! empty( $s['save_card'] ) ) {
		$charge_args['save_card'] = true;
	}

	// Capture — default true; false = auth-only
	// The accept.blue API accepts `capture: false` to auth-only
	$capture = isset( $s['capture'] ) ? (bool) $s['capture'] : true;
	$charge_args['capture'] = $capture;
	Frm_AB_Lite_Logger::info( 'Capture: ' . ( $capture ? 'true (immediate)' : 'false (auth-only)' ) );

	// Description
	if ( $description ) {
		$charge_args['transaction_details'] = array( 'description' => $description );
	}

	// Billing info
	$billing = array();
	$billing_map = array(
		'first_name' => 'billing_first_name',
		'last_name'  => 'billing_last_name',
		'street'     => 'billing_street',
		'street2'    => 'billing_street2',
		'city'       => 'billing_city',
		'state'      => 'billing_state',
		'zip'        => 'billing_zip',
		'country'    => 'billing_country',
		'phone'      => 'billing_phone',
	);
	foreach ( $billing_map as $ab_key => $setting_key ) {
		$val = $meta( isset( $s[ $setting_key ] ) ? $s[ $setting_key ] : '' );
		if ( $val ) $billing[ $ab_key ] = $val;
	}
	// Also pull avs_address and avs_zip to top-level (accept.blue expects these at root)
	// Supplement with any billing fields auto-detected from known field keys via Field_Mapper.
	if ( class_exists( 'Frm_AB_Lite_Field_Mapper' ) ) {
		$mapped = Frm_AB_Lite_Field_Mapper::get_billing_from_entry( $entry, $form );
		foreach ( $mapped as $k => $v ) {
			if ( ! isset( $billing[ $k ] ) && $v ) {
				$billing[ $k ] = $v;
			}
		}
	}
	if ( isset( $billing['street'] ) ) {
		$charge_args['avs_address'] = $billing['street'];
	}
	if ( isset( $billing['zip'] ) ) {
		$charge_args['avs_zip'] = $billing['zip'];
	}
	if ( ! empty( $billing ) ) {
		$charge_args['billing_info'] = $billing;
	}

	// ── Level 3 Line Items ───────────────────────────────────────────────────
	// Only include if explicitly enabled in form action settings
	if ( ! empty( $s['li_enabled'] ) ) {
		$li = array();

		// SKU / commodity code
		// commodity_code requires min 8 chars per accept.blue API; default '00000000'
		$li_sku = isset( $s['li_sku'] ) ? trim( $s['li_sku'] ) : '';
		if ( $li_sku ) {
			$li_sku = class_exists( 'FrmProFieldsHelper' )
				? FrmProFieldsHelper::get_default_value( $li_sku, $entry, $form )
				: $li_sku;
			$li_sku = sanitize_text_field( $li_sku );
		}
		// Pad or default commodity_code to meet 8-char minimum
		if ( strlen( $li_sku ) >= 8 ) {
			$li['commodity_code'] = $li_sku;
		} elseif ( strlen( $li_sku ) > 0 ) {
			$li['commodity_code'] = str_pad( $li_sku, 8, '0', STR_PAD_RIGHT );
		} else {
			$li['commodity_code'] = '00000000';
		}
		$li['sku']              = $li_sku ?: '00000000';
		$li['unit_of_measure']  = 'EA'; // Each — default unit of measure

		// Description
		$li_desc = isset( $s['li_description'] ) ? trim( $s['li_description'] ) : '';
		if ( $li_desc ) {
			$li_desc = class_exists( 'FrmProFieldsHelper' )
				? FrmProFieldsHelper::get_default_value( $li_desc, $entry, $form )
				: $li_desc;
			$li['description'] = sanitize_text_field( $li_desc );
		}

		// Quantity: fixed value or mapped field
		$qty = 1;
		$li_qty_type = isset( $s['li_qty_type'] ) ? $s['li_qty_type'] : 'fixed';
		if ( 'field' === $li_qty_type ) {
			$qty_field_id = isset( $s['li_quantity'] ) ? $s['li_quantity'] : '';
			$qty_val      = $qty_field_id ? $meta( $qty_field_id ) : '';
			if ( '' !== $qty_val && floatval( $qty_val ) > 0 ) {
				$qty = floatval( $qty_val );
			}
		} else {
			$qty_fixed = isset( $s['li_qty_fixed'] ) ? floatval( $s['li_qty_fixed'] ) : 1;
			$qty = max( 1, $qty_fixed );
		}
		$li['quantity'] = $qty;

		// Unit cost: fixed, from field, or calculated as amount / quantity
		$li_cost_type = isset( $s['li_cost_type'] ) ? $s['li_cost_type'] : 'fixed';
		if ( 'field' === $li_cost_type ) {
			$cost_field_id = isset( $s['li_cost_field'] ) ? $s['li_cost_field'] : '';
			$cost_val      = $cost_field_id ? floatval( preg_replace( '/[^0-9.]/', '', $meta( $cost_field_id ) ) ) : 0;
			$li['unit_cost'] = $cost_val > 0 ? round( $cost_val, 4 ) : round( $amount / $qty, 4 );
		} elseif ( 'fixed' === $li_cost_type && ! empty( $s['li_cost_fixed'] ) ) {
			$li['unit_cost'] = round( floatval( $s['li_cost_fixed'] ), 4 );
		} else {
			// Auto-calculate: amount / quantity
			$li['unit_cost'] = round( $amount / $qty, 4 );
		}

		// Tax rate
		$li_tax = isset( $s['li_tax_rate'] ) ? trim( $s['li_tax_rate'] ) : '';
		if ( '' !== $li_tax && floatval( $li_tax ) > 0 ) {
			$li['tax_rate']   = floatval( $li_tax );
			$li['tax_amount'] = round( $li['unit_cost'] * $qty * floatval( $li_tax ) / 100, 2 );
		}

		// Total amount for this line item
		$li['total_amount'] = round( $li['unit_cost'] * $qty, 2 );

		$charge_args['line_items'] = array( $li );
	}

	/**
	 * @param array  $charge_args
	 * @param object $entry
	 * @param object $form
	 * @param object $action
	 */
	// ── 3DS: browser data + verify3DS result ─────────────────────────────────
	// verify3DS result is the primary 3DS payload (Paay).
	// browser_info is the fallback / supplemental data for native 3DS.
	$three_ds_raw    = sanitize_text_field( wp_unslash( $_POST['frm_ab_lite_three_ds_data']   ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$three_ds_result = sanitize_text_field( wp_unslash( $_POST['frm_ab_lite_three_ds_result'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

	// If we have a verify3DS result (Paay), attach it directly
	if ( ! empty( $s['three_ds_enabled'] ) && $three_ds_result ) {
		$verify_result = json_decode( $three_ds_result, true );
		if ( is_array( $verify_result ) && ! empty( $verify_result ) ) {
			$charge_args['three_ds'] = array_filter( [
				'eci'          => $verify_result['eci']          ?? null,
				'cavv'         => $verify_result['cavv']         ?? null,
				'ds_trans_id'  => $verify_result['ds_trans_id']  ?? null,
				'status'       => $verify_result['status']       ?? null,
				'version'      => $verify_result['version']      ?? null,
			], function( $v ) { return $v !== null; } );
			Frm_AB_Lite_Logger::info( '[3DS] verify3DS result attached to charge', [
				'status'     => $verify_result['status']     ?? '?',
				'eci'        => $verify_result['eci']        ?? '?',
				'ds_trans_id'=> $verify_result['ds_trans_id'] ?? '?',
			] );
		}
	}

	if ( ! empty( $s['three_ds_enabled'] ) && $three_ds_raw ) {
		$three_ds = json_decode( $three_ds_raw, true );
		if ( is_array( $three_ds ) && ! empty( $three_ds ) ) {
			$three_ds_payload = array_filter( [
				'browser_info' => array_filter( [
					'java_enabled'       => isset( $three_ds['java_enabled'] )      ? (bool) $three_ds['java_enabled']               : null,
					'javascript_enabled' => true,
					'language'           => isset( $three_ds['language'] )          ? sanitize_text_field( $three_ds['language'] )   : null,
					'color_depth'        => isset( $three_ds['color_depth'] )       ? intval( $three_ds['color_depth'] )             : null,
					'screen_height'      => isset( $three_ds['screen_height'] )     ? intval( $three_ds['screen_height'] )           : null,
					'screen_width'       => isset( $three_ds['screen_width'] )      ? intval( $three_ds['screen_width'] )            : null,
					'timezone_offset'    => isset( $three_ds['timezone_offset'] )   ? intval( $three_ds['timezone_offset'] )         : null,
					'user_agent'         => isset( $three_ds['user_agent'] )        ? sanitize_text_field( $three_ds['user_agent'] ) : null,
					'accept_header'      => 'application/json',
				], function( $v ) { return $v !== null; } ),
			] );

			// Frictionless: request silent authentication (no customer challenge)
			if ( ! empty( $s['three_ds_frictionless'] ) ) {
				$three_ds_payload['preference'] = 'no_challenge';
				Frm_AB_Lite_Logger::info( '[3DS] Frictionless mode requested (preference: no_challenge).' );
			} else {
				Frm_AB_Lite_Logger::info( '[3DS] Standard mode — challenge allowed if issuer requires it.' );
			}

			// Paay API key is passed in the JS constructor (threeDS.apiKey option),
			// not in the charge payload. The verify3DS result (ECI/CAVV) is what
			// gets attached to the charge — that arrives via frm_ab_lite_three_ds_result.
			$global_settings = Frm_AB_Lite_Settings::get_settings();
			$paay_api_key    = trim( $global_settings['paay_api_key'] ?? '' );
			Frm_AB_Lite_Logger::info( '[3DS] Browser info payload built', [
				'paay_configured' => $paay_api_key ? 'yes' : 'no (native 3DS only)',
			] );

			$charge_args['three_ds'] = $three_ds_payload;
			Frm_AB_Lite_Logger::info( '[3DS] Browser data attached to charge', [
				'screen'   => ( $three_ds['screen_width']  ?? '?' ) . 'x' . ( $three_ds['screen_height'] ?? '?' ),
				'language' => $three_ds['language']         ?? '?',
				'tz'       => $three_ds['timezone_offset']  ?? '?',
				'paay'     => $paay_api_key ? 'yes' : 'no',
			] );
		}
	}

	$charge_args = apply_filters( 'frm_ab_lite_charge_args', $charge_args, $entry, $form, $action );

	// Abort if fraud shield blocked the transaction
	if ( apply_filters( 'frm_ab_lite_abort_payment', false ) ) {
		Frm_AB_Lite_Logger::error( 'Charge FAILED: Payment aborted by fraud shield.' );
		return;
	}

	Frm_AB_Lite_Logger::request( 'POST transactions/charge', array_merge( $charge_args, [ 'source' => substr( $charge_args['source'], 0, 15 ) . '[redacted]' ] ) );

	// ── 6. Recurring path: setup FIRST, no separate charge ─────────────────
	//
	// Steps:
	//   Step 1 – Create customer in accept.blue vault
	//   Step 2 – Attach payment method from nonce → get payment_method_id
	//   Step 3 – Create recurring schedule (next_run_date = today or today+trial_days)
	//
	// No Step 4 charge. The schedule handles ALL payments including the first one.
	// accept.blue fires the first charge automatically on next_run_date.
	if ( false ) { // Lite: recurring disabled — Pro-only feature.
		Frm_AB_Lite_Logger::info( sprintf(
			'[Recurring] Mode active for entry #%d — running setup before charge.',
			$entry->id ?? 0
		) );

		$recurring_result = frm_ab_lite_setup_recurring_then_charge( $api, $nonce, $amount, $s, $billing, $charge_args, $entry );

		if ( is_wp_error( $recurring_result ) ) {
			$error_msg = $recurring_result->get_error_message();
			Frm_AB_Lite_Logger::error( '[Recurring] ABORTED — ' . $error_msg );
			frm_ab_lite_record_payment( $entry, $action, [ 'id' => '', 'status' => 'failed', 'error' => $error_msg ], $amount );
			global $frm_ab_lite_payment_error;
			$frm_ab_lite_payment_error = $error_msg;
			$uid = get_current_user_id() ?: session_id();
			if ( $uid ) set_transient( 'frm_ab_lite_payment_error_' . $uid, $error_msg, 60 );
			if ( ! session_id() && ! headers_sent() ) { session_start(); }
			$_SESSION['frm_ab_lite_error'] = $error_msg;
			// translators: %s is the payment error message.
			// translators: %s is the payment error message.
			frm_ab_lite_add_error( sprintf( __( 'Payment failed: %s', 'payment-gateway-accept-blue-for-formidable' ), $error_msg ) );
			do_action( 'frm_ab_lite_payment_failed', $error_msg, $entry );
			return;
		}

		// Schedule created successfully — no charge result (accept.blue fires first charge via schedule).
		// Record the subscription/installment as 'pending'; webhook updates it to 'complete' on first charge.
		$is_install = $recurring_result['installment_count'] > 0;
		frm_ab_lite_record_payment( $entry, $action, [
			'id'                 => '',
			'reference_number'   => '',
			'status'             => 'pending',
			'_resolved_status'   => 'pending',
			'schedule_id'        => $recurring_result['schedule_id'],
			'next_run_date'      => $recurring_result['next_run_date'],
			'schedule_type'      => $recurring_result['schedule_type'],
			'installment_count'  => $recurring_result['installment_count'],
			'per_payment_amount' => $recurring_result['per_payment_amount'],
			'_capture_requested' => true,
		], $is_install ? $recurring_result['per_payment_amount'] : $amount );
		do_action( 'frm_ab_lite_payment_complete', [], $entry, $form, $action );
		return;
	}

	// ── 6b. Non-recurring path: standard direct charge ────────────────────
	// ── 6. Create charge ─────────────────────────────────────────────────────
	$result = $api->create_charge( $charge_args );

	// ── Check 1: WP-level error (network failure, HTTP 4xx/5xx) ─────────────
	if ( is_wp_error( $result ) ) {
		$error_msg = $result->get_error_message();
		Frm_AB_Lite_Logger::error( 'Charge FAILED: ' . $error_msg );
		frm_ab_lite_record_payment( $entry, $action, array( 'id' => '', 'status' => 'failed', 'error' => $error_msg ), $amount );
		global $frm_ab_lite_payment_error;
		$frm_ab_lite_payment_error = $error_msg;
		$uid = get_current_user_id() ?: session_id();
		if ( $uid ) set_transient( 'frm_ab_lite_payment_error_' . $uid, $error_msg, 60 );
		if ( ! session_id() && ! headers_sent() ) { session_start(); }
		$_SESSION['frm_ab_lite_error'] = $error_msg;
		// translators: %s is the payment error message.
		// translators: %s is the payment error message.
		frm_ab_lite_add_error( sprintf( __( 'Payment failed: %s', 'payment-gateway-accept-blue-for-formidable' ), $error_msg ) );
		do_action( 'frm_ab_lite_payment_failed', $error_msg, $entry );
		return;
	}

	// ── Check 2: API-level error (HTTP 200 but status = "Error"/"Declined") ─
	// accept.blue returns HTTP 200 with status:"Error" or status_code:"E"/"D"
	// for invalid card, declined, payment method not allowed, etc.
	$api_status      = strtolower( $result['status']      ?? '' );
	$api_status_code = strtoupper( $result['status_code'] ?? '' );
	$api_error_msg   = $result['error_message'] ?? $result['message'] ?? '';
	$api_is_error    = in_array( $api_status, array( 'error', 'declined' ), true )
	                || in_array( $api_status_code, array( 'E', 'D' ), true );

	if ( $api_is_error ) {
		$error_msg = $api_error_msg
			?: sprintf(
				/* translators: %s = status string from API */
				// translators: %1$s is the payment status (e.g. failed), %2$s is the status code.
				__( 'Payment %1$s (code: %2$s)', 'payment-gateway-accept-blue-for-formidable' ),
				ucfirst( $api_status ),
				$api_status_code
			);

		Frm_AB_Lite_Logger::error( 'Charge FAILED (API error): status=' . $api_status . ' code=' . $api_status_code . ' msg=' . $error_msg );
		frm_ab_lite_record_payment( $entry, $action, array(
			'id'     => $result['reference_number'] ?? $result['id'] ?? '',
			'status' => 'failed',
			'error'  => $error_msg,
		), $amount );

		global $frm_ab_lite_payment_error;
		$frm_ab_lite_payment_error = $error_msg;
		// Store in transient as fallback in case global is lost across hook scopes
		$uid = get_current_user_id() ?: session_id();
		if ( $uid ) set_transient( 'frm_ab_lite_payment_error_' . $uid, $error_msg, 60 );
		if ( ! session_id() && ! headers_sent() ) { session_start(); }
		$_SESSION['frm_ab_lite_error'] = $error_msg;
		// translators: %s is the payment error message.
		// translators: %s is the payment error message.
		frm_ab_lite_add_error( sprintf( __( 'Payment failed: %s', 'payment-gateway-accept-blue-for-formidable' ), $error_msg ) );
		do_action( 'frm_ab_lite_payment_failed', $error_msg, $entry );
		return;
	}

	Frm_AB_Lite_Logger::info( sprintf(
		'[Accept.Blue] Charge SUCCESS | Ref: %s | api_status: %s | detail_status: %s | mapped: %s | Amount: $%s',
		( $result['reference_number'] ?? $result['transaction']['id'] ?? $result['id'] ?? '?' ),
		$result['status'] ?? '?',
		$result['status_details']['status'] ?? $result['transaction']['status_details']['status'] ?? 'n/a',
		frm_ab_lite_map_status( $result ),
		number_format( $amount, 2 )
	) );
	Frm_AB_Lite_Logger::info( '[Accept.Blue] ✓ API RESPONSE | Full result: ' . wp_json_encode( $result ) );

	// ── 7. Record payment ────────────────────────────────────────────────────
	// Pass capture flag so record_payment can distinguish auth vs complete
	$result['_capture_requested'] = $charge_args['capture'] ?? true;
	frm_ab_lite_record_payment( $entry, $action, $result, $amount );

	do_action( 'frm_ab_lite_payment_complete', $result, $entry, $form, $action );
}

// ── Recurring: 3-step setup (no separate charge) ─────────────────────────────
//
// The recurring schedule handles ALL charges including the first one.
// next_run_date is set to TODAY (no trial) or TODAY + trial_days (with trial).
// accept.blue fires the first charge automatically on next_run_date.
//
//   Step 1 – Create customer         POST /customers
//   Step 2 – Attach payment method   POST /customers/{id}/payment-methods   (uses nonce)
//   Step 3 – Create schedule         POST /customers/{id}/recurring-schedules
//            next_run_date = today (no trial) or today + N days (trial)
//
// Returns array { customer_id, payment_method_id, schedule_id, next_run_date } on success,
// or WP_Error if any step fails.
// ─────────────────────────────────────────────────────────────────────────────

function frm_ab_lite_setup_recurring_then_charge( $api, $nonce, $amount, $settings, $billing, $charge_args, $entry, $customer_id = 0, $payment_method_id = 0 ) {

	$schedule_type    = $settings['schedule_type'] ?? 'subscription';
	$is_installment   = ( $schedule_type === 'installment' );
	$installment_count = max( 2, intval( $settings['installment_count'] ?? 3 ) );

	// Installment: split total evenly, round to cents, add remainder to last payment
	if ( $is_installment ) {
		$installment_amount = round( $amount / $installment_count, 2 );
		$remainder          = round( $amount - ( $installment_amount * $installment_count ), 2 );
		$charge_amount      = $installment_amount; // per-payment amount sent to API
		$num_left           = $installment_count;
	} else {
		$charge_amount = $amount;
		$num_left      = intval( $settings['recurring_duration'] ?? 0 ); // 0 = indefinite
	}

	$trial_type = $settings['trial_period_type'] ?? 'none';
	$trial_days = intval( $settings['trial_days'] ?? 0 );
	$trial_info = ( $trial_type === 'days' && $trial_days > 0 )
		? sprintf( '%d-day free trial', $trial_days )
		: 'no trial (first charge tomorrow)';

	if ( $is_installment ) {
		Frm_AB_Lite_Logger::info( sprintf(
			'[Recurring] START (INSTALLMENT) for entry #%d | total: $%s | %d payments of $%s%s | frequency: %s | trial: %s',
			$entry->id ?? 0,
			number_format( $amount, 2 ),
			$installment_count,
			number_format( $installment_amount, 2 ),
			0 !== (int) $remainder ? sprintf( ' (last: $%s)', number_format( $installment_amount + $remainder, 2 ) ) : '',
			$settings['recurring_frequency'] ?? 'monthly',
			$trial_info
		) );
	} else {
		Frm_AB_Lite_Logger::info( sprintf(
			'[Recurring] START (SUBSCRIPTION) for entry #%d | amount: $%s | frequency: %s | num_left: %s | trial: %s',
			$entry->id ?? 0,
			number_format( $amount, 2 ),
			$settings['recurring_frequency'] ?? 'monthly',
			$num_left ?: 'indefinite',
			$trial_info
		) );
	}

	// ── Step 1: Create customer ───────────────────────────────────────────
	// Skip if customer + payment_method were already created by the JS precheck
	// (recurring-vault path in frm_ab_lite_precheck_payment_handler).
	if ( ! $customer_id || ! $payment_method_id ) {

		$customer_args = [];
		if ( ! empty( $charge_args['customer']['email'] ) )      $customer_args['email']      = $charge_args['customer']['email'];
		elseif ( ! empty( $charge_args['email'] ) )              $customer_args['email']      = $charge_args['email'];
		if ( ! empty( $charge_args['name'] ) )                   $customer_args['name']       = $charge_args['name'];
		if ( ! empty( $charge_args['customer']['identifier'] ) ) $customer_args['identifier'] = $charge_args['customer']['identifier'];
		if ( ! empty( $entry->id ) )                             $customer_args['customer_number'] = 'frm_entry_' . $entry->id;

		Frm_AB_Lite_Logger::info( '[Step 1/4] Creating customer in accept.blue vault', $customer_args );

		$customer = $api->create_customer( $customer_args );
		if ( is_wp_error( $customer ) ) {
			return new WP_Error( 'recurring_step1', '[Step 1/4] create_customer failed: ' . $customer->get_error_message() );
		}

		$customer_id = intval( $customer['id'] ?? 0 );
		if ( ! $customer_id ) {
			return new WP_Error( 'recurring_step1', '[Step 1/4] create_customer returned no ID.' );
		}

		Frm_AB_Lite_Logger::info( '[Step 1/4] ✓ Customer created', [ 'customer_id' => $customer_id ] );

		// ── Step 2: Attach payment method (nonce) to the customer ─────────────
		$clean_nonce = ( 0 === strpos( $nonce, 'nonce-' ) ) ? substr( $nonce, strlen( 'nonce-' ) ) : $nonce;

		$pm_args = [];
		if ( ! empty( $charge_args['name'] ) )        $pm_args['name']         = $charge_args['name'];
		if ( ! empty( $charge_args['avs_zip'] ) )     $pm_args['avs_zip']      = $charge_args['avs_zip'];
		if ( ! empty( $charge_args['avs_address'] ) ) $pm_args['avs_address']  = $charge_args['avs_address'];
		if ( ! empty( $billing ) )                    $pm_args['billing_info'] = $billing;

		Frm_AB_Lite_Logger::info( '[Step 2/4] Attaching payment method to customer', [
			'customer_id'  => $customer_id,
			'nonce_prefix' => substr( $clean_nonce, 0, 8 ) . '…',
		] );

		$pm_result = $api->add_payment_method( $customer_id, $clean_nonce, $pm_args );
		if ( is_wp_error( $pm_result ) ) {
			return new WP_Error( 'recurring_step2', '[Step 2/4] add_payment_method failed: ' . $pm_result->get_error_message() );
		}

		$payment_method_id = intval( $pm_result['id'] ?? 0 );
		if ( ! $payment_method_id ) {
			return new WP_Error( 'recurring_step2', '[Step 2/4] add_payment_method returned no ID.' );
		}

		Frm_AB_Lite_Logger::info( '[Step 2/4] ✓ Payment method attached', [
			'customer_id' => $customer_id,
			'pm_id'       => $payment_method_id,
		] );

	} else {
		Frm_AB_Lite_Logger::info( sprintf(
			'[Step 1-2/4] Skipped — using pre-vaulted ids from precheck | customer_id=%d | pm_id=%d',
			$customer_id, $payment_method_id
		) );
	}
	// ── Step 3: Create recurring schedule ────────────────────────────────
	// next_run_date:
	//   No trial  → today (accept.blue fires first charge today)
	//   Trial     → today + N days
	// We never send a past date; the trial_days calculation always produces
	// a date >= today.
	// accept.blue requires next_run_date to be AFTER today (in EST).
	// Minimum is always tomorrow. "No trial" = tomorrow. Trial = tomorrow + N days.
	if ( 'days' === $trial_type && $trial_days > 0 ) {
		// +1 for tomorrow baseline, then add trial days on top
		$next_run_date = gmdate( 'Y-m-d', strtotime( '+' . ( $trial_days + 1 ) . ' days' ) );
		Frm_AB_Lite_Logger::info( sprintf(
			'[Step 3/3] Trial period: %d days — first charge on %s.',
			$trial_days, $next_run_date
		) );
	} else {
		// No trial — first charge tomorrow (earliest date accept.blue accepts)
		$next_run_date = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
		Frm_AB_Lite_Logger::info( '[Step 3/3] No trial — first charge tomorrow (' . $next_run_date . ').' );
	}

	// Build title: auto-label installments for clarity in accept.blue dashboard
	$schedule_title = $settings['recurring_title'] ?? '';
	if ( $is_installment && empty( $schedule_title ) ) {
		$schedule_title = sprintf(
			'%d-installment plan ($%s/%s)',
			$installment_count,
			number_format( $installment_amount, 2 ),
			$settings['recurring_frequency'] ?? 'monthly'
		);
	}

	$schedule = [
		'payment_method_id' => $payment_method_id,
		'frequency'         => $settings['recurring_frequency'] ?? 'monthly',
		'amount'            => $charge_amount,   // per-payment: full amount or installment slice
		'next_run_date'     => $next_run_date,
		'title'             => $schedule_title,
		'num_left'          => $num_left,        // installment count or subscription limit (0=indefinite)
	];

	Frm_AB_Lite_Logger::info( '[Step 3/3] Creating recurring schedule', [
		'type'          => $is_installment ? 'installment' : 'subscription',
		'customer_id'   => $customer_id,
		'pm_id'         => $payment_method_id,
		'frequency'     => $schedule['frequency'],
		'per_payment'   => number_format( $charge_amount, 2 ),
		'next_run_date' => $schedule['next_run_date'],
		'num_left'      => $num_left ?: 'indefinite',
	] );

	$sched_result = $api->create_recurring_schedule( $customer_id, $payment_method_id, $schedule );
	if ( is_wp_error( $sched_result ) ) {
		return new WP_Error( 'recurring_step3', '[Step 3/3] create_recurring_schedule failed: ' . $sched_result->get_error_message() );
	}

	$schedule_id = intval( $sched_result['id'] ?? 0 );
	if ( ! $schedule_id ) {
		return new WP_Error( 'recurring_step3', '[Step 3/3] create_recurring_schedule returned no schedule ID.' );
	}

	Frm_AB_Lite_Logger::info( '[Step 3/3] ✓ Recurring schedule created', [
		'schedule_id'   => $schedule_id,
		'customer_id'   => $customer_id,
		'pm_id'         => $payment_method_id,
		'frequency'     => $schedule['frequency'],
		'next_run_date' => $schedule['next_run_date'],
	] );

	// ── Done: persist IDs — accept.blue fires the first charge via the schedule ──
	// No separate charge call needed. The schedule's next_run_date controls billing.
	if ( $is_installment ) {
		Frm_AB_Lite_Logger::info( sprintf(
			'[Recurring] ✓ INSTALLMENT SETUP COMPLETE for entry #%d | %d × $%s/%s | schedule_id: %d | first_charge: %s | customer_id: %d | pm_id: %d',
			$entry->id ?? 0,
			$installment_count,
			number_format( $charge_amount, 2 ),
			$schedule['frequency'],
			$schedule_id,
			$next_run_date,
			$customer_id,
			$payment_method_id
		) );
	} else {
		Frm_AB_Lite_Logger::info( sprintf(
			'[Recurring] ✓ SUBSCRIPTION SETUP COMPLETE for entry #%d | $%s/%s | schedule_id: %d | first_charge: %s | customer_id: %d | pm_id: %d',
			$entry->id ?? 0,
			number_format( $charge_amount, 2 ),
			$schedule['frequency'],
			$schedule_id,
			$next_run_date,
			$customer_id,
			$payment_method_id
		) );
	}

	// Persist IDs to entry meta
	if ( ! empty( $entry->id ) ) {
		if ( class_exists( 'FrmEntryMeta' ) ) {
			FrmEntryMeta::update_entry_meta( $entry->id, 0, '_frm_ab_lite_customer_id', $customer_id );
			FrmEntryMeta::update_entry_meta( $entry->id, 0, '_frm_ab_lite_pm_id',       $payment_method_id );
			FrmEntryMeta::update_entry_meta( $entry->id, 0, '_frm_ab_lite_schedule_id', $schedule_id );
		} else {
			update_post_meta( $entry->id, '_frm_ab_lite_customer_id', $customer_id );
			update_post_meta( $entry->id, '_frm_ab_lite_pm_id',       $payment_method_id );
			update_post_meta( $entry->id, '_frm_ab_lite_schedule_id', $schedule_id );
		}
	}

	// Persist schedule metadata to payments table for dashboard
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->update(
		$wpdb->prefix . 'frm_ab_lite_payments',
		[ 'meta_value' => wp_json_encode( [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'customer_id'       => $customer_id,
			'payment_method_id' => $payment_method_id,
			'schedule_id'       => $schedule_id,
			'frequency'         => $schedule['frequency'],
			'next_run_date'     => $next_run_date,
		] ) ],
		[ 'item_id' => $entry->id, 'paysys' => 'acceptblue' ],
		[ '%s' ],
		[ '%d', '%s' ]
	);

	do_action( 'frm_ab_lite_subscription_created', $sched_result, $entry, $customer_id, $payment_method_id );

	return [
		'customer_id'        => $customer_id,
		'payment_method_id'  => $payment_method_id,
		'schedule_id'        => $schedule_id,
		'next_run_date'      => $next_run_date,
		'schedule_type'      => $schedule_type,
		'installment_count'  => $is_installment ? $installment_count : 0,
		'per_payment_amount' => $charge_amount,
	];
}
// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Map an accept.blue v2 API response to our internal payment status.
 *
 * accept.blue returns TWO status fields:
 *   - result['status']                  e.g. "Approved" — top-level auth result
 *   - result['status_details']['status'] e.g. "queued" (auth-only) or "captured" (charged)
 *
 * We must check status_details first; "Approved" alone does NOT mean captured.
 *
 * status_details values:
 *   queued    = auth-only (authorised but not yet captured)
 *   captured  = captured/charged
 *   settled   = settled in batch
 *   voided    = voided
 *   refunded  = refunded
 *   failed    = failed
 */
function frm_ab_lite_map_status( array $result ): string {
	// Two response types have different structures:
	//
	// POST /transactions/charge (charge creation):
	//   result['status']       = "Approved"|"Declined"|"Error"|"Submitted"|"Partially Approved"
	//   result['status_code']  = "A"|"D"|"E"|"P"
	//   result['status_details']['status'] = "queued"|"captured"|"settled"|...
	//   result['_capture_requested'] injected by us before calling this function
	//
	// GET /transactions/{id} (sync):
	//   The transaction object may have no top-level 'status'.
	//   result['status_details']['status'] is the authoritative status:
	//     "captured","settled","queued","voided","declined","error","blocked","expired","cancelled","returned"
	//
	// Pre-auth transient path:
	//   result['_resolved_status'] set to the already-computed status string — use it directly.

	// ── Priority 0: pre-resolved status (from stored transient) ──────────
	if ( ! empty( $result['_resolved_status'] ) ) {
		return (string) $result['_resolved_status'];
	}

	$top         = strtolower( $result['status']                   ?? '' );
	$status_code = strtoupper( $result['status_code']              ?? '' );
	$captured    = $result['_capture_requested']                   ?? true;

	// status_details may be at the top level (GET /transactions/{id})
	// OR nested inside the 'transaction' sub-object (POST /transactions/charge).
	// Some versions of the accept.blue v2 response have it in both places; some only nested.
	$sd_top    = $result['status_details']['status']                  ?? '';
	$sd_nested = $result['transaction']['status_details']['status']   ?? '';
	$detail    = strtolower( $sd_top !== '' ? $sd_top : $sd_nested );

	// ── Priority 1: status_details is the most reliable source ───────────
	if ( '' !== $detail ) {
		switch ( $detail ) {
			case 'captured':
			case 'settled':
			case 'originated':
			case 'approved':
				return 'complete';

			case 'queued':    // auth-only awaiting capture
			case 'pending':
			case 'reserve':
				return 'auth';

			case 'voided':
				return 'voided';

			case 'returned':
			case 'refunded':
				return 'refunded';

			case 'declined':
			case 'error':
			case 'blocked':
			case 'expired':
			case 'cancelled':
				return 'failed';
		}
	}

	// ── Priority 2: POST top-level status (charge creation response) ─────
	if ( '' !== $top && 'unknown' !== $top ) {
		// Explicit failures
		if ( in_array( $top, [ 'declined', 'error', 'failed' ], true )
			|| $status_code === 'D' || $status_code === 'E' ) {
			return 'failed';
		}
		if ( 'voided' === $top ) return 'voided';

		// Approved — distinguish auth vs captured by capture flag
		if ( in_array( $top, [ 'approved', 'partially approved', 'submitted' ], true )
			|| $status_code === 'A' || $status_code === 'P' ) {
			if ( false === $captured || 0 === $captured ) {
				return 'auth';
			}
			return 'complete';
		}

		// Already-mapped internal status values (from stored transient fallback)
		if ( 'auth' === $top )     return 'auth';
		if ( 'complete' === $top ) return 'complete';
		if ( 'refunded' === $top ) return 'refunded';
		if ( 'voided' === $top )   return 'voided';
	}

	// ── Fallback ─────────────────────────────────────────────────────────
	return 'failed';
}

function frm_ab_lite_record_payment( $entry, $action, array $result, float $amount ) {
	global $wpdb;

	// accept.blue v2 returns reference_number at top level; id is inside transaction{}
	$charge_id = '';
	if ( ! empty( $result['reference_number'] ) ) {
		$charge_id = (string) $result['reference_number'];
	} elseif ( ! empty( $result['transaction']['id'] ) ) {
		$charge_id = (string) $result['transaction']['id'];
	} elseif ( ! empty( $result['id'] ) ) {
		$charge_id = (string) $result['id'];
	}
	$frm_status = frm_ab_lite_map_status( $result );

	// For auth-only transactions accept.blue returns auth_amount which includes any surcharge.
	// Use it as the authorised amount so the stored amount reflects what was actually reserved.
	if ( 'auth' === $frm_status && ! empty( $result['auth_amount'] ) ) {
		$amount = floatval( $result['auth_amount'] );
	}

	$data = array(
		'item_id'    => isset( $entry->id ) ? (int) $entry->id : 0,
		'action_id'  => isset( $action->ID ) ? (int) $action->ID : 0,
		'paysys'     => 'acceptblue',
		'amount'     => $amount,
		'status'     => $frm_status,
		'receipt_id' => (string) $charge_id,
		'meta_value' => wp_json_encode( $result ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		'created_at' => current_time( 'mysql' ),
	);

	$payment_id = 0;

	// ── 1. Always write to our own table (wp_frm_ab_lite_payments) ────────────────
	$our_table = $wpdb->prefix . 'frm_ab_lite_payments';
	$our_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $our_table ) ) === $our_table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( ! $our_exists ) {
		// Table missing — create it on the fly
		if ( function_exists( 'frm_ab_lite_create_table' ) ) {
			frm_ab_lite_create_table();
			$our_exists = true;
		}
	}
	if ( $our_exists ) {
		$inserted = $wpdb->insert( $our_table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $inserted ) {
			Frm_AB_Lite_Logger::error( 'Own table insert failed: ' . $wpdb->last_error );
		} else {
			$payment_id = $wpdb->insert_id;
			Frm_AB_Lite_Logger::info( 'Payment saved', [ 'id' => $payment_id, 'status' => $frm_status, 'charge' => $charge_id ] );
		}
	}

	// ── 2. Also write to Formidable Pro table if it exists ───────────────────
	$frm_table = $wpdb->prefix . 'frm_payments';
	$frm_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $frm_table ) ) === $frm_table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $frm_exists ) {
		$wpdb->insert( $frm_table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->last_error ) {
			Frm_AB_Lite_Logger::error( 'frm_payments insert error: ' . $wpdb->last_error );
		}
	}

	if ( 'complete' === $frm_status ) {
		$data['id'] = $payment_id;
		do_action( 'frm_payment_status_complete', array( 'payment' => (object) $data, 'entry' => $entry ) );
	} elseif ( 'auth' === $frm_status ) {
		// Auth-only: fire a dedicated hook so developers can act on it,
		// but do NOT fire frm_payment_status_complete (no money captured yet).
		$data['id'] = $payment_id;
		do_action( 'frm_ab_lite_payment_authorised', array( 'payment' => (object) $data, 'entry' => $entry ) );
	}
}

function frm_ab_lite_add_error( string $message ) {
	global $frmProErrors;
	if ( ! is_array( $frmProErrors ) ) $frmProErrors = array();
	$frmProErrors[] = $message;
	Frm_AB_Lite_Logger::error( $message );
}

/**
 * Hook 1: Intercept Formidable's redirect — when payment failed, redirect back
 * to the form page (not the thank-you page) with the error in the URL.
 */
add_filter( 'frm_redirect_url', function( $location, $form, $entry ) {
	global $frm_ab_lite_payment_error;
	if ( empty( $frm_ab_lite_payment_error ) ) return $location;

	// Delete the orphaned entry (no payment was taken)
	if ( ! empty( $entry ) && ! empty( $entry->id ) && class_exists( 'FrmEntry' ) ) {
		FrmEntry::destroy( $entry->id );
		Frm_AB_Lite_Logger::info( 'Entry #' . $entry->id . ' deleted - payment failed.' );
	}

	// Redirect back to the page the form was on, with the error in the URL
	$referrer = wp_get_referer() ?: get_permalink();
	return add_query_arg( 'frm_ab_lite_error', rawurlencode( $frm_ab_lite_payment_error ), $referrer );
}, 20, 3 );

/**
 * Hook: intercept Formidable's AJAX response before it is sent.
 * frm_ajax_success fires before wp_send_json — we replace the whole response
 * with a proper Formidable error array so its JS shows the error inline
 * and does NOT navigate to the thank-you page.
 */
/**
 * Hook: frm_after_create_entry — fires right after entry is saved, before AJAX response.
 * If payment failed, delete the entry and send a JSON error response immediately.
 * This is the most reliable intercept point for AJAX submissions.
 */
add_action( 'frm_after_create_entry', function( $entry_id, $form_id ) {
	global $frm_ab_lite_payment_error;
	if ( empty( $frm_ab_lite_payment_error ) ) return;
	if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) return;

	// Delete the orphaned entry — no payment was taken
	if ( $entry_id && class_exists( 'FrmEntry' ) ) {
		FrmEntry::destroy( $entry_id );
		Frm_AB_Lite_Logger::info( 'Entry #' . $entry_id . ' deleted - payment failed (after_create_entry).' );
	}
	// Keep $frm_ab_lite_payment_error set — frm_ajax_success will use it to override the response
}, 1, 2 );

/**
 * Guard: when payment has failed, prevent ALL other form actions (Confirmation,
 * Send Email, etc.) from firing. This stops the thank-you message from appearing.
 *
 * frm_before_trigger_action fires before each individual action executes.
 * Returning false skips that action entirely.
 *
 * frm_action_options_before_save: skip on save
 * frm_trigger_{action_type}_action: specific per type
 *
 * Most reliable: hook into 'frm_before_trigger_action' (Formidable Pro)
 * AND 'frm_trigger_confirmation_action' (both Lite and Pro).
 */
add_filter( 'frm_before_trigger_action', function( $action, $entry, $form, $event ) {
	global $frm_ab_lite_payment_error;
	if ( empty( $frm_ab_lite_payment_error ) ) return $action;
	// Block everything except our own payment action type
	$action_type = isset( $action->post_excerpt ) ? $action->post_excerpt : '';
	if ( 'acceptblue' !== $action_type ) {
		Frm_AB_Lite_Logger::info( '[Accept.Blue] Blocked action type "' . $action_type . '" — payment failed.' );
		return false; // returning false skips this action
	}
	return $action;
}, 10, 4 );

// Also target the confirmation action specifically (works in Formidable Lite)
add_filter( 'frm_trigger_confirmation_action', function( $action, $entry, $form ) {
	global $frm_ab_lite_payment_error;
	if ( ! empty( $frm_ab_lite_payment_error ) ) {
		Frm_AB_Lite_Logger::info( '[Accept.Blue] Blocked confirmation action — payment failed.' );
		return false;
	}
	return $action;
}, 10, 3 );

// Block email actions too
add_filter( 'frm_trigger_email_action', function( $action, $entry, $form ) {
	global $frm_ab_lite_payment_error;
	if ( ! empty( $frm_ab_lite_payment_error ) ) return false;
	return $action;
}, 10, 3 );

add_filter( 'frm_ajax_success', function( $return ) {
	// Check global AND transient — global can be lost if hooks run in separate scope
	global $frm_ab_lite_payment_error;
	$error_msg = $frm_ab_lite_payment_error;

	// Transient fallback (set in payment check code alongside the global)
	if ( empty( $error_msg ) ) {
		$uid       = get_current_user_id() ?: ( isset( $_COOKIE['frm_ab_lite_uid'] ) ? sanitize_key( $_COOKIE['frm_ab_lite_uid'] ) : '' );
		$error_msg = $uid ? (string) get_transient( 'frm_ab_lite_payment_error_' . $uid ) : '';
		if ( $error_msg ) delete_transient( 'frm_ab_lite_payment_error_' . $uid );
	}

	if ( empty( $error_msg ) ) return $return;
	$frm_ab_lite_payment_error = '';

	$error_html = '<div class="frm_ab_lite_error_msg frm_error" role="alert" style="display:flex;gap:8px;padding:10px 14px;">'
		. '<span style="flex-shrink:0;font-size:1.1em;">&#9888;</span>'
		// translators: %s is the payment error message.
		. '<span>' . esc_html( sprintf( __( 'Payment failed: %s', 'payment-gateway-accept-blue-for-formidable' ), $error_msg ) ) . '</span>'
		. '</div>';

	$return['errors']       = array( 'accept_blue_payment' => $error_msg );
	$return['content']      = $error_html;
	$return['redirect_url'] = '';
	$return['redirect']     = false;
	$return['pass']         = '0';
	$return['conf_method']  = '';
	$return['conf_id']      = '';

	Frm_AB_Lite_Logger::info( '[Accept.Blue] frm_ajax_success — injected payment error: ' . $error_msg );

	return $return;
}, 9999 );

/**
 * Fallback: frm_ajax_success may not exist in Formidable Lite.
 * Hook directly into wp_ajax_frm_entries_create shutdown to send our error JSON.
 * Uses output buffering to capture whatever Formidable already sent and replace it.
 */
add_action( 'wp_ajax_frm_entries_create',        'frm_ab_lite_maybe_override_ajax_response', 9999 );
add_action( 'wp_ajax_nopriv_frm_entries_create', 'frm_ab_lite_maybe_override_ajax_response', 9999 );

function frm_ab_lite_maybe_override_ajax_response() {
	global $frm_ab_lite_payment_error;
	if ( empty( $frm_ab_lite_payment_error ) ) return;

	$error_msg = $frm_ab_lite_payment_error;
	$frm_ab_lite_payment_error = '';

	$error_html = '<div class="frm_ab_lite_error_msg frm_error" role="alert" style="display:flex;gap:8px;padding:10px 14px;">'
		. '<span style="flex-shrink:0;font-size:1.1em;">&#9888;</span>'
		// translators: %s is the payment error message.
		. '<span>' . esc_html( sprintf( __( 'Payment failed: %s', 'payment-gateway-accept-blue-for-formidable' ), $error_msg ) ) . '</span>'
		. '</div>';

	// Discard anything Formidable already buffered and send our clean JSON
	while ( ob_get_level() > 0 ) { ob_end_clean(); }
	header( 'Content-Type: application/json; charset=utf-8' );
	echo wp_json_encode( array(
		'errors'       => array( 'accept_blue_payment' => $error_msg ),
		'content'      => $error_html,
		'redirect_url' => '',
		'pass'         => '0',
	) );
	exit;
}

/**
 * Hook 2: When the form is displayed and ?frm_ab_lite_error is in the URL,
 * inject the error into Formidable's validation so it renders above the form.
 * Also read from session as fallback.
 */
add_filter( 'frm_validate_entry', function( $errors, $values ) {
	$msg = '';
	if ( ! empty( $_GET['frm_ab_lite_error'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.NonceVerification.Recommended
		$msg = sanitize_text_field( rawurldecode( wp_unslash( $_GET['frm_ab_lite_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}
	if ( ! $msg ) {
		if ( ! session_id() && ! headers_sent() ) { session_start(); }
		if ( ! empty( $_SESSION['frm_ab_lite_error'] ) ) {
			$msg = sanitize_text_field( $_SESSION['frm_ab_lite_error'] );
			unset( $_SESSION['frm_ab_lite_error'] );
		}
	}
	if ( $msg ) {
		$errors['accept_blue_payment'] = $msg;
	}
	return $errors;
}, 10, 2 );

/**
 * Hook 3: Display the payment error as a styled notice above the form.
 * Fires on frm_display_form_action (before the form renders on the page).
 */
add_action( 'frm_display_form_action', function( $atts ) {
	$msg = '';
	if ( ! empty( $_GET['frm_ab_lite_error'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.NonceVerification.Recommended
		$msg = sanitize_text_field( rawurldecode( wp_unslash( $_GET['frm_ab_lite_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}
	if ( ! $msg ) {
		if ( ! session_id() && ! headers_sent() ) { session_start(); }
		if ( ! empty( $_SESSION['frm_ab_lite_error'] ) ) {
			$msg = sanitize_text_field( $_SESSION['frm_ab_lite_error'] );
			unset( $_SESSION['frm_ab_lite_error'] );
		}
	}
	if ( $msg ) {
		echo '<div class="frm_ab_lite_payment_error_notice">'
			. '<span class="frm_ab_lite_notice_icon">!</span>'
			// translators: %s is the payment error message.
			. '<span>' . esc_html( sprintf( __( 'Payment failed: %s', 'payment-gateway-accept-blue-for-formidable' ), $msg ) ) . '</span>'
			. '</div>';
	}
} );
