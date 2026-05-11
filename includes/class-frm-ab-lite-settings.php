<?php
/**
 * Accept.Blue Settings
 *
 * Registers the accept.blue settings tab inside Formidable → Global Settings.
 *
 * Correct Formidable pattern (from official docs):
 *   frm_add_settings_section  → array with 'class' and 'function' keys
 *   The 'function' is 'route', which dispatches to display_form() or process_form()
 *
 * @package FrmAcceptBlue
 */

defined( 'ABSPATH' ) || exit;

class Frm_AB_Lite_Settings {

	const OPTION_KEY = 'frm_acceptblue_lite_settings';

	public static function init() {
		// Register tab — value MUST be an array, not a string
		add_filter( 'frm_add_settings_section', array( __CLASS__, 'add_settings_section' ) );

		// Handle save on the update_settings action
		add_action( 'frm_update_settings', array( __CLASS__, 'save_settings' ) );

		// AJAX: test connection from settings page
		add_action( 'wp_ajax_frm_ab_lite_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
	}

	// -------------------------------------------------------------------------
	// Tab registration — array format required by Formidable
	// -------------------------------------------------------------------------

	public static function add_settings_section( $sections ) {
		$sections['acceptblue_lite'] = array(
			'name'     => __( 'Accept.Blue', 'frm-acceptblue-lite' ),
			'class'    => __CLASS__,
			'function' => 'route',
			'icon'     => 'frm_icon_font frm_credit_card_alt_icon',
		);
		return $sections;
	}

	// -------------------------------------------------------------------------
	// Route (dispatches display vs. save) — called by Formidable
	// -------------------------------------------------------------------------

	public static function route() {
		// Formidable calls route() for both display AND after save.
		// Only attempt to save when it's a POST request.
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['frm_ab_lite_settings'] ) ) {
			self::process_form();
		}
		self::display_form();
	}

	// -------------------------------------------------------------------------
	// Display
	// -------------------------------------------------------------------------

	public static function display_form() {
		$settings = self::get_settings();
		?>
		<style id="frm-ab-lite-pro-styles">
/* Pro feature panels use standard WP notice styling */
</style>
		<div class="frm_ab_lite_settings_wrap">

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
				<a href="https://www.patreon.com/posts/formidable-blue-157799373?source=lite" target="_blank" rel="noopener"
				   style="color:#7dd3fc;font-weight:700;text-decoration:none;">
					&#8599; Pro version
				</a>.
			</span>
		</div>
			<h3 style="display:flex;align-items:center;gap:10px;">
				<img src="<?php echo esc_url( FRM_AB_LITE_URL . 'assets/accept-blue-icon.svg' ); ?>"
					 style="width:28px;height:28px;border-radius:5px;flex-shrink:0;" alt="">
				<?php esc_html_e( 'accept.blue Payment Gateway', 'frm-acceptblue-lite' ); ?>
			</h3>
			<p>
				<?php printf(
					wp_kses(
						/* translators: %s = URL */
						__( 'Enter your <a href="%s" target="_blank" rel="noopener">accept.blue</a> credentials. Find your API key and Hosted Tokenization key in your accept.blue merchant portal.', 'frm-acceptblue-lite' ),
						array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
					),
					'https://accept.blue'
				); ?>
			</p>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="frm_ab_lite_test_mode"><?php esc_html_e( 'Test / Sandbox Mode', 'frm-acceptblue-lite' ); ?></label>
					</th>
					<td>
						<input type="checkbox"
							id="frm_ab_lite_test_mode"
							name="frm_ab_lite_settings[test_mode]"
							value="1"
							<?php checked( 1, $settings['test_mode'] ); ?> />
						<label for="frm_ab_lite_test_mode">
							<?php esc_html_e( 'Enable sandbox/test mode (no real charges)', 'frm-acceptblue-lite' ); ?>
						</label>
						<p class="description">
							<?php if ( ! empty( $settings['test_mode'] ) ) : ?>
								<strong style="color:#d63638;">⚠ <?php esc_html_e( 'Test Mode ON', 'frm-acceptblue-lite' ); ?></strong>
								— <?php printf(
									wp_kses( __( 'Requests go to <code>%s</code>. Use a <strong>Sandbox API key</strong> from your accept.blue portal.', 'frm-acceptblue-lite' ), array( 'code' => array(), 'strong' => array() ) ),
									esc_html( Frm_AB_Lite_API::SANDBOX_URL )
								); ?>
							<?php else : ?>
								<strong style="color:#00a32a;">✓ <?php esc_html_e( 'Live Mode ON', 'frm-acceptblue-lite' ); ?></strong>
								— <?php printf(
									wp_kses( __( 'Requests go to <code>%s</code>. Use a <strong>Production API key</strong>.', 'frm-acceptblue-lite' ), array( 'code' => array(), 'strong' => array() ) ),
									esc_html( Frm_AB_Lite_API::LIVE_URL )
								); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>

				<!-- ── DEBUG LOGGING ─────────────────────────────────────────────── -->
				<tr>
					<th scope="row">
						<label for="frm_ab_lite_debug_log"><?php esc_html_e( 'Debug Logging', 'frm-acceptblue-lite' ); ?></label>
					</th>
					<td>
						<input type="checkbox"
							id="frm_ab_lite_debug_log"
							name="frm_ab_lite_settings[debug_log]"
							value="1"
							<?php checked( 1, $settings['debug_log'] ); ?> />
						<label for="frm_ab_lite_debug_log">
							<?php esc_html_e( 'Enable debug logging for Accept.Blue API requests and responses', 'frm-acceptblue-lite' ); ?>
						</label>
						<p class="description">
							<?php
							$_ab_log_file = class_exists('Frm_AB_Lite_Logger') ? Frm_AB_Lite_Logger::get_log_file() : null;
							$_ab_log_files = class_exists('Frm_AB_Lite_Logger') ? Frm_AB_Lite_Logger::list_files() : [];
							?>
							<?php if ( ! empty( $settings['debug_log'] ) ) : ?>
								<strong style="color:#d63638;">&#9888; <?php esc_html_e( 'Debug logging is ON.', 'frm-acceptblue-lite' ); ?></strong>
								<?php esc_html_e( 'All API requests and responses are written to the Accept.Blue log file. Disable when not actively debugging.', 'frm-acceptblue-lite' ); ?>
								<br />
								<strong><?php esc_html_e( 'Log file:', 'frm-acceptblue-lite' ); ?></strong>
								<code style="font-size:11px;"><?php echo esc_html( $_ab_log_file ?: __( 'Not yet created', 'frm-acceptblue-lite' ) ); ?></code>
								<?php if ( ! empty( $_ab_log_files ) ) : ?>
									<br /><a href="<?php echo esc_url( admin_url('admin.php?page=frm-ab-lite-transactions&frm_ab_lite_view_log=1') ); ?>"><?php esc_html_e( 'View Log', 'frm-acceptblue-lite' ); ?></a>
								<?php endif; ?>
							<?php else : ?>
								<?php esc_html_e( 'When enabled, all Accept.Blue API requests and responses are written to a dedicated log file (separate from debug.log).', 'frm-acceptblue-lite' ); ?>
								<?php if ( $_ab_log_file ) : ?>
									<br /><code style="font-size:11px;color:#555;"><?php echo esc_html( dirname( $_ab_log_file ) ); ?>/accept-blue-YYYY-MM.log</code>
								<?php endif; ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="frm_ab_lite_api_key"><?php esc_html_e( 'API Key', 'frm-acceptblue-lite' ); ?></label>
					</th>
					<td>
						<input type="text"
							id="frm_ab_lite_api_key"
							name="frm_ab_lite_settings[api_key]"
							value="<?php echo esc_attr( $settings['api_key'] ); ?>"
							class="regular-text"
							autocomplete="off" />
						<p class="description">
							<?php esc_html_e( 'Used as the HTTP Basic Auth username when calling the accept.blue API.', 'frm-acceptblue-lite' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="frm_ab_lite_pin"><?php esc_html_e( 'PIN', 'frm-acceptblue-lite' ); ?></label>
					</th>
					<td>
						<input type="password"
							id="frm_ab_lite_pin"
							name="frm_ab_lite_settings[pin]"
							value="<?php echo esc_attr( $settings['pin'] ); ?>"
							class="regular-text"
							autocomplete="new-password" />
						<p class="description">
							<?php esc_html_e( 'HTTP Basic Auth password. Leave blank if your key does not use a PIN.', 'frm-acceptblue-lite' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="frm_ab_lite_tokenization_key"><?php esc_html_e( 'Hosted Tokenization Key', 'frm-acceptblue-lite' ); ?></label>
					</th>
					<td>
						<input type="text"
							id="frm_ab_lite_tokenization_key"
							name="frm_ab_lite_settings[tokenization_key]"
							value="<?php echo esc_attr( $settings['tokenization_key'] ); ?>"
							class="regular-text" />
						<p class="description">
							<?php esc_html_e( 'Public key for the accept.blue Hosted Tokenization iFrame. Found in your accept.blue portal → Hosted Tokenization.', 'frm-acceptblue-lite' ); ?>
						</p>
						<?php
						$tok_key   = $settings['tokenization_key'] ?? '';
						$is_sandbox = ! empty( $settings['test_mode'] );
						// Sandbox tokenization keys from accept.blue start with "pk_"
						// Live keys do not. Warn if they appear mismatched.
						if ( $tok_key && $is_sandbox && strpos( $tok_key, 'pk_' ) !== 0 ) : ?>
							<p style="color:#b91c1c;font-weight:600;margin-top:6px;">
								&#9888; <?php esc_html_e( 'Warning: Sandbox mode is ON but this key does not look like a sandbox Hosted Tokenization key (sandbox keys start with "pk_"). Please check your accept.blue portal → Hosted Tokenization and use the sandbox key.', 'frm-acceptblue-lite' ); ?>
							</p>
						<?php elseif ( $tok_key && ! $is_sandbox && strpos( $tok_key, 'pk_' ) === 0 ) : ?>
							<p style="color:#b45309;font-weight:600;margin-top:6px;">
								&#9888; <?php esc_html_e( 'Warning: Live mode is ON but this looks like a sandbox Hosted Tokenization key (starts with "pk_"). Please use your live key for production.', 'frm-acceptblue-lite' ); ?>
							</p>
						<?php elseif ( ! $tok_key ) : ?>
							<p style="color:#b91c1c;font-weight:600;margin-top:6px;">
								&#9888; <?php esc_html_e( 'Required: Enter your Hosted Tokenization key or the card payment field will show a 401 error.', 'frm-acceptblue-lite' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( '3DS (Paay) API Key', 'frm-acceptblue-lite' ); ?></th>
					<td>
						<p class="description">
							<?php esc_html_e( 'Available in the Pro version.', 'frm-acceptblue-lite' ); ?>
							<a href="https://www.patreon.com/posts/formidable-blue-157799373?source=lite" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro &rarr;', 'frm-acceptblue-lite' ); ?></a>
						</p>
					</td>
				</tr>
				<?php echo '</tbody><tbody>'; ?>

				<tr>
					<th scope="row"><?php esc_html_e( 'Connection Test', 'frm-acceptblue-lite' ); ?></th>
					<td>
						<?php if ( ! empty( $settings['api_key'] ) ) : ?>
							<button type="button" id="frm_ab_lite_test_connection" class="button button-secondary">
								<?php esc_html_e( 'Test Connection', 'frm-acceptblue-lite' ); ?>
							</button>
							<span id="frm_ab_lite_test_result" style="margin-left:10px;"></span>
						<?php else : ?>
							<span style="color:#999;"><?php esc_html_e( 'Enter your API key and save to test the connection.', 'frm-acceptblue-lite' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>

				<?php echo '<tbody>'; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook URL', 'frm-acceptblue-lite' ); ?></th>
					<td>
						<p class="description">
							<?php esc_html_e( 'Available in the Pro version.', 'frm-acceptblue-lite' ); ?>
							<a href="https://www.patreon.com/posts/formidable-blue-157799373?source=lite" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro &rarr;', 'frm-acceptblue-lite' ); ?></a>
						</p>
					</td>
				</tr>
				<?php echo '</tbody><tbody></tbody>'; ?>
			</table>

			<hr style="margin:28px 0 20px;">

			<?php self::render_license_tab(); ?>

		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// License Tab
	// -------------------------------------------------------------------------

	public static function render_license_tab() { /* Lite — licensing removed */ }

	// -------------------------------------------------------------------------
	// Save
	// -------------------------------------------------------------------------

	public static function process_form() {
		self::save_settings();
	}

	public static function save_settings() {
		if ( ! isset( $_POST['frm_ab_lite_settings'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$raw = isset( $_POST['frm_ab_lite_settings'] ) ? wp_unslash( $_POST['frm_ab_lite_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$settings = array(
			'api_key'          => sanitize_text_field( isset( $raw['api_key'] )          ? $raw['api_key']          : '' ),
			'pin'              => sanitize_text_field( isset( $raw['pin'] )              ? $raw['pin']              : '' ),
			'paay_api_key'     => sanitize_text_field( isset( $raw['paay_api_key'] )     ? $raw['paay_api_key']     : '' ),
			'tokenization_key' => sanitize_text_field( isset( $raw['tokenization_key'] ) ? $raw['tokenization_key'] : '' ),
			'webhook_token'    => sanitize_text_field( isset( $raw['webhook_token'] )    ? $raw['webhook_token']    : '' ),
			'test_mode'        => ! empty( $raw['test_mode'] ) ? 1 : 0,
			'debug_log'        => ! empty( $raw['debug_log'] ) ? 1 : 0,
		);

		update_option( self::OPTION_KEY, $settings, false );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get all plugin settings with safe defaults.
	 */
	public static function get_settings() {
		$defaults = array(
			'api_key'          => '',
			'pin'              => '',
			'paay_api_key'     => '',
			'tokenization_key' => '',
			'webhook_token'    => '',
			'test_mode'        => 1,
			'debug_log'        => 0,
		);
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Quick helper — true when debug logging is enabled.
	 */
	public static function is_debug_enabled(): bool {
		$s = self::get_settings();
		return ! empty( $s['debug_log'] );
	}

	/**
	 * Build and return an Frm_AB_Lite_API instance using saved credentials.
	 * Returns null if no API key has been configured yet.
	 */
	public static function get_api(): ?Frm_AB_Lite_API {
		$settings = self::get_settings();
		if ( empty( $settings['api_key'] ) ) {
			return null;
		}
		return new Frm_AB_Lite_API(
			trim( $settings['api_key'] ),
			trim( $settings['pin'] ),
			(bool) $settings['test_mode']
		);
	}

	/**
	 * AJAX: test the API connection with current saved credentials.
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'frm_ab_lite_test', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'frm-acceptblue-lite' ) );
		}

		$settings = self::get_settings();

		if ( empty( $settings['api_key'] ) ) {
			wp_send_json_error( __( 'API key is not configured.', 'frm-acceptblue-lite' ) );
		}

		$api_key   = trim( $settings['api_key'] );
		$pin       = trim( $settings['pin'] );
		$test_mode = ! empty( $settings['test_mode'] );

		// Route to the correct endpoint based on test mode
		$base_url = $test_mode
			? Frm_AB_Lite_API::SANDBOX_URL
			: Frm_AB_Lite_API::LIVE_URL;

		$response = wp_remote_get(
			$base_url . 'transactions?limit=1',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $pin ),
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'User-Agent'    => Frm_AB_Lite_API::USER_AGENT . '; WordPress/' . get_bloginfo( 'version' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( __( 'Network error: ', 'frm-acceptblue-lite' ) . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		if ( $code === 200 || $code === 206 ) {
			$mode_label = $test_mode ? __( 'Sandbox', 'frm-acceptblue-lite' ) : __( 'Production', 'frm-acceptblue-lite' );
			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: 1: mode (Sandbox/Production), 2: HTTP status code */
					__( 'Connected to accept.blue %1$s successfully (HTTP %2$d).', 'frm-acceptblue-lite' ),
					$mode_label,
					$code
				),
			) );
		}

		if ( $code === 401 || $code === 403 ) {
			$detail = isset( $body['message'] ) ? $body['message'] : $raw;
			$hint   = $test_mode
				? __( ' Tip: Test Mode is ON — make sure you are using a Sandbox API key.', 'frm-acceptblue-lite' )
				: __( ' Tip: Test Mode is OFF — make sure you are using a Production API key.', 'frm-acceptblue-lite' );
			wp_send_json_error(
				sprintf( __( 'Authentication failed (HTTP %d): %s', 'frm-acceptblue-lite' ), $code, $detail ) . $hint
			);
		}

		$msg = isset( $body['message'] ) ? $body['message']
			: ( isset( $body['error'] ) ? $body['error'] : "HTTP {$code}: {$raw}" );
		wp_send_json_error( $msg );
	}
}

Frm_AB_Lite_Settings::init();
