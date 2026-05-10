<?php
/**
 * Accept.Blue API v2 Wrapper
 *
 * Handles all server-side communication with the accept.blue REST API.
 * Base URL: https://api.accept.blue/api/v2
 * Auth:     HTTP Basic — username = API key, password = PIN (or empty string for key-only auth)
 *
 * @package FrmAcceptBlue
 */

defined( 'ABSPATH' ) || exit;

class Frm_AB_Lite_API {

	const LIVE_URL    = 'https://api.accept.blue/api/v2/';
	const SANDBOX_URL = 'https://api.sandbox.accept.blue/api/v2/';

	const USER_AGENT  = 'Formidable-AcceptBlue-Plugin/1.0.0 (WordPress)';

	/** @var string */
	private $api_key;

	/** @var string */
	private $pin;

	/** @var bool */
	private $test_mode;

	/** @var string */
	private $base_url;

	/**
	 * @param string $api_key   accept.blue API key
	 * @param string $pin       PIN associated with the key (may be empty)
	 * @param bool   $test_mode Whether to use the sandbox endpoint
	 */
	public function __construct( string $api_key, string $pin = '', bool $test_mode = false ) {
		$this->api_key   = trim( $api_key );
		$this->pin       = trim( $pin );
		$this->test_mode = $test_mode;
		$this->base_url  = $test_mode ? self::SANDBOX_URL : self::LIVE_URL;
	}

	// -------------------------------------------------------------------------
	// Charges
	// -------------------------------------------------------------------------

	/**
	 * Create a charge using a hosted-tokenization nonce or a saved customer token.
	 *
	 * @param array $args {
	 *   @type string $source          Required. Nonce (e.g. "nonce-xxxx") or customer token id.
	 *   @type float  $amount          Required. Charge amount (e.g. 25.00).
	 *   @type string $currency        Optional. Default "USD".
	 *   @type string $description     Optional. Charge description shown in virtual terminal.
	 *   @type array  $billing_info    Optional. Keys: name, email, address, city, state, zip, country.
	 *   @type bool   $save_card       Optional. Save card to customer vault.
	 *   @type string $customer_id     Optional. Existing accept.blue customer ID.
	 *   @type string $invoice_number  Optional.
	 *   @type array  $custom_fields   Optional. Up to 20 key=>value pairs.
	 * }
	 * @return array|WP_Error  Decoded response body on success, WP_Error on failure.
	 */
	public function create_charge( array $args ) {
		// Prefix nonce with "nonce-" as required by accept.blue hosted tokenization
		$source = $args['source'];
		if ( $source && strpos( $source, 'nonce-' ) !== 0 ) {
			$source = 'nonce-' . $source;
		}

		$body = [
			'source'       => $source,
			'amount'       => round( floatval( $args['amount'] ), 2 ),
			'currency'     => $args['currency'] ?? 'USD',
			// Required for nonce-based charges: send any valid month/year
			'expiry_month' => ! empty( $args['expiry_month'] ) ? intval( $args['expiry_month'] ) : intval( gmdate( 'n' ) ),
			'expiry_year'  => ! empty( $args['expiry_year']  ) ? intval( $args['expiry_year']  ) : intval( gmdate( 'Y' ) ) + 1,
		];

		// Capture flag (true = immediate charge, false = auth-only)
		$body['capture'] = isset( $args['capture'] ) ? (bool) $args['capture'] : true;

		// Name on card (cardholder name)
		if ( ! empty( $args['name'] ) ) {
			$body['name'] = sanitize_text_field( $args['name'] );
		}

		// Email at top level (accept.blue accepts it here)
		if ( ! empty( $args['email'] ) ) {
			$body['email'] = sanitize_email( $args['email'] );
		}

		// AVS fields at top level
		if ( ! empty( $args['avs_zip'] ) ) {
			$body['avs_zip'] = sanitize_text_field( $args['avs_zip'] );
		}
		if ( ! empty( $args['avs_address'] ) ) {
			$body['avs_address'] = sanitize_text_field( $args['avs_address'] );
		}

		// Transaction description (top-level, not nested)
		if ( ! empty( $args['description'] ) ) {
			$body['description'] = sanitize_text_field( $args['description'] );
		}

		// Invoice number
		if ( ! empty( $args['invoice_number'] ) ) {
			$body['invoice_number'] = sanitize_text_field( $args['invoice_number'] );
		}

		// Billing info object
		if ( ! empty( $args['billing_info'] ) && is_array( $args['billing_info'] ) ) {
			$body['billing_info'] = array_map( 'sanitize_text_field', $args['billing_info'] );
		}

		// Customer vault — email, identifier, customer_number
		$customer = array();
		if ( ! empty( $args['customer']['email'] ) )           $customer['email']           = sanitize_email( $args['customer']['email'] );
		if ( ! empty( $args['customer']['identifier'] ) )      $customer['identifier']      = sanitize_text_field( $args['customer']['identifier'] );
		if ( ! empty( $args['customer']['customer_number'] ) ) $customer['customer_number'] = sanitize_text_field( $args['customer']['customer_number'] );
		if ( ! empty( $customer ) )                            $body['customer']            = $customer;

		if ( ! empty( $args['customer_id'] ) ) {
			$body['customer_id'] = intval( $args['customer_id'] );
		}
		if ( ! empty( $args['save_card'] ) ) {
			$body['save_card'] = (bool) $args['save_card'];
		}
		if ( ! empty( $args['custom_fields'] ) && is_array( $args['custom_fields'] ) ) {
			$body['custom_fields'] = $args['custom_fields'];
		}

		// 3DS2 browser info — passed from JS via hidden field
		if ( ! empty( $args['three_ds'] ) && is_array( $args['three_ds'] ) ) {
			$body['three_ds'] = $args['three_ds'];
		}

		// Level 3 line items
		if ( ! empty( $args['line_items'] ) && is_array( $args['line_items'] ) ) {
			$body['line_items'] = $args['line_items'];
		}

		// Transaction details object (batch_id etc — only include if non-empty description)
		if ( ! empty( $args['transaction_details'] ) && is_array( $args['transaction_details'] ) ) {
			// Merge description into top-level if set
			if ( ! isset( $body['description'] ) && ! empty( $args['transaction_details']['description'] ) ) {
				$body['description'] = sanitize_text_field( $args['transaction_details']['description'] );
			}
		}

		return $this->request( 'POST', 'transactions/charge', $body );
	}

	/**
	 * Retrieve a single transaction by reference_number.
	 *
	 * @param int $charge_id  reference_number from charge response
	 * @return array|WP_Error
	 */
	public function get_charge( int $charge_id ) {
		return $this->request( 'GET', "transactions/{$charge_id}" );
	}

	/**
	 * Create a charge against a saved customer payment method (vault token).
	 * Use this instead of create_charge() when the card is already saved in the vault
	 * (e.g. after add_payment_method() returns a payment_method_id).
	 *
	 * The source for a saved token is the integer payment_method_id — NOT a nonce.
	 * accept.blue identifies saved tokens by their integer ID directly in `source`.
	 *
	 * @param int   $payment_method_id   ID returned by add_payment_method()
	 * @param array $args                Same keys as create_charge() except `source` is ignored.
	 * @return array|WP_Error
	 */
	public function charge_by_payment_method( int $payment_method_id, array $args ) {
		// Build charge body without the nonce-prefix logic — token IDs are integers
		$body = [
			'source'   => $payment_method_id,  // integer token ID, no "nonce-" prefix
			'amount'   => round( floatval( $args['amount'] ), 2 ),
			'currency' => $args['currency'] ?? 'USD',
			'capture'  => isset( $args['capture'] ) ? (bool) $args['capture'] : true,
		];

		if ( ! empty( $args['name'] ) )            $body['name']           = sanitize_text_field( $args['name'] );
		if ( ! empty( $args['email'] ) )            $body['email']          = sanitize_email( $args['email'] );
		if ( ! empty( $args['avs_zip'] ) )          $body['avs_zip']        = sanitize_text_field( $args['avs_zip'] );
		if ( ! empty( $args['avs_address'] ) )      $body['avs_address']    = sanitize_text_field( $args['avs_address'] );
		if ( ! empty( $args['description'] ) )      $body['description']    = sanitize_text_field( $args['description'] );
		if ( ! empty( $args['invoice_number'] ) )   $body['invoice_number'] = sanitize_text_field( $args['invoice_number'] );
		if ( ! empty( $args['customer_id'] ) )      $body['customer_id']    = intval( $args['customer_id'] );
		if ( ! empty( $args['billing_info'] ) )     $body['billing_info']   = array_map( 'sanitize_text_field', $args['billing_info'] );
		if ( ! empty( $args['line_items'] ) )       $body['line_items']     = $args['line_items'];

		return $this->request( 'POST', 'transactions/charge', $body );
	}

	/**
	 * Capture a previously authorised (auth-only) transaction.
	 * POST /transactions/capture
	 *
	 * @param int   $charge_id  reference_number of the auth transaction
	 * @param float $amount     Optional — omit to capture the full auth amount
	 * @return array|WP_Error
	 */
	public function capture_charge( int $charge_id, float $amount = 0 ) {
		$body = [ 'reference_number' => $charge_id ];
		if ( $amount > 0 ) {
			$body['amount'] = round( $amount, 2 );
		}
		return $this->request( 'POST', 'transactions/capture', $body );
	}

	/**
	 * Adjust and capture a previously authorised transaction.
	 * POST /transactions/adjust-capture
	 * Adjusts amount and immediately captures into batch.
	 *
	 * @param int   $charge_id  reference_number of the auth transaction
	 * @param float $amount     New amount to capture (required)
	 * @return array|WP_Error
	 */
	public function adjust_capture_charge( int $charge_id, float $amount ) {
		$body = [
			'reference_number' => $charge_id,
			'amount'           => round( $amount, 2 ),
		];
		return $this->request( 'POST', 'transactions/adjust-capture', $body );
	}

	/**
	 * Refund a charge (full or partial).
	 *
	 * @param int   $charge_id
	 * @param float $amount     Amount to refund. Pass 0 for full refund.
	 * @return array|WP_Error
	 */
	public function refund_charge( int $charge_id, float $amount = 0 ) {
		// POST /transactions/reversal  — reference_number in body, not URL
		$body = [ 'reference_number' => $charge_id ];
		if ( $amount > 0 ) {
			$body['amount'] = round( $amount, 2 );
		}
		return $this->request( 'POST', 'transactions/reversal', $body );
	}

	/**
	 * Void an unsettled charge.
	 *
	 * @param int $charge_id
	 * @return array|WP_Error
	 */
	public function void_charge( int $charge_id ) {
		// POST /transactions/void  — reference_number in body, not URL
		$body = [ 'reference_number' => $charge_id ];
		return $this->request( 'POST', 'transactions/void', $body );
	}

	// -------------------------------------------------------------------------
	// Customers / Vault
	// -------------------------------------------------------------------------

	/**
	 * Create a customer in the accept.blue vault.
	 *
	 * @param array $args Keys: email, name, phone, identifier (merchant ref).
	 * @return array|WP_Error
	 */
	public function create_customer( array $args ) {
		$body = [];
		foreach ( [ 'email', 'name', 'phone', 'identifier', 'customer_number' ] as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$body[ $key ] = sanitize_text_field( $args[ $key ] );
			}
		}
		// accept.blue v2 requires 'identifier' — auto-generate one if not supplied.
		if ( empty( $body['identifier'] ) ) {
			$body['identifier'] = 'frm-ab-lite-' . substr( md5( uniqid( '', true ) ), 0, 12 );
		}
		return $this->request( 'POST', 'customers', $body );
	}

	/**
	 * Get a customer by accept.blue customer ID.
	 *
	 * @param int $customer_id
	 * @return array|WP_Error
	 */
	public function get_customer( int $customer_id ) {
		return $this->request( 'GET', "customers/{$customer_id}" );
	}

	// -------------------------------------------------------------------------
	// Customer payment methods
	// -------------------------------------------------------------------------

	/**
	 * Attach a payment method (nonce) to a customer vault.
	 * POST /customers/{id}/payment-methods
	 *
	 * @param int    $customer_id  accept.blue customer ID
	 * @param string $nonce        Hosted tokenization nonce (without "nonce-" prefix)
	 * @param array  $args         Optional: expiry_month, expiry_year, name, billing_info
	 * @return array|WP_Error
	 */
	public function add_payment_method( int $customer_id, string $nonce, array $args = [] ) {
		// Source must be prefixed with "nonce-"
		$source = ( strpos( $nonce, 'nonce-' ) === 0 ) ? $nonce : 'nonce-' . $nonce;
		$body = [
			'source'       => $source,
			'expiry_month' => ! empty( $args['expiry_month'] ) ? intval( $args['expiry_month'] ) : intval( gmdate( 'n' ) ),
			'expiry_year'  => ! empty( $args['expiry_year']  ) ? intval( $args['expiry_year']  ) : intval( gmdate( 'Y' ) ) + 1,
		];
		if ( ! empty( $args['name'] ) ) {
			$body['name'] = sanitize_text_field( $args['name'] );
		}
		if ( ! empty( $args['avs_zip'] ) ) {
			$body['avs_zip'] = sanitize_text_field( $args['avs_zip'] );
		}
		if ( ! empty( $args['avs_address'] ) ) {
			$body['avs_address'] = sanitize_text_field( $args['avs_address'] );
		}
		if ( ! empty( $args['billing_info'] ) && is_array( $args['billing_info'] ) ) {
			$body['billing_info'] = array_map( 'sanitize_text_field', $args['billing_info'] );
		}
		return $this->request( 'POST', "customers/{$customer_id}/payment-methods", $body );
	}

	// -------------------------------------------------------------------------
	// Recurring schedules
	// -------------------------------------------------------------------------

	/**
	 * Create a recurring schedule on a customer.
	 * POST /customers/{id}/recurring-schedules
	 *
	 * @param int   $customer_id
	 * @param int   $payment_method_id  Payment method ID from add_payment_method()
	 * @param array $schedule {
	 *   frequency     string  daily|weekly|biweekly|monthly|bimonthly|quarterly|biannually|annually
	 *   amount        float
	 *   next_run_date string  Y-m-d  (defaults to today)
	 *   title         string  Optional schedule label
	 *   num_left      int     0 = indefinite
	 * }
	 * @return array|WP_Error
	 */
	public function create_recurring_schedule( int $customer_id, int $payment_method_id, array $schedule ) {
		$body = [
			'payment_method_id' => $payment_method_id,
			'frequency'         => sanitize_text_field( $schedule['frequency'] ?? 'monthly' ),
			'amount'            => round( floatval( $schedule['amount'] ?? 0 ), 2 ),
			'next_run_date'     => sanitize_text_field( $schedule['next_run_date'] ?? gmdate( 'Y-m-d' ) ),
		];
		if ( ! empty( $schedule['title'] ) ) {
			$body['title'] = sanitize_text_field( $schedule['title'] );
		}
		if ( isset( $schedule['num_left'] ) && intval( $schedule['num_left'] ) > 0 ) {
			$body['num_left'] = intval( $schedule['num_left'] );
		}
		return $this->request( 'POST', "customers/{$customer_id}/recurring-schedules", $body );
	}

	/**
	 * List recurring schedules with optional filters.
	 * GET /recurring-schedules
	 * Automatically paginates to return all results.
	 *
	 * @param array $params  Optional: customer_id, status, limit, page, sort, order
	 * @return array|WP_Error  Flat array of all schedule objects, or WP_Error on failure.
	 */
	public function list_recurring_schedules( array $params = [] ) {
		$page       = 1;
		$per_page   = 100;
		$all        = array();

		// Allow callers to pass explicit limit/page to skip pagination
		if ( isset( $params['limit'] ) && isset( $params['page'] ) ) {
			return $this->request( 'GET', 'recurring-schedules', null, $params );
		}

		// Paginate until the API returns fewer items than requested (last page)
		while ( true ) {
			$query  = array_merge( $params, [
				'limit' => $per_page,
				'page'  => $page,
			] );
			$result = $this->request( 'GET', 'recurring-schedules', null, $query );

			if ( is_wp_error( $result ) ) {
				// Return error on first page, partial data on subsequent pages
				return $page === 1 ? $result : $all;
			}

			// accept.blue wraps results in { data: [...], total: N } or returns a plain array
			$items = is_array( $result ) ? ( $result['data'] ?? $result ) : [];
			if ( ! is_array( $items ) ) {
				break;
			}

			$all = array_merge( $all, $items );

			// Stop if we got fewer items than requested (last page)
			if ( count( $items ) < $per_page ) {
				break;
			}
			$page++;

			// Safety cap: never fetch more than 50 pages (5,000 schedules)
			if ( $page > 50 ) {
				break;
			}
		}

		// Sort newest first by schedule ID (largest ID = most recently created)
		usort( $all, function( $a, $b ) {
			return (int) ( $b['id'] ?? 0 ) - (int) ( $a['id'] ?? 0 );
		} );

		return $all;
	}

	/**
	 * Get a single recurring schedule.
	 * GET /recurring-schedules/{id}
	 */
	public function get_recurring_schedule( int $schedule_id ) {
		return $this->request( 'GET', "recurring-schedules/{$schedule_id}" );
	}

	/**
	 * Cancel a recurring schedule.
	 * POST /recurring-schedules/{id}/cancel
	 */
	public function cancel_recurring_schedule( int $schedule_id ) {
		return $this->request( 'POST', "recurring-schedules/{$schedule_id}/cancel" );
	}

	/**
	 * Pause a recurring schedule.
	 * POST /recurring-schedules/{id}/pause
	 */
	public function pause_recurring_schedule( int $schedule_id ) {
		return $this->request( 'POST', "recurring-schedules/{$schedule_id}/pause" );
	}

	/**
	 * Resume a paused recurring schedule.
	 * POST /recurring-schedules/{id}/resume
	 */
	public function resume_recurring_schedule( int $schedule_id ) {
		return $this->request( 'POST', "recurring-schedules/{$schedule_id}/resume" );
	}

	// -------------------------------------------------------------------------
	// Transactions list / reporting
	// -------------------------------------------------------------------------

	/**
	 * List recent transactions with optional filters.
	 *
	 * @param array $params  Query params: limit, page, customer_id, start_date, end_date.
	 * @return array|WP_Error
	 */
	public function list_transactions( array $params = [] ) {
		return $this->request( 'GET', 'transactions', null, $params );
	}

	// -------------------------------------------------------------------------
	// Public proxy (used by extension classes)
	// -------------------------------------------------------------------------

	/**
	 * Public wrapper around the internal request method.
	 * Allows extension classes (e.g. Frm_AB_Lite_Recurring) to call arbitrary endpoints
	 * without duplicating auth logic.
	 *
	 * @param string     $method
	 * @param string     $endpoint
	 * @param array|null $body
	 * @param array      $params
	 * @return array|WP_Error
	 */
	public function raw_request( string $method, string $endpoint, ?array $body = null, array $params = [] ) {
		return $this->request( $method, $endpoint, $body, $params );
	}

	// -------------------------------------------------------------------------
	// Internal HTTP helper
	// -------------------------------------------------------------------------

	/**
	 * Send an authenticated request to the accept.blue v2 API.
	 * Logs full request and response to the PHP error log when debug logging is enabled
	 * in Formidable → Global Settings → Accept.Blue → Debug Logging.
	 *
	 * @param string     $method
	 * @param string     $endpoint
	 * @param array|null $body
	 * @param array      $params
	 * @return array|WP_Error
	 */
	private function request( string $method, string $endpoint, ?array $body = null, array $params = [] ) {
		$url = $this->base_url . ltrim( $endpoint, '/' );

		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		$json_body = null;
		if ( null !== $body && in_array( strtoupper( $method ), [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			$json_body = wp_json_encode( $body );
		}

		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => 30,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' . $this->pin ),
				'User-Agent'    => self::USER_AGENT . '; WordPress/' . get_bloginfo( 'version' ),
			],
		];

		if ( $json_body !== null ) {
			$args['body'] = $json_body;
		}

		$debug = class_exists( 'Frm_AB_Lite_Settings' ) && Frm_AB_Lite_Settings::is_debug_enabled();

		// ── Debug: Request log ─────────────────────────────────────────────
		if ( $debug ) {
			$log_body = $body;
			if ( is_array( $log_body ) && isset( $log_body['source'] ) ) {
				$log_body['source'] = substr( $log_body['source'], 0, 12 ) . '[redacted]';
			}
			Frm_AB_Lite_Logger::request(
				$args['method'] . ' ' . $url,
				array_merge( [ 'mode' => $this->test_mode ? 'SANDBOX' : 'LIVE' ], $log_body ?: [] )
			);
		}

		// ── Execute ────────────────────────────────────────────────────────
		$start    = microtime( true );
		$response = wp_remote_request( $url, $args );
		$elapsed  = round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			Frm_AB_Lite_Logger::error(
				'NETWORK ERROR ' . $args['method'] . ' ' . $url,
				[ 'ms' => $elapsed, 'error' => $response->get_error_message() ]
			);
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$raw_body  = wp_remote_retrieve_body( $response );
		$decoded   = json_decode( $raw_body, true );

		// ── Debug: Response log ────────────────────────────────────────────
		if ( $debug ) {
			Frm_AB_Lite_Logger::response(
				$args['method'] . ' ' . $url . ' HTTP ' . $http_code . ' (' . $elapsed . 'ms)',
				$decoded ?? $raw_body
			);
		}

		if ( $http_code >= 400 ) {
			$message = $decoded['message'] ?? $decoded['error'] ?? "HTTP {$http_code} error from accept.blue";
			Frm_AB_Lite_Logger::error(
				'API ERROR HTTP ' . $http_code . ' ' . $args['method'] . ' ' . $url,
				[ 'message' => $message ]
			);
			return new WP_Error( 'acceptblue_api_error', $message, [
				'http_code' => $http_code,
				'body'      => $decoded,
			] );
		}

		return $decoded;
	}
}
