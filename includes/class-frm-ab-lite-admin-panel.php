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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'admin_notices',         array( __CLASS__, 'maybe_show_upsell_notice' ) );
		add_action( 'wp_ajax_frm_ab_lite_dismiss_upsell',          array( __CLASS__, 'ajax_dismiss_upsell' ) );
	}

	// -------------------------------------------------------------------------
	// Pro Upsell Notice (dismissible for 7 days, Formidable pages only)
	// -------------------------------------------------------------------------

	const UPSELL_TRANSIENT = 'frm_ab_lite_upsell_dismissed';
	const PRO_URL          = 'https://ryanplugins.net/product/formidable-accept-blue-payment-gateway/';

	/**
	 * Display the upsell notice on Formidable-related admin pages only.
	 * Follows WordPress guidelines:
	 *  - manage_options cap check
	 *  - standard .notice.notice-info.is-dismissible markup
	 *  - dismiss stored as a site transient (7 days), not per-user meta,
	 *    so admins on the same site share the dismissed state
	 *  - AJAX dismiss with nonce verification
	 */
	public static function maybe_show_upsell_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_transient( self::UPSELL_TRANSIENT ) ) {
			return;
		}

		$nonce   = wp_create_nonce( 'frm_ab_lite_dismiss_upsell' );
		$pro_url = esc_url( self::PRO_URL );
		?>
		<div class="notice notice-info is-dismissible" id="frm-ab-lite-upsell-notice" style="display:flex;align-items:center;gap:12px;padding:12px 16px;">
			<img src="<?php echo esc_url( FRM_AB_LITE_URL . 'assets/accept-blue-icon.svg' ); ?>"
				style="width:36px;height:36px;border-radius:6px;flex-shrink:0;" alt="accept.blue">
			<p style="margin:0;">
				<strong><?php esc_html_e( 'Unlock the full power of accept.blue for Formidable Forms', 'payment-gateway-accept-blue-for-formidable' ); ?></strong>
				&mdash;
				<?php esc_html_e( 'Upgrade to Pro for 3D Secure, force capture, recurring subscriptions, refunds, webhooks, fraud shield, and more.', 'payment-gateway-accept-blue-for-formidable' ); ?>
				&nbsp;
				<a href="<?php echo esc_url( $pro_url ); ?>" target="_blank" rel="noopener" class="button button-primary" style="margin-left:6px;">
					<?php esc_html_e( 'Upgrade to Pro →', 'payment-gateway-accept-blue-for-formidable' ); ?>
				</a>
			</p>
		</div>
		<?php
		wp_add_inline_script( 'frm-acceptblue-lite-admin', sprintf(
			'( function() {
				var notice = document.getElementById( "frm-ab-lite-upsell-notice" );
				if ( ! notice ) return;
				notice.addEventListener( "click", function( e ) {
					if ( ! e.target.classList.contains( "notice-dismiss" ) ) return;
					var data = new FormData();
					data.append( "action", "frm_ab_lite_dismiss_upsell" );
					data.append( "nonce", %s );
					fetch( %s, { method: "POST", body: data, credentials: "same-origin" } );
				} );
			} )();',
			wp_json_encode( $nonce ),
			wp_json_encode( admin_url( 'admin-ajax.php' ) )
		) );
	}

	/**
	 * AJAX handler: store the 7-day dismissal transient.
	 */
	public static function ajax_dismiss_upsell() {
		check_ajax_referer( 'frm_ab_lite_dismiss_upsell', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		set_transient( self::UPSELL_TRANSIENT, '1', 7 * DAY_IN_SECONDS );
		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public static function register_menu() {
		add_submenu_page(
			'formidable',
			__( 'Accept.Blue Transactions', 'payment-gateway-accept-blue-for-formidable' ),
			__( 'Accept.Blue', 'payment-gateway-accept-blue-for-formidable' ),
			'manage_options',
			'frm-ab-lite-transactions',
			array( __CLASS__, 'render_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------

	public static function render_page() {
		// Log viewer — real Lite feature
		if ( isset( $_GET['frm_ab_lite_view_log'] ) && class_exists( 'Frm_AB_Lite_Logger' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="wrap frm-ab-lite-admin-panel">';
			self::render_log_viewer();
			echo '</div>';
			return;
		}
		?>
		<div class="wrap frm-ab-lite-admin-panel">
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
		// (Panel shows a Pro upgrade notice in Lite — no action buttons to wire up)

		// ── Settings/Formidable admin pages ───────────────────────────────────
		if ( $is_formidable ) {
			// Test connection script
			$test_data = array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'frm_ab_lite_test' ),
				'testing'          => __( 'Testing...', 'payment-gateway-accept-blue-for-formidable' ),
				'connectionFailed' => __( 'Connection failed.', 'payment-gateway-accept-blue-for-formidable' ),
			);
			wp_add_inline_script( 'frm-acceptblue-lite-admin', 'var frmAbSettings = ' . wp_json_encode( $test_data ) . ';' );
			wp_add_inline_script( 'frm-acceptblue-lite-admin', self::test_connection_js() );

			// License section script
			$lic_data = array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'enterCode'          => __( 'Please enter your purchase code.', 'payment-gateway-accept-blue-for-formidable' ),
				'verifying'          => __( 'Verifying...', 'payment-gateway-accept-blue-for-formidable' ),
				'verifyBtn'          => __( 'Verify License', 'payment-gateway-accept-blue-for-formidable' ),
				'verifyShort'        => __( 'Verify', 'payment-gateway-accept-blue-for-formidable' ),
				'networkError'       => __( 'Network error. Please check your connection and try again.', 'payment-gateway-accept-blue-for-formidable' ),
				'verifyFailed'       => __( 'Verification failed. Please check your purchase code and try again.', 'payment-gateway-accept-blue-for-formidable' ),
				'deactivating'       => __( 'Deactivating...', 'payment-gateway-accept-blue-for-formidable' ),
				'deactivateBtn'      => __( 'Deactivate License', 'payment-gateway-accept-blue-for-formidable' ),
				'deactivateConfirm'  => __( 'Are you sure you want to deactivate this license?', 'payment-gateway-accept-blue-for-formidable' ),
			);
			wp_add_inline_script( 'frm-acceptblue-lite-admin', 'var frmAbLicense = ' . wp_json_encode( $lic_data ) . ';' );
			wp_add_inline_script( 'frm-acceptblue-lite-admin', self::license_js() );

			// Form action / recurring toggle JS
			wp_add_inline_script( 'frm-acceptblue-lite-admin', self::form_action_js() );
		}
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
		<h2><?php esc_html_e( 'Accept.Blue Log Viewer', 'payment-gateway-accept-blue-for-formidable' ); ?></h2>
		<?php if ( $cleared ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'Log cleared.', 'payment-gateway-accept-blue-for-formidable' ); ?></p></div>
		<?php endif; ?>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=frm-ab-lite-transactions' ) ); ?>" class="button">&larr; <?php esc_html_e( 'Back to Transactions', 'payment-gateway-accept-blue-for-formidable' ); ?></a>
			<?php if ( $current ) : ?>
			<form method="post" action="<?php echo esc_url( add_query_arg( array( 'page' => 'frm-ab-lite-transactions', 'frm_ab_lite_view_log' => '1' ), admin_url( 'admin.php' ) ) ); ?>" style="display:inline;margin-left:8px;">
				<?php wp_nonce_field( 'frm_ab_lite_clear_log' ); ?>
				<button type="submit" name="frm_ab_lite_clear_log" value="1" class="button button-secondary"
					onclick="return confirm('<?php esc_attr_e( 'Clear this log file?', 'payment-gateway-accept-blue-for-formidable' ); ?>')"><?php esc_html_e( 'Clear Log', 'payment-gateway-accept-blue-for-formidable' ); ?></button>
			</form>
			<?php endif; ?>
		</p>

		<?php if ( ! empty( $files ) ) : ?>
		<p>
			<strong><?php esc_html_e( 'Log file:', 'payment-gateway-accept-blue-for-formidable' ); ?></strong>
			<select onchange="location.href=this.value">
				<?php foreach ( $files as $f ) : ?>
					<option value="<?php echo esc_url( add_query_arg( array( 'frm_ab_lite_view_log' => 1, 'log_file' => $f ), admin_url( 'admin.php?page=frm-ab-lite-transactions' ) ) ); ?>"
						<?php selected( $f, $current ); ?>>
						<?php echo esc_html( basename( $f ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<code style="font-size:11px;color:#666;"><?php echo esc_html( basename( $current ) ); ?></code>
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
				<em style="color:#aaa;"><?php esc_html_e( 'Log is empty. Enable Debug Logging in Settings > Accept.Blue to start capturing logs.', 'payment-gateway-accept-blue-for-formidable' ); ?></em>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Recurring Schedules Page
	// -------------------------------------------------------------------------


	


}

Frm_AB_Lite_Admin_Panel::init();
