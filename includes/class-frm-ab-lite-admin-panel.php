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
		add_action( 'wp_ajax_frm_ab_lite_sync_transaction',           array( __CLASS__, 'ajax_sync_transaction' ) );
		add_action( 'wp_ajax_frm_ab_lite_regenerate_webhook_token',   array( __CLASS__, 'ajax_regenerate_webhook_token' ) );
		add_action( 'wp_ajax_frm_ab_lite_capture_transaction',        array( __CLASS__, 'ajax_capture_transaction' ) );
		add_action( 'wp_ajax_frm_ab_lite_adjust_capture_transaction', array( __CLASS__, 'ajax_adjust_capture_transaction' ) );
		add_action( 'wp_ajax_frm_ab_lite_void_transaction',           array( __CLASS__, 'ajax_void_transaction' ) );
		add_action( 'wp_ajax_frm_ab_lite_refund_transaction',         array( __CLASS__, 'ajax_refund_transaction' ) );
		add_action( 'wp_ajax_frm_ab_lite_export_csv',                 array( __CLASS__, 'ajax_export_csv' ) );
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
		// Log viewer — real Lite feature
		if ( isset( $_GET['frm_ab_lite_view_log'] ) && class_exists( 'Frm_AB_Lite_Logger' ) ) {
			echo '<div class="wrap frm-ab-lite-admin-panel">';
			self::render_log_viewer();
			echo '</div>';
			return;
		}
		$icon = esc_url( FRM_AB_LITE_URL . 'assets/accept-blue-icon.svg' );
		$pro_url = esc_url( 'https://ryanplugins.net' );
		?>
		<div class="wrap frm-ab-lite-admin-panel">
			<h1 style="display:inline-flex;align-items:center;gap:10px;">
				<img src="<?php echo $icon; ?>" style="width:28px;height:28px;border-radius:5px;" alt="">
				<?php esc_html_e( 'accept.blue — Transactions', 'frm-acceptblue-lite' ); ?>
			</h1>
			<hr class="wp-header-end">

			<div class="notice notice-info" style="margin:16px 0;padding:12px 16px;">
				<p>
					<strong><?php esc_html_e( 'Transactions Panel — Pro feature', 'frm-acceptblue-lite' ); ?></strong><br>
					<?php esc_html_e( 'Upgrade to Pro to view all payments, filter by status, export CSV, issue refunds, capture authorisations, and sync with accept.blue in real time.', 'frm-acceptblue-lite' ); ?>
					<a href="<?php echo $pro_url; ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Learn more about Pro &rarr;', 'frm-acceptblue-lite' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	
	public static function enqueue_scripts( $hook ) {
		$is_panel     = strpos( $hook, 'frm-ab-lite-transactions' ) !== false;
		$is_formidable = strpos( $hook, 'formidable' ) !== false
			|| strpos( $hook, 'frm_' ) !== false
			|| $hook === 'toplevel_page_formidable'
			|| strpos( $hook, 'page_formidable' ) !== false;

		if ( ! $is_panel && ! $is_formidable ) return;

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
		if ( $is_panel ) {
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
		.frm-ab-lite-admin-panel .tablenav-pages a,
		.frm-ab-lite-admin-panel .tablenav-pages span.current,

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


	

	/**
	 * Add accept.blue icon to plugin row in WP Plugins list.
	 */
	public static function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( FRM_AB_LITE_FILE ) !== $file ) return $links;
		$links[] = '<img src="' . esc_url( FRM_AB_LITE_URL . 'assets/accept-blue-icon.svg' ) . '" '
			. 'style="width:16px;height:16px;vertical-align:middle;margin-right:4px;" alt=""> accept.blue';
		return $links;
	}



}

Frm_AB_Lite_Admin_Panel::init();
