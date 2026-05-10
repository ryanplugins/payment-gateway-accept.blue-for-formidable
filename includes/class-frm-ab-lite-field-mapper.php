<?php
/**
 * Accept.Blue Field Mapper
 *
 * Maps Formidable entry meta values to the accept.blue v2 charge API structure.
 *
 * accept.blue API reference:
 *   POST /charges
 *
 * @package FrmAcceptBlue
 */

defined( 'ABSPATH' ) || exit;

class Frm_AB_Lite_Field_Mapper {

	/**
	 * Build a complete accept.blue charge payload from a Formidable entry.
	 *
	 * Reads from well-known field keys (ab_cc_*) OR from the action settings
	 * field mapping (for custom forms).
	 *
	 * @param object $entry    Formidable entry object.
	 * @param object $form     Formidable form object.
	 * @param object $action   Form action object (settings in ->post_content).
	 * @param float  $amount   Resolved charge amount.
	 * @param string $nonce    Tokenization nonce.
	 * @return array           Charge args ready for Frm_AB_Lite_API::create_charge().
	 */
	public static function build_charge_args( $entry, $form, $action, float $amount, string $nonce ): array {
		$metas    = self::get_metas( $entry, $form );
		$settings = is_array( $action->post_content ) ? $action->post_content : array();

		$args = array(
			'source'   => $nonce,
			'amount'   => $amount,
			'currency' => isset( $settings['currency'] ) ? $settings['currency'] : 'USD',
		);

		// ── Name on card ──────────────────────────────────────────────────────
		$name = self::find( $metas, array( 'ab_cc_name', 'name', 'full_name', 'billing_name' ) );
		if ( $name ) {
			$args['name'] = $name;
		}

		// ── CVV2 ──────────────────────────────────────────────────────────────
		$cvv2 = self::find( $metas, array( 'ab_cc_cvv2', 'cvv2', 'cvv', 'cvc' ) );
		if ( $cvv2 ) {
			$args['cvv2'] = $cvv2;
		}

		// ── Save card ─────────────────────────────────────────────────────────
		$save_raw = self::find( $metas, array( 'ab_cc_save_card', 'save_card' ) );
		if ( ! empty( $save_raw ) || ! empty( $settings['save_card'] ) ) {
			$args['save_card'] = true;
		}

		// ── Billing address ───────────────────────────────────────────────────
		$billing = self::build_billing( $metas );
		if ( ! empty( $billing ) ) {
			$args['billing_info'] = $billing;
		}

		// ── AVS fields (top-level per accept.blue spec) ───────────────────────
		$avs_address = self::find( $metas, array( 'ab_cc_avs_address', 'avs_address', 'billing_address', 'address' ) );
		if ( $avs_address ) {
			$args['avs_address'] = $avs_address;
		}

		$avs_zip = self::find( $metas, array( 'ab_cc_avs_zip', 'avs_zip', 'billing_zip', 'zip', 'postal_code' ) );
		if ( $avs_zip ) {
			$args['avs_zip'] = $avs_zip;
		}

		// ── Shipping info (Level 3) ───────────────────────────────────────────
		$shipping = self::build_shipping( $metas );
		if ( ! empty( $shipping ) ) {
			$args['shipping_info'] = $shipping;
		}

		// ── Amount details (tax, Level 2/3) ───────────────────────────────────
		$amount_details = self::build_amount_details( $metas );
		if ( ! empty( $amount_details ) ) {
			$args['amount_details'] = $amount_details;
		}

		// ── Transaction details ───────────────────────────────────────────────
		$tx_description = self::find( $metas, array( 'ab_cc_description', 'description', 'order_description' ) );
		if ( $tx_description ) {
			$args['transaction_details'] = array( 'description' => $tx_description );
		}

		// ── Line items (Level 3) ──────────────────────────────────────────────
		$line_items = self::build_line_items( $metas );
		if ( ! empty( $line_items ) ) {
			$args['line_items'] = $line_items;
		}

		// ── Custom fields ─────────────────────────────────────────────────────
		$custom = self::build_custom_fields( $metas );
		if ( ! empty( $custom ) ) {
			$args['custom_fields'] = $custom;
		}

		// ── Customer info ─────────────────────────────────────────────────────
		$email    = self::find( $metas, array( 'ab_cc_email', 'email', 'email_address' ) );
		$phone    = self::find( $metas, array( 'ab_cc_phone', 'phone', 'phone_number' ) );
		$customer = array();
		if ( $email ) {
			$customer['email'] = $email;
		}
		if ( $phone ) {
			$customer['phone'] = $phone;
		}
		if ( ! empty( $customer ) ) {
			$args['customer'] = $customer;
		}

		// ── Ignore duplicates ─────────────────────────────────────────────────
		$ignore_dupes = self::find( $metas, array( 'ab_cc_ignore_duplicates', 'ignore_duplicates' ) );
		if ( ! empty( $ignore_dupes ) ) {
			$args['ignore_duplicates'] = true;
		}

		// ── Capture (default true) ────────────────────────────────────────────
		if ( isset( $settings['capture'] ) && $settings['capture'] === '0' ) {
			$args['capture'] = false;
		}

		return $args;
	}

	// -------------------------------------------------------------------------
	// Sub-builders
	// -------------------------------------------------------------------------

	private static function build_billing( array $metas ): array {
		$billing = array();

		$map = array(
			'first_name' => array( 'billing_first_name', 'first_name' ),
			'last_name'  => array( 'billing_last_name',  'last_name'  ),
			'company'    => array( 'billing_company',    'company'    ),
			'address'    => array( 'ab_cc_avs_address',  'billing_address', 'address', 'street' ),
			'city'       => array( 'ab_cc_billing_city', 'billing_city',    'city'    ),
			'state'      => array( 'ab_cc_billing_state','billing_state',   'state'   ),
			'zip'        => array( 'ab_cc_avs_zip',      'billing_zip',     'zip', 'postal_code' ),
			'country'    => array( 'ab_cc_billing_country', 'billing_country', 'country' ),
		);

		foreach ( $map as $ab_key => $aliases ) {
			$val = self::find( $metas, $aliases );
			if ( $val ) {
				$billing[ $ab_key ] = $val;
			}
		}

		// Merge first/last into full name if needed
		if ( ! isset( $billing['name'] ) ) {
			$first = $billing['first_name'] ?? '';
			$last  = $billing['last_name']  ?? '';
			$full  = trim( "$first $last" );
			if ( $full ) {
				$billing['name'] = $full;
			}
			unset( $billing['first_name'], $billing['last_name'] );
		}

		return $billing;
	}

	private static function build_shipping( array $metas ): array {
		$shipping = array();

		$map = array(
			'name'    => array( 'ab_cc_ship_name',    'ship_name',    'shipping_name'    ),
			'address' => array( 'ab_cc_ship_address', 'ship_address', 'shipping_address' ),
			'city'    => array( 'ab_cc_ship_city',    'ship_city',    'shipping_city'    ),
			'state'   => array( 'ab_cc_ship_state',   'ship_state',   'shipping_state'   ),
			'zip'     => array( 'ab_cc_ship_zip',     'ship_zip',     'shipping_zip'     ),
			'country' => array( 'ab_cc_ship_country', 'ship_country', 'shipping_country' ),
		);

		foreach ( $map as $ab_key => $aliases ) {
			$val = self::find( $metas, $aliases );
			if ( $val ) {
				$shipping[ $ab_key ] = $val;
			}
		}

		return $shipping;
	}

	private static function build_amount_details( array $metas ): array {
		$details = array();

		$tax = self::find( $metas, array( 'ab_cc_tax', 'tax', 'tax_amount' ) );
		if ( $tax !== null && $tax !== '' ) {
			$details['tax'] = floatval( $tax );
		}

		$tax_pct = self::find( $metas, array( 'ab_cc_tax_percent', 'tax_percent', 'tax_rate' ) );
		if ( $tax_pct !== null && $tax_pct !== '' ) {
			$details['tax_percent'] = floatval( $tax_pct );
		}

		return $details;
	}

	private static function build_line_items( array $metas ): array {
		$sku         = self::find( $metas, array( 'ab_cc_item_sku',         'item_sku',         'sku'         ) );
		$description = self::find( $metas, array( 'ab_cc_item_description', 'item_description', 'description' ) );
		$cost        = self::find( $metas, array( 'ab_cc_item_cost',        'item_cost',        'unit_cost'   ) );
		$quantity    = self::find( $metas, array( 'ab_cc_item_quantity',    'item_quantity',    'quantity'    ) );
		$tax_rate    = self::find( $metas, array( 'ab_cc_item_tax_rate',    'item_tax_rate'                   ) );

		if ( empty( $sku ) && empty( $description ) && empty( $cost ) ) {
			return array();
		}

		$item = array();
		if ( $sku ) {
			$item['sku']         = $sku;
		}
		if ( $description ) {
			$item['description'] = $description;
		}
		if ( $cost ) {
			$item['cost']        = floatval( $cost );
		}
		if ( $quantity ) {
			$item['quantity']    = intval( $quantity );
		}
		if ( $tax_rate ) {
			$item['tax_rate']    = floatval( $tax_rate );
		}

		return ! empty( $item ) ? array( $item ) : array();
	}

	private static function build_custom_fields( array $metas ): array {
		$custom = array();

		for ( $i = 1; $i <= 20; $i++ ) {
			$val = self::find( $metas, array(
				"ab_cc_custom_{$i}",
				"custom_{$i}",
				"custom_field_{$i}",
			) );
			if ( $val !== null && $val !== '' ) {
				$custom[ "custom_{$i}" ] = $val;
			}
		}

		return $custom;
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	/**
	 * Find the first non-empty value for any of the given field key aliases.
	 *
	 * @param array    $metas    key => value map of all field keys in the entry.
	 * @param string[] $aliases  List of field keys to try in order.
	 * @return string|null
	 */
	private static function find( array $metas, array $aliases ): ?string {
		foreach ( $aliases as $alias ) {
			if ( isset( $metas[ $alias ] ) && $metas[ $alias ] !== '' ) {
				return sanitize_text_field( $metas[ $alias ] );
			}
		}
		return null;
	}

	/**
	 * Build a flat key=>value map of all entry meta values keyed by field_key.
	 *
	 * @param object $entry
	 * @param object $form
	 * @return array  field_key => value
	 */
	private static function get_metas( $entry, $form ): array {
		$result = array();

		if ( empty( $entry->metas ) ) {
			return $result;
		}

		$fields    = FrmField::get_all_for_form( $form->id );
		$id_to_key = array();
		foreach ( $fields as $field ) {
			$id_to_key[ $field->id ] = $field->field_key;
		}

		foreach ( $entry->metas as $field_id => $value ) {
			$key                  = isset( $id_to_key[ $field_id ] ) ? $id_to_key[ $field_id ] : $field_id;
			$result[ $key ]       = is_array( $value ) ? implode( ', ', $value ) : $value;
			$result[ $field_id ]  = $result[ $key ];
		}

		return $result;
	}

	/**
	 * Auto-detect billing address fields from an entry using well-known field key names.
	 * Used to supplement explicitly-mapped billing fields in the Form Action.
	 *
	 * @param object $entry
	 * @param object $form
	 * @return array Partial billing array (only keys found in the entry).
	 */
	public static function get_billing_from_entry( $entry, $form ): array {
		$metas   = self::get_metas( $entry, $form );
		$billing = array();

		$map = array(
			'first_name' => array( 'ab_billing_first_name', 'billing_first_name', 'first_name' ),
			'last_name'  => array( 'ab_billing_last_name',  'billing_last_name',  'last_name'  ),
			'street'     => array( 'ab_billing_street',     'billing_street',     'address',   'street_address' ),
			'street2'    => array( 'ab_billing_street2',    'billing_street2',    'address_2'  ),
			'city'       => array( 'ab_billing_city',       'billing_city',       'city'       ),
			'state'      => array( 'ab_billing_state',      'billing_state',      'state'      ),
			'zip'        => array( 'ab_billing_zip',        'billing_zip',        'zip',        'postal_code' ),
			'country'    => array( 'ab_billing_country',    'billing_country',    'country'    ),
			'phone'      => array( 'ab_billing_phone',      'billing_phone',      'phone'      ),
		);

		foreach ( $map as $ab_key => $candidates ) {
			$val = self::find( $metas, $candidates );
			if ( $val ) {
				$billing[ $ab_key ] = $val;
			}
		}

		return $billing;
	}
}
