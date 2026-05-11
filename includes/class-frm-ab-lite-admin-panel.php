<?php
/**
 * Accept.Blue Admin Transactions Panel
 *
 * Adds a "Accept.Blue" submenu under Formidable with a filterable
 * transaction list, stats strip, refund button, sync, and CSV export.
 *
 * Gracefully handles the case where wp_frm_payments does not exist
 * (Formidable Lite — Pro required for the payments table).
 *
 * @package FrmAcceptBlue
 */

defined( 'ABSPATH' ) || exit;

class Frm_AB_Lite_Admin_Panel {

	public static function init() {
		add_action( 'admin_menu',            array( __CLASS__, 'register_menu' ), 30 );
		add_filter( 'plugin_row_meta',       array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'admin_notices',         array( __CLASS__, 'maybe_show_token_notice' ) );
		add_action( 'admin_notices',         array( __CLASS__, 'maybe_show_pro_notice' ) );
		add_action( 'wp_ajax_frm_ab_lite_sync_transaction',           array( __CLASS__, 'ajax_sync_transaction' ) );
		add_action( 'wp_ajax_frm_ab_lite_regenerate_webhook_token',   array( __CLASS__, 'ajax_regenerate_webhook_token' ) );
		add_action( 'wp_ajax_frm_ab_lite_refresh_schedules',          array( __CLASS__, 'ajax_refresh_schedules' ) );
		add_action( 'wp_ajax_frm_ab_lite_capture_transaction',        array( __CLASS__, 'ajax_capture_transaction' ) );
		add_action( 'wp_ajax_frm_ab_lite_adjust_capture_transaction', array( __CLASS__, 'ajax_adjust_capture_transaction' ) );
		add_action( 'wp_ajax_frm_ab_lite_void_transaction',           array( __CLASS__, 'ajax_void_transaction' ) );
		add_action( 'wp_ajax_frm_ab_lite_refund_transaction',         array( __CLASS__, 'ajax_refund_transaction' ) );
		add_action( 'wp_ajax_frm_ab_lite_export_csv',                 array( __CLASS__, 'ajax_export_csv' ) );
		add_action( 'wp_ajax_frm_ab_lite_dismiss_pro_notice',         array( __CLASS__, 'ajax_dismiss_pro_notice' ) );
	}

	/**
	 * Show a success notice after webhook token regeneration.
	 */
	public static function maybe_show_token_notice() {
		if ( isset( $_GET['frm_action'] ) && 'acceptblue_token_regenerated' === $_GET['frm_action'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'Webhook token regenerated. Copy the new URL from the Accept.Blue settings and update it in your accept.blue portal.', 'frm-acceptblue-lite' );
			echo '</p></div>';
		}
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public static function register_menu() {
		add_submenu_page(
			'formidable',
			__( 'Accept.Blue Transactions', 'frm-acceptblue-lite' ),
			__( 'Accept.Blue', 'frm-acceptblue-lite' ),
			'manage_options',
			'frm-ab-lite-transactions',
			array( __CLASS__, 'render_page' )
		);
		add_submenu_page(
			'formidable',
			__( 'Accept.Blue Schedules', 'frm-acceptblue-lite' ),
			__( 'AB Schedules', 'frm-acceptblue-lite' ),
			'manage_options',
			'frm-ab-lite-schedules',
			array( __CLASS__, 'render_schedules_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Check whether the frm_payments table exists (requires Formidable Pro).
	 */
	private static function payments_table_exists(): bool {
		global $wpdb;
		$our = $wpdb->prefix . 'frm_ab_lite_payments';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $our ) ) === $our ) return true;
		$frm = $wpdb->prefix . 'frm_payments';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $frm ) ) === $frm;
	}

	private static function get_payments_table(): string {
		global $wpdb;
		$our = $wpdb->prefix . 'frm_ab_lite_payments';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $our ) ) === $our ) return $our;
		return $wpdb->prefix . 'frm_payments';
	}

	// -------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------

	public static function render_page() {
		// Pro feature — show static overlay, no DB queries
		if ( isset( $_GET['frm_ab_lite_view_log'] ) && class_exists( 'Frm_AB_Lite_Logger' ) ) {
			echo '<div class="wrap frm-ab-lite-admin-panel">';
			self::render_log_viewer();
			echo '</div>';
			return;
		}
		$icon = esc_url( FRM_AB_LITE_URL . 'assets/accept-blue-icon.svg' );
		?>
		<style>
.frm-ab-lite-pro-page-banner{display:flex;align-items:center;gap:14px;background:linear-gradient(135deg,#1d2327,#2c3a47);color:#fff;padding:14px 20px;border-radius:8px;margin:16px 0 20px;box-shadow:0 3px 12px rgba(0,0,0,.18);font-size:13.5px;line-height:1.5}
.frm-ab-lite-pro-page-banner a{color:#7dd3fc;font-weight:700;text-decoration:none}
.frm-ab-lite-mock-blur{filter:blur(4px);opacity:.5;pointer-events:none;user-select:none}
.frm-ab-lite-pro-wrap{position:relative}
.frm-ab-lite-pro-wrap-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;z-index:10;background:rgba(255,255,255,.3);border-radius:6px}
.frm-ab-lite-pro-badge-lg{background:linear-gradient(135deg,#1d2327,#2c3a47);color:#fff;padding:14px 34px;border-radius:32px;font-weight:700;font-size:15px;box-shadow:0 6px 24px rgba(0,0,0,.28);text-align:center;line-height:1.6}
.frm-ab-lite-pro-badge-lg small{display:block;font-size:11px;font-weight:400;opacity:.8;margin-top:3px}
</style>
		<div class="wrap frm-ab-lite-admin-panel">
			<h1 style="display:inline-flex;align-items:center;gap:10px;">
				<img src="<?php echo esc_url( $icon ); ?>" style="width:28px;height:28px;border-radius:5px;" alt="">
				<?php esc_html_e( 'accept.blue — Transactions', 'frm-acceptblue-lite' ); ?>
			</h1>
			<hr class="wp-header-end">

			<div class="frm-ab-lite-pro-page-banner">
				<span style="font-size:22px;flex-shrink:0;">&#128274;</span>
				<span>
					<strong>Transactions Panel — Pro Feature</strong><br>
					View all payments, filter by status, export CSV, and sync transaction data.
					<a href="https://www.patreon.com/posts/formidable-blue-157799373?source=lite" target="_blank" rel="noopener">&#8599; Upgrade to Pro</a> to unlock.
				</span>
			</div>

			<div class="frm-ab-lite-pro-wrap">
				<div class="frm-ab-lite-pro-wrap-overlay">
					<div class="frm-ab-lite-pro-badge-lg">&#128274; Pro Version<small>Upgrade to unlock the Transactions Panel</small></div>
				</div>
				<div class="frm-ab-lite-mock-blur">
					<div style="display:flex;gap:16px;margin-bottom:18px;flex-wrap:wrap;">
						<?php foreach ( ['Successful (30d)' => '12', 'Revenue (30d)' => '$1,240.00', 'Failed (30d)' => '1', 'Refunded (30d)' => '0'] as $label => $val ) : ?>
						<div style="flex:1;min-width:140px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px;text-align:center;">
							<div style="font-size:26px;font-weight:700;color:#1a4a7a;"><?php echo esc_html( $val ); ?></div>
							<div style="font-size:12px;color:#6b7280;margin-top:4px;"><?php echo esc_html( $label ); ?></div>
						</div>
						<?php endforeach; ?>
					</div>
					<table class="wp-list-table widefat fixed striped">
						<thead><tr>
							<?php foreach ( ['ID','Date','Entry','Form','Amount','Status','Charge ID','Actions'] as $h ) : ?>
								<th style="background:#1a4a7a;color:#fff;padding:10px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;"><?php echo esc_html( $h ); ?></th>
							<?php endforeach; ?>
						</tr></thead>
						<tbody>
						<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<tr>
							<td><?php echo esc_html( $i ); ?></td>
							<td>2026-05-0<?php echo esc_html( $i ); ?></td>
							<td>#<?php echo 1000 + $i; ?></td>
							<td>Payment Form</td>
							<td>$<?php echo esc_html( $i * 10 ); ?>.00</td>
							<td><span style="background:#dcfce7;color:#166534;border-radius:99px;padding:2px 10px;font-size:12px;">complete</span></td>
							<td>ch_xxxxx<?php echo esc_html( $i ); ?></td>
							<td><button class="button button-small">Refund</button></td>
						</tr>
						<?php endfor; ?>
						</tbody>
					</table>
				</div><!-- /.mock-blur -->
			</div><!-- /.pro-wrap -->
		</div><!-- /.wrap -->
		<?php
	}

	
	public static function enqueue_scripts( $hook ) {
		$is_panel     = strpos( $hook, 'frm-ab-lite-transactions' ) !== false;
		$is_schedules = strpos( $hook, 'frm-ab-lite-schedules' ) !== false;
		$is_formidable = strpos( $hook, 'formidable' ) !== false
			|| strpos( $hook, 'frm_' ) !== false
			|| $hook === 'toplevel_page_formidable'
			|| strpos( $hook, 'page_formidable' ) !== false;

		if ( ! $is_panel && ! $is_schedules && ! $is_formidable ) return;

		// ── Register a shared admin handle for all plugin inline JS ──────────
		wp_register_script(
			'frm-acceptblue-lite-admin',
			false,  // no external file — inline only
			array( 'jquery' ),
			FRM_AB_LITE_VERSION,
			true    // footer
		);
		wp_enqueue_script( 'frm-acceptblue-lite-admin' );

		// ── All admin CSS via wp_add_inline_style ─────────────────────────────
		wp_add_inline_style( 'wp-admin', self::admin_css() );

		// License section CSS (settings page)
		wp_add_inline_style( 'wp-admin', self::license_css() );

		// ── Transactions panel JS ─────────────────────────────────────────────
		if ( $is_panel || $is_schedules ) {
			$panel_data = array(
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'exportNonce'         => wp_create_nonce( 'frm_ab_lite_export' ),
				'refreshNonce'        => wp_create_nonce( 'frm_ab_lite_refresh_schedules' ),
				'refundPrompt'        => __( 'Refund amount (leave blank for full refund):', 'frm-acceptblue-lite' ),
				'captureConfirm'      => __( 'Capture the full authorised amount?', 'frm-acceptblue-lite' ),
				'adjustCapturePrompt' => __( 'Enter new amount to adjust and capture (required):', 'frm-acceptblue-lite' ),
				'voidConfirm'         => __( 'Void this transaction? This cannot be undone.', 'frm-acceptblue-lite' ),
				'refreshing'          => __( 'Refreshing...', 'frm-acceptblue-lite' ),
			);
			wp_add_inline_script( 'frm-acceptblue-lite-admin', 'var frmAbPanel = ' . wp_json_encode( $panel_data ) . ';' );
			wp_add_inline_script( 'frm-acceptblue-lite-admin', self::panel_js() );

			// CSV export POST form script
			wp_add_inline_script( 'frm-acceptblue-lite-admin', self::csv_export_js() );

			// Dashboard export POST form script (Formidable payments dashboard)
			wp_add_inline_script( 'frm-acceptblue-lite-admin', self::dash_export_js() );

			// Schedules refresh script
			if ( $is_schedules ) {
				wp_add_inline_script( 'frm-acceptblue-lite-admin', self::schedules_refresh_js() );
			}
		}

		// ── Settings/Formidable admin pages ───────────────────────────────────
		if ( $is_formidable ) {
			// Copy Webhook URL button
			wp_add_inline_script( 'frm-acceptblue-lite-admin', self::copy_webhook_url_js() );

			// Test connection script
			$test_data = array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'frm_ab_lite_test' ),
				'testing'          => __( 'Testing...', 'frm-acceptblue-lite' ),
				'connectionFailed' => __( 'Connection failed.', 'frm-acceptblue-lite' ),
			);
			wp_add_inline_script( 'frm-acceptblue-lite-admin', 'var frmAbSettings = ' . wp_json_encode( $test_data ) . ';' );
			wp_add_inline_script( 'frm-acceptblue-lite-admin', self::test_connection_js() );

			// License section script
			$lic_data = array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'enterCode'          => __( 'Please enter your purchase code.', 'frm-acceptblue-lite' ),
				'verifying'          => __( 'Verifying...', 'frm-acceptblue-lite' ),
				'verifyBtn'          => __( 'Verify License', 'frm-acceptblue-lite' ),
				'verifyShort'        => __( 'Verify', 'frm-acceptblue-lite' ),
				'networkError'       => __( 'Network error. Please check your connection and try again.', 'frm-acceptblue-lite' ),
				'verifyFailed'       => __( 'Verification failed. Please check your purchase code and try again.', 'frm-acceptblue-lite' ),
				'deactivating'       => __( 'Deactivating...', 'frm-acceptblue-lite' ),
				'deactivateBtn'      => __( 'Deactivate License', 'frm-acceptblue-lite' ),
				'deactivateConfirm'  => __( 'Are you sure you want to deactivate this license?', 'frm-acceptblue-lite' ),
			);
			wp_add_inline_script( 'frm-acceptblue-lite-admin', 'var frmAbLicense = ' . wp_json_encode( $lic_data ) . ';' );
			wp_add_inline_script( 'frm-acceptblue-lite-admin', self::license_js() );

			// Form action / recurring toggle JS
			wp_add_inline_script( 'frm-acceptblue-lite-admin', self::form_action_js() );
		}
	}

	private static function panel_js() {
		return <<<'JS'
( function() {
	var ajaxUrl = frmAbPanel.ajaxUrl;
	var actionMap = {
		"sync":           "frm_ab_lite_sync_transaction",
		"capture":        "frm_ab_lite_capture_transaction",
		"adjust_capture": "frm_ab_lite_adjust_capture_transaction",
		"void":           "frm_ab_lite_void_transaction",
		"refund":         "frm_ab_lite_refund_transaction"
	};

	function wireButtons() {
		document.querySelectorAll( ".frm-ab-lite-action-btn" ).forEach( function( btn ) {
			if ( btn._wired ) return;
			btn._wired = true;
			btn.addEventListener( "click", function() {
				var action = btn.dataset.action;
				var amount = "";

				if ( action === "refund" ) {
					amount = prompt( frmAbPanel.refundPrompt, btn.dataset.amount || "" );
					if ( amount === null ) return;
				} else if ( action === "capture" ) {
					if ( !confirm( frmAbPanel.captureConfirm ) ) return;
					// No amount sent — backend captures the full authorised amount
				} else if ( action === "adjust_capture" ) {
					amount = prompt( frmAbPanel.adjustCapturePrompt, btn.dataset.amount || "" );
					if ( amount === null || amount === "" ) return;
				} else if ( action === "void" ) {
					if ( !confirm( frmAbPanel.voidConfirm ) ) return;
				}

				var origText = btn.textContent;
				var origIcon = btn.innerHTML;
				btn.disabled  = true;
				btn.innerHTML = "<span style=\"opacity:.5\">&#x21BB;</span>";

				var fd = new FormData();
				fd.append( "action",     actionMap[ action ] );
				fd.append( "nonce",      btn.dataset.nonce );
				fd.append( "payment_id", btn.dataset.id );
				fd.append( "charge_id",  btn.dataset.charge );
				if ( amount !== "" ) fd.append( "amount", amount );

				function resetBtn() {
					btn.disabled  = false;
					btn.innerHTML = origIcon;
				}

				fetch( ajaxUrl, { method: "POST", body: fd } )
					.then( function(r) { return r.json(); } )
					.then( function(r) {
						if ( r.success ) {
							// Show message then reload — reset not needed since page reloads
							if ( r.data && r.data.message ) {
								alert( r.data.message );
							}
							location.reload();
						} else {
							resetBtn();
							var msg = ( r.data && r.data.message ) ? r.data.message : ( r.data || "Action failed." );
							alert( "Error: " + msg );
						}
					} )
					.catch( function(e) {
						resetBtn();
						alert( "Request failed: " + e.message );
					} );
			} );
		} );
	}

	if ( document.readyState === "loading" ) {
		document.addEventListener( "DOMContentLoaded", wireButtons );
	} else {
		wireButtons();
	}
} )();
JS;
	}

	private static function copy_webhook_url_js(): string {
		return <<<'JS'
( function() {
	var btn = document.getElementById( 'frm_ab_lite_copy_webhook_url' );
	if ( ! btn ) return;
	btn.addEventListener( 'click', function() {
		var url = btn.getAttribute( 'data-url' );
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( url ).then( function() {
				var orig = btn.textContent;
				btn.textContent = '✓ Copied!';
				setTimeout( function() { btn.textContent = orig; }, 2000 );
			} );
		} else {
			var inp = document.getElementById( 'frm_ab_lite_webhook_url_display' );
			if ( inp ) { inp.select(); document.execCommand( 'copy' ); }
		}
	} );
} )();
JS;
	}

	private static function csv_export_js(): string {
		return <<<'JS'
( function() {
	var btn = document.getElementById( 'frm-ab-lite-export-csv-btn' );
	if ( ! btn ) return;
	btn.addEventListener( 'click', function() {
		var form = document.createElement( 'form' );
		form.method = 'POST';
		form.action = frmAbPanel.ajaxUrl;
		var fields = { action: 'frm_ab_lite_export_csv', nonce: frmAbPanel.exportNonce };
		Object.keys( fields ).forEach( function( k ) {
			var i = document.createElement( 'input' );
			i.type = 'hidden'; i.name = k; i.value = fields[k];
			form.appendChild( i );
		} );
		document.body.appendChild( form );
		form.submit();
		document.body.removeChild( form );
	} );
} )();
JS;
	}

	private static function dash_export_js(): string {
		return <<<'JS'
( function() {
	var btn = document.getElementById( 'frm-ab-lite-dash-export-btn' );
	if ( ! btn ) return;
	btn.addEventListener( 'click', function() {
		var form = document.createElement( 'form' );
		form.method = 'POST';
		form.action = frmAbPanel.ajaxUrl;
		var fields = { action: 'frm_ab_lite_export_csv', nonce: frmAbPanel.exportNonce };
		Object.keys( fields ).forEach( function( k ) {
			var i = document.createElement( 'input' );
			i.type = 'hidden'; i.name = k; i.value = fields[k];
			form.appendChild( i );
		} );
		document.body.appendChild( form );
		form.submit();
		document.body.removeChild( form );
	} );
} )();
JS;
	}

	private static function schedules_refresh_js(): string {
		return <<<'JS'
( function() {
	var btn = document.getElementById( 'frm-ab-lite-refresh-schedules' );
	if ( ! btn ) return;
	btn.addEventListener( 'click', function() {
		btn.disabled = true;
		btn.textContent = frmAbPanel.refreshing;
		location.reload();
	} );
} )();
JS;
	}

	private static function test_connection_js(): string {
		return <<<'JS'
( function() {
	var el = document.getElementById( 'frm_ab_lite_test_connection' );
	if ( ! el ) return;
	el.addEventListener( 'click', function() {
		var btn    = this;
		var result = document.getElementById( 'frm_ab_lite_test_result' );
		btn.disabled       = true;
		result.textContent = frmAbSettings.testing;
		result.style.color = '#666';
		var data = new FormData();
		data.append( 'action', 'frm_ab_lite_test_connection' );
		data.append( 'nonce',  frmAbSettings.nonce );
		fetch( frmAbSettings.ajaxUrl, { method: 'POST', body: data } )
			.then( function( r ) { if ( ! r.ok ) throw new Error( 'HTTP ' + r.status ); return r.json(); } )
			.then( function( r ) {
				btn.disabled = false;
				if ( r.success ) {
					result.textContent = 'OK ' + r.data.message;
					result.style.color = '#27ae60';
				} else {
					result.textContent = 'X ' + ( r.data || frmAbSettings.connectionFailed );
					result.style.color = '#c0392b';
				}
			} )
			.catch( function( err ) {
				btn.disabled = false;
				result.textContent = 'X ' + err.message;
				result.style.color = '#c0392b';
			} );
	} );
} )();
JS;
	}

	private static function license_js(): string { return ''; /* Lite */ 
}

private static function form_action_js(): string {
		return <<<'JS'
( function() {

	/* ─── helpers: always query the live DOM by ID ─────────────────────────── */

	function applyThreeDs() {
		var cb  = document.getElementById( 'frm_ab_lite_three_ds_enabled' );
		var row = document.getElementById( 'frm_ab_lite_row_3ds_options' );
		if ( ! cb || ! row ) return;
		row.style.display = cb.checked ? '' : 'none';
	}

	function applyIframeStyle() {
		var sel  = document.getElementById( 'frm_ab_lite_iframe_style_select' );
		var wrap = document.getElementById( 'frm_ab_lite_custom_style_wrap' );
		if ( ! sel || ! wrap ) return;
		wrap.style.display = ( sel.value === 'custom' ) ? '' : 'none';
	}

	function applyScheduleType() {
		var sel      = document.getElementById( 'frm_ab_lite_schedule_type' );
		var rowInst  = document.getElementById( 'frm_ab_lite_row_installment_count' );
		var rowSubD  = document.getElementById( 'frm_ab_lite_row_sub_duration' );
		if ( ! sel ) return;
		var isInstall = sel.value === 'installment';
		if ( rowInst ) rowInst.style.display = isInstall ? '' : 'none';
		if ( rowSubD ) rowSubD.style.display = isInstall ? 'none' : '';
		// Sync disabled to prevent hidden inputs from failing HTML5 validation
		var inputInst = document.getElementById( 'frm_ab_lite_installment_count' );
		var inputSubD = document.getElementById( 'frm_ab_lite_recurring_duration' );
		if ( inputInst ) inputInst.disabled = ! isInstall;
		if ( inputSubD ) inputSubD.disabled = isInstall;
	}

	function applyTrialType() {
		var sel          = document.getElementById( 'frm_ab_lite_trial_period_type' );
		var rowTrialDays = document.getElementById( 'frm_ab_lite_trial_days_row' );
		var inputDays    = document.getElementById( 'frm_ab_lite_trial_days' );
		if ( ! sel || ! rowTrialDays ) return;
		var isDays = sel.value === 'days';
		rowTrialDays.style.display = isDays ? '' : 'none';
		if ( inputDays ) inputDays.disabled = ! isDays;
	}

	function applyRecurring() {
		var cb = document.getElementById( 'frm_ab_lite_rec_enabled' );
		if ( ! cb ) return;
		var on = cb.checked;
		document.querySelectorAll( '.frm-ab-lite-rec-row' ).forEach( function( r ) {
			r.style.display = on ? '' : 'none';
		} );
		if ( on ) {
			applyScheduleType();
			applyTrialType();
		} else {
			// Disable all number inputs inside rec-rows so hidden fields
			// never fail HTML5 min/max validation on form submit
			document.querySelectorAll( '.frm-ab-lite-rec-row input[type="number"]' ).forEach( function( inp ) {
				inp.disabled = true;
			} );
		}
	}

	/* ─── event delegation on document ─────────────────────────────────────── *
	 * Binds once at the document level — works no matter when Formidable        *
	 * lazy-renders the action content. No inline onchange in PHP templates.    */
	document.addEventListener( 'change', function( e ) {
		var id = e.target && e.target.id;
		if ( id === 'frm_ab_lite_rec_enabled' ) {
			applyRecurring();
		} else if ( id === 'frm_ab_lite_schedule_type' ) {
			applyScheduleType();
		} else if ( id === 'frm_ab_lite_trial_period_type' ) {
			applyTrialType();
		} else if ( id === 'frm_ab_lite_three_ds_enabled' ) {
			applyThreeDs();
		} else if ( id === 'frm_ab_lite_iframe_style_select' ) {
			applyIframeStyle();
		}
	} );

	/* ─── initial state ─────────────────────────────────────────────────────── *
	 * Run applyRecurring() as soon as the checkbox appears in the DOM,          *
	 * whether already present or lazy-rendered by Formidable later.            */
	function maybeInit( root ) {
		if ( ! root || ! root.querySelector ) return;
		if ( root.querySelector( '#frm_ab_lite_rec_enabled' ) ) {
			applyRecurring();
		}
		if ( root.querySelector( '#frm_ab_lite_three_ds_enabled' ) ) {
			applyThreeDs();
		}
		if ( root.querySelector( '#frm_ab_lite_iframe_style_select' ) ) {
			applyIframeStyle();
		}
	}

	maybeInit( document );

	new MutationObserver( function( mutations ) {
		mutations.forEach( function( m ) {
			m.addedNodes.forEach( function( node ) {
				if ( node.nodeType !== 1 ) return;
				if ( node.id === 'frm_ab_lite_rec_enabled' ) {
					applyRecurring();
				} else if ( node.id === 'frm_ab_lite_three_ds_enabled' ) {
					applyThreeDs();
				} else if ( node.id === 'frm_ab_lite_iframe_style_select' ) {
					applyIframeStyle();
				} else {
					maybeInit( node );
				}
			} );
		} );
	} ).observe( document.body, { childList: true, subtree: true } );

} )();
JS;
	}

	private static function license_css(): string { return ''; /* Lite */ }

private static function admin_css() {
		return '
		/* ── Accept.Blue action icon ── */
		/* 1. Picker row (top list) — li has data-actiontype="acceptblue" */
		li[data-actiontype="acceptblue"] .frm-outer-circle {
			background-color: #279EDA !important;
			background-image: url("' . esc_url( FRM_AB_LITE_URL . 'assets/accept-blue-icon.svg' ) . '") !important;
			background-repeat: no-repeat !important;
			background-position: center center !important;
			background-size: 65% auto !important;
		}
		li[data-actiontype="acceptblue"] .frm-outer-circle i { display: none !important; }
		li[data-actiontype="acceptblue"] .frm-inner-circle {
			background-color: #279EDA !important;
			background-image: url("' . esc_url( FRM_AB_LITE_URL . 'assets/accept-blue-icon.svg' ) . '") !important;
			background-repeat: no-repeat !important;
			background-position: center center !important;
			background-size: 65% auto !important;
		}
		li[data-actiontype="acceptblue"] .frm-inner-circle i { display: none !important; }
		/* 2. Active action widget title (bottom list) — container has frm_single_acceptblue_settings */
		.frm_single_acceptblue_settings .widget-title .frm_form_action_icon,
		.frm_acceptblue_action .frm-outer-circle > span {
			background-color: #279EDA !important;
			background-image: url("' . esc_url( FRM_AB_LITE_URL . 'assets/accept-blue-icon.svg' ) . '") !important;
			background-repeat: no-repeat !important;
			background-position: center center !important;
			background-size: 65% auto !important;
		}
		.frm_single_acceptblue_settings .widget-title .frm_form_action_icon i,
		.frm_acceptblue_action .frm-outer-circle > span i { display: none !important; }
		.frm_single_acceptblue_settings .widget-title .frm-inner-circle {
			background-color: transparent !important;
			background-image: none !important;
		}

		/* Page header */
		.frm-ab-lite-admin-panel h1.wp-heading-inline { font-size:1.5em; font-weight:700; color:#1a4a7a; margin-right:10px; }
		.frm-ab-lite-admin-panel .page-title-action { border-radius:6px; font-size:12px; font-weight:600; padding:4px 12px; height:auto; line-height:1.8; }

		/* Stats strip */
		.frm-ab-lite-stats-strip { display:flex; gap:14px; margin:18px 0 20px; flex-wrap:wrap; }
		.frm-ab-lite-stat { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:18px 24px; min-width:150px; text-align:center; box-shadow:0 1px 4px rgba(0,0,0,.05); border-top:3px solid #d1d5db; flex:1; }
		.frm-ab-lite-stat strong { display:block; font-size:1.9em; font-weight:800; line-height:1.2; color:#1a4a7a; }
		.frm-ab-lite-stat span { color:#6b7280; font-size:.82em; font-weight:500; display:block; margin-top:3px; }
		.frm-ab-lite-stat--revenue { border-top-color:#279EDA; }
		.frm-ab-lite-stat--revenue strong { color:#059669; }
		.frm-ab-lite-stat--failed { border-top-color:#ef4444; }
		.frm-ab-lite-stat--failed strong { color:#dc2626; }

		/* Filters bar */
		.frm-ab-lite-filters { display:flex; gap:8px; align-items:center; margin:0 0 16px; flex-wrap:wrap; background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:12px 16px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
		.frm-ab-lite-filters select, .frm-ab-lite-filters input[type="search"] { height:32px; border-radius:6px; border:1px solid #d1d5db; padding:0 10px; font-size:13px; box-shadow:none; }
		.frm-ab-lite-filters input[type="search"] { width:220px; }
		.frm-ab-lite-filters .button-secondary { height:32px; line-height:30px; border-radius:6px; font-weight:600; font-size:13px; }

		/* Table */
		.frm-ab-lite-table { border-radius:10px; border:1px solid #e5e7eb !important; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.06); border-collapse:separate !important; border-spacing:0 !important; }
		.frm-ab-lite-table thead tr th { background:#1a4a7a; color:#fff; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; padding:12px 14px; border-bottom:none !important; }
		.frm-ab-lite-table tbody tr td { padding:11px 14px; vertical-align:middle; border-bottom:1px solid #f3f4f6; font-size:13px; color:#374151; }
		.frm-ab-lite-table tbody tr:last-child td { border-bottom:none; }
		.frm-ab-lite-table tbody tr:hover td { background:#f0f7ff !important; }
		.frm-ab-lite-table.striped tbody tr:nth-child(odd) td { background:#fafafa; }
		.frm-ab-lite-table.striped tbody tr:nth-child(odd):hover td { background:#f0f7ff !important; }
		.frm-ab-lite-table td strong { color:#111827; font-weight:700; }
		.frm-ab-lite-table code { font-size:.8em; background:#f1f5f9; color:#1a4a7a; padding:2px 7px; border-radius:4px; font-weight:600; letter-spacing:.02em; }
		.frm-ab-lite-table td a { color:#1a4a7a; font-weight:600; text-decoration:none; }
		.frm-ab-lite-table td a:hover { text-decoration:underline; color:#1e5a9a; }

		/* Actions cell */
		.frm-ab-lite-actions-cell { white-space:nowrap; display:flex; gap:5px; flex-wrap:wrap; align-items:center; }
		.frm-ab-lite-actions-cell .button { border-radius:5px; font-size:11px; font-weight:600; height:26px; line-height:24px; padding:0 9px; }
		.frm-ab-lite-action-btn[data-action="sync"] { color:#6b7280 !important; border-color:#d1d5db !important; font-size:14px !important; padding:0 7px !important; }
		.frm-ab-lite-btn-danger { color:#dc2626 !important; border-color:#fca5a5 !important; }
		.frm-ab-lite-btn-danger:hover { background:#dc2626 !important; color:#fff !important; border-color:#dc2626 !important; }

		/* Pagination */
		.frm-ab-lite-admin-panel .tablenav.bottom,
		.frm-ab-lite-schedules-page .tablenav.bottom { margin-top:12px; }
		.frm-ab-lite-admin-panel .tablenav-pages a,
		.frm-ab-lite-admin-panel .tablenav-pages span.current,
		.frm-ab-lite-schedules-page .tablenav-pages a,
		.frm-ab-lite-schedules-page .tablenav-pages span.current { border-radius:5px; font-weight:600; font-size:12px; }

		/* Schedules page */
		.frm-ab-lite-schedules-page h1 { font-size:1.5em; font-weight:700; color:#1a4a7a; display:flex; align-items:center; gap:10px; margin-bottom:4px; }
		.frm-ab-lite-schedules-page h1 img { width:28px; height:28px; border-radius:5px; }
		.frm-ab-lite-schedules-page .frm-ab-lite-sched-subtitle { color:#6b7280; margin-top:0; margin-bottom:0; font-size:13px; }
		.frm-ab-lite-sched-refresh { font-size:13px !important; font-weight:600 !important; border-radius:6px !important; height:32px !important; line-height:30px !important; padding:0 14px !important; margin-left:6px !important; }

		/* Schedules table */
		.frm-ab-lite-schedules-page .frm-ab-lite-table { margin-top:0; }
		.frm-ab-lite-schedules-page .frm-ab-lite-table thead tr th { background:#1a4a7a; color:#fff; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; padding:12px 14px; border-bottom:none !important; }
		.frm-ab-lite-schedules-page .frm-ab-lite-table tbody tr td { padding:11px 14px; vertical-align:middle; }
		.frm-ab-lite-sched-id { font-family:monospace; font-size:12px; color:#6b7280; }
		.frm-ab-lite-sched-title { font-weight:600; color:#111827; }
		.frm-ab-lite-sched-amount { font-weight:700; color:#1a4a7a; }
		.frm-ab-lite-sched-date { font-size:12px; color:#6b7280; }
		.frm-ab-lite-sched-remaining { text-align:center; font-weight:600; color:#374151; }
		.frm-ab-lite-sched-days { color:#6b7280; font-size:11px; margin-left:3px; }
		';
	}

	// -------------------------------------------------------------------------
	// AJAX: Sync
	// -------------------------------------------------------------------------

	public static function ajax_sync_transaction() {
		wp_send_json_error( __( 'Sync is available in the Pro version.', 'frm-acceptblue-lite' ) );
		return; // Lite
	}


	// -------------------------------------------------------------------------
	// AJAX: Regenerate Webhook Token
	// -------------------------------------------------------------------------

	public static function ajax_regenerate_webhook_token() {
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'frm_ab_lite_regenerate_token' ) ) {
			wp_die( esc_html__( 'Invalid nonce. Please refresh and try again.', 'frm-acceptblue-lite' ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'frm-acceptblue-lite' ), 403 );
		}

		if ( class_exists( 'Frm_AB_Lite_Recurring' ) ) {
			Frm_AB_Lite_Recurring::generate_webhook_token();
		}

		// Redirect back to the Accept.Blue settings tab
		wp_safe_redirect( add_query_arg(
			array(
				'page'        => 'formidable-settings',
				'frm_action'  => 'acceptblue_token_regenerated',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	// -------------------------------------------------------------------------
	// AJAX: Export CSV
	// -------------------------------------------------------------------------

	public static function ajax_export_csv() {
		wp_send_json_error( __( 'CSV Export is available in the Pro version.', 'frm-acceptblue-lite' ) );
		return; // Lite
	}


	// -------------------------------------------------------------------------
	// AJAX: Capture (auth-only -> captured)
	// -------------------------------------------------------------------------

	public static function ajax_capture_transaction() {
		wp_send_json_error( __( 'Capture is available in the Pro version.', 'frm-acceptblue-lite' ) );
		return; // Lite
	}


	// -------------------------------------------------------------------------
	// AJAX: Adjust + Capture
	// -------------------------------------------------------------------------

	public static function ajax_adjust_capture_transaction() {
		wp_send_json_error( __( 'Adjust & Capture is available in the Pro version.', 'frm-acceptblue-lite' ) );
		return; // Lite
	}


	// -------------------------------------------------------------------------
	// AJAX: Void
	// -------------------------------------------------------------------------

	public static function ajax_void_transaction() {
		wp_send_json_error( __( 'Void is available in the Pro version.', 'frm-acceptblue-lite' ) );
		return; // Lite
	}


	// -------------------------------------------------------------------------
	// AJAX: Refund
	// -------------------------------------------------------------------------

	public static function ajax_refund_transaction() {
		wp_send_json_error( __( 'Refunds are available in the Pro version.', 'frm-acceptblue-lite' ) );
		return; // Lite
	}


	// -------------------------------------------------------------------------
	// Log Viewer
	// -------------------------------------------------------------------------

	private static function render_log_viewer() {
		if ( ! class_exists( 'Frm_AB_Lite_Logger' ) ) return;

		// ── Handle Clear Log POST (submitted from within the log viewer) ────
		$cleared = false;
		if ( isset( $_POST['frm_ab_lite_clear_log'] ) && check_admin_referer( 'frm_ab_lite_clear_log' ) ) {
			Frm_AB_Lite_Logger::clear();
			$cleared = true;
		}

		$files   = Frm_AB_Lite_Logger::list_files();
		$current = isset( $_GET['log_file'] ) ? sanitize_text_field( wp_unslash( $_GET['log_file'] ) ) : '';
		// Only allow files inside our log dir
		$upload  = wp_upload_dir();
		$log_dir = trailingslashit( $upload['basedir'] ) . Frm_AB_Lite_Logger::LOG_DIR;
		if ( $current && ( strpos( realpath( $current ) ?: '', realpath( $log_dir ) ?: 'x' ) !== 0 ) ) {
			$current = '';
		}
		if ( ! $current && ! empty( $files ) ) $current = $files[0];

		$log_content = $current ? Frm_AB_Lite_Logger::tail( 500 ) : '';

		?>
		<h2><?php esc_html_e( 'Accept.Blue Log Viewer', 'frm-acceptblue-lite' ); ?></h2>
		<?php if ( $cleared ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'Log cleared.', 'frm-acceptblue-lite' ); ?></p></div>
		<?php endif; ?>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=frm-ab-lite-transactions' ) ); ?>" class="button">&larr; <?php esc_html_e( 'Back to Transactions', 'frm-acceptblue-lite' ); ?></a>
			<?php if ( $current ) : ?>
			<form method="post" action="<?php echo esc_url( add_query_arg( array( 'page' => 'frm-ab-lite-transactions', 'frm_ab_lite_view_log' => '1' ), admin_url( 'admin.php' ) ) ); ?>" style="display:inline;margin-left:8px;">
				<?php wp_nonce_field( 'frm_ab_lite_clear_log' ); ?>
				<button type="submit" name="frm_ab_lite_clear_log" value="1" class="button button-secondary"
					onclick="return confirm('<?php esc_attr_e( 'Clear this log file?', 'frm-acceptblue-lite' ); ?>')"><?php esc_html_e( 'Clear Log', 'frm-acceptblue-lite' ); ?></button>
			</form>
			<?php endif; ?>
		</p>

		<?php if ( ! empty( $files ) ) : ?>
		<p>
			<strong><?php esc_html_e( 'Log file:', 'frm-acceptblue-lite' ); ?></strong>
			<select onchange="location.href=this.value">
				<?php foreach ( $files as $f ) : ?>
					<option value="<?php echo esc_url( add_query_arg( array( 'frm_ab_lite_view_log' => 1, 'log_file' => $f ), admin_url( 'admin.php?page=frm-ab-lite-transactions' ) ) ); ?>"
						<?php selected( $f, $current ); ?>>
						<?php echo esc_html( basename( $f ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<code style="font-size:11px;color:#666;"><?php echo esc_html( $current ); ?></code>
		</p>
		<?php endif; ?>

		<div style="background:#1d1d1d;color:#e0e0e0;padding:16px;border-radius:6px;font-family:monospace;font-size:12px;line-height:1.6;white-space:pre-wrap;max-height:600px;overflow-y:auto;word-break:break-all;">
			<?php if ( $log_content ) :
				// Colorize log levels
				$colored = htmlspecialchars( $log_content );
				$colored = preg_replace( '/\[ERROR\]/',   '<span style="color:#ff6b6b;font-weight:700;">[ERROR]</span>',   $colored );
				$colored = preg_replace( '/\[REQUEST\]/', '<span style="color:#69db7c;">[REQUEST]</span>',  $colored );
				$colored = preg_replace( '/\[RESPONSE\]/','<span style="color:#74c0fc;">[RESPONSE]</span>', $colored );
				$colored = preg_replace( '/\[INFO\]/',    '<span style="color:#ffd43b;">[INFO]</span>',    $colored );
				echo $colored; // phpcs:ignore
			 else : ?>
				<em style="color:#aaa;"><?php esc_html_e( 'Log is empty. Enable Debug Logging in Settings > Accept.Blue to start capturing logs.', 'frm-acceptblue-lite' ); ?></em>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Recurring Schedules Page
	// -------------------------------------------------------------------------

	public static function render_schedules_page() {
		// Pro feature — show static overlay, no live API calls
		$icon = esc_url( FRM_AB_LITE_URL . 'assets/accept-blue-icon.svg' );
		?>
		<style>
.frm-ab-lite-pro-page-banner{display:flex;align-items:center;gap:14px;background:linear-gradient(135deg,#1d2327,#2c3a47);color:#fff;padding:14px 20px;border-radius:8px;margin:16px 0 20px;box-shadow:0 3px 12px rgba(0,0,0,.18);font-size:13.5px;line-height:1.5}
.frm-ab-lite-pro-page-banner a{color:#7dd3fc;font-weight:700;text-decoration:none}
.frm-ab-lite-mock-blur{filter:blur(4px);opacity:.5;pointer-events:none;user-select:none}
.frm-ab-lite-pro-wrap{position:relative}
.frm-ab-lite-pro-wrap-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;z-index:10;background:rgba(255,255,255,.3);border-radius:6px}
.frm-ab-lite-pro-badge-lg{background:linear-gradient(135deg,#1d2327,#2c3a47);color:#fff;padding:14px 34px;border-radius:32px;font-weight:700;font-size:15px;box-shadow:0 6px 24px rgba(0,0,0,.28);text-align:center;line-height:1.6}
.frm-ab-lite-pro-badge-lg small{display:block;font-size:11px;font-weight:400;opacity:.8;margin-top:3px}
</style>
		<div class="wrap frm-ab-lite-schedules-page">
			<h1>
				<img src="<?php echo esc_url( $icon ); ?>" alt="" style="width:28px;height:28px;border-radius:5px;vertical-align:middle;margin-right:6px;">
				<?php esc_html_e( 'accept.blue — Recurring Schedules', 'frm-acceptblue-lite' ); ?>
			</h1>
			<hr class="wp-header-end">

			<div class="frm-ab-lite-pro-page-banner">
				<span style="font-size:22px;flex-shrink:0;">&#128274;</span>
				<span>
					<strong>Recurring Schedules — Pro Feature</strong><br>
					View, pause, resume, and cancel recurring billing schedules from accept.blue.
					<a href="https://www.patreon.com/posts/formidable-blue-157799373?source=lite" target="_blank" rel="noopener">&#8599; Upgrade to Pro</a> to unlock.
				</span>
			</div>

			<div class="frm-ab-lite-pro-wrap">
				<div class="frm-ab-lite-pro-wrap-overlay">
					<div class="frm-ab-lite-pro-badge-lg">&#128274; Pro Version<small>Upgrade to unlock Recurring Schedules</small></div>
				</div>
				<div class="frm-ab-lite-mock-blur">
					<table class="wp-list-table widefat fixed striped frm-ab-lite-table">
						<thead><tr>
							<?php foreach ( ['ID','Title','Customer','Amount','Frequency','Next Run','Remaining','Status','Created'] as $h ) : ?>
								<th style="background:#1a4a7a;color:#fff;padding:10px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;"><?php echo esc_html( $h ); ?></th>
							<?php endforeach; ?>
						</tr></thead>
						<tbody>
						<?php
						$mock = [
							['38984','Membership','ID 266807','$8.00','Monthly','2026-05-10','5'],
							['38983','Membership','ID 266806','$10.00','Monthly','2026-05-10','∞'],
							['38982','Membership','ID 266805','$10.00','Monthly','2026-05-10','∞'],
							['38981','Membership','ID 266804','$8.00','Monthly','2026-05-10','5'],
							['38980','Membership','ID 266803','$8.00','Monthly','2026-05-10','5'],
						];
						foreach ( $mock as $row ) : ?>
						<tr>
							<?php foreach ( $row as $cell ) : ?><td><?php echo esc_html( $cell ); ?></td><?php endforeach; ?>
							<td><span style="background:#dcfce7;color:#166534;border-radius:99px;padding:2px 10px;font-size:12px;">Active</span></td>
							<td>May 9, 2026</td>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div><!-- /.mock-blur -->
			</div><!-- /.pro-wrap -->
		</div><!-- /.wrap -->
		<?php
	}

	
	public static function ajax_refresh_schedules() {
		if ( ! check_ajax_referer( 'frm_ab_lite_refresh_schedules', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce', 'frm-acceptblue-lite' ) ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'frm-acceptblue-lite' ) ) );
		}

		$api    = Frm_AB_Lite_Settings::get_api();
		// list_recurring_schedules now paginates automatically and returns all schedules
		// sorted newest first — no limit parameter needed.
		$result = $api->list_recurring_schedules();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$schedules = is_array( $result ) ? $result : [];
		wp_send_json_success( array( 'count' => count( $schedules ), 'schedules' => $schedules ) );
	}

	/**
	 * Add accept.blue icon to plugin row in WP Plugins list.
	 */
	public static function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( FRM_AB_LITE_FILE ) !== $file ) return $links;
		$links[] = '<img src="' . esc_url( FRM_AB_LITE_URL . 'assets/accept-blue-icon.svg' ) . '" '
			. 'style="width:16px;height:16px;vertical-align:middle;margin-right:4px;" alt=""> accept.blue';
		return $links;
	}

	// ── Pro upgrade admin notice ─────────────────────────────────────────────

	public static function maybe_show_pro_notice() {
		// Never show on any Formidable page — avoids overlap with form editor UI
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		if ( str_starts_with( $page, 'formidable' ) || str_starts_with( $page, 'frm-' ) || str_starts_with( $page, 'frm_' ) ) {
			return;
		}
		$uid = get_current_user_id();
		if ( get_transient( 'frm_ab_lite_pro_notice_dismissed_' . $uid ) ) {
			return;
		}
		$pro_url = 'https://www.patreon.com/posts/formidable-blue-157799373?source=lite';
		?>
		<div class="notice frm-ab-lite-admin-notice" style="display:flex;align-items:center;gap:16px;padding:14px 16px;border-left:4px solid #1d2327;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.08);position:relative;">
			<span style="font-size:26px;flex-shrink:0;">&#128274;</span>
			<div style="flex:1;min-width:0;">
				<strong style="font-size:13.5px;">Payment gateway: accept.blue for Formidable</strong>
				<p style="margin:4px 0 0;font-size:13px;color:#3c434a;">
					You&rsquo;re running the Lite version. Unlock <strong>recurring billing, refunds, webhooks, fraud shield, and an admin transactions panel</strong> by upgrading to Pro.
					<a href="<?php echo esc_url( $pro_url ); ?>" target="_blank" rel="noopener" style="font-weight:700;color:#0073aa;margin-left:6px;">&#8599; Upgrade to Pro &rarr;</a>
				</p>
			</div>
			<button type="button" class="frm-ab-lite-dismiss-btn"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'frm_ab_lite_dismiss_notice' ) ); ?>"
					style="flex-shrink:0;background:#1d2327;color:#fff;border:none;border-radius:20px;padding:7px 18px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;">
				Dismiss for 30 days
			</button>
		</div>
		<script>
		(function(){
			var btn = document.querySelector('.frm-ab-lite-dismiss-btn');
			if (!btn) return;
			btn.addEventListener('click', function(){
				var notice = btn.closest('.frm-ab-lite-admin-notice');
				notice.style.transition = 'opacity .3s';
				notice.style.opacity = '0';
				setTimeout(function(){ notice.remove(); }, 320);
				fetch(ajaxurl, {
					method: 'POST',
					headers: {'Content-Type': 'application/x-www-form-urlencoded'},
					body: 'action=frm_ab_lite_dismiss_pro_notice&nonce=' + encodeURIComponent(btn.dataset.nonce)
				});
			});
		})();
		</script>
		<?php
	}

	public static function ajax_dismiss_pro_notice() {
		check_ajax_referer( 'frm_ab_lite_dismiss_notice', 'nonce' );
		$uid = get_current_user_id();
		set_transient( 'frm_ab_lite_pro_notice_dismissed_' . $uid, 1, 30 * DAY_IN_SECONDS );
		wp_send_json_success();
	}


}

Frm_AB_Lite_Admin_Panel::init();
