<?php
/**
 * Plugin Name:     Formidable Accept.Blue Lite
 * Plugin URI:      https://ryanplugins.net
 * Description:     Lite version — integrates accept.blue Hosted Tokenization payments and debug logging with Formidable Forms. Upgrade to Pro for recurring subscriptions, refunds, admin panel, webhooks, fraud shield, and more.
 * Version:         1.0.0
 * Author:          RyanPlugins
 * Author URI:      https://www.patreon.com/RyanPlugins
 * Text Domain:     frm-acceptblue
 * Requires PHP:    7.4
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'FRM_AB_LITE_VERSION', '1.0.0-lite' );
define( 'FRM_AB_LITE_FILE',    __FILE__ );
define( 'FRM_AB_LITE_PATH',    plugin_dir_path( __FILE__ ) );
define( 'FRM_AB_LITE_URL',     plugin_dir_url( __FILE__ ) );

$frm_ab_lite_includes = [
	'class-frm-ab-lite-logger.php',
	'class-frm-ab-lite-api.php',
	'class-frm-ab-lite-settings.php',
	'class-frm-ab-lite-field.php',
	'class-frm-ab-lite-field-mapper.php',
	'class-frm-ab-lite-recurring.php',
	'class-frm-ab-lite-form-action.php',
	'class-frm-ab-lite-gateway.php',
	'class-frm-ab-lite-admin-panel.php',
	'class-frm-ab-lite-dashboard.php',
	'class-frm-ab-lite-fraud.php',
];


// Lite version — licensing removed.


function frm_ab_lite_boot() {
	global $frm_ab_lite_includes;

	// Load plugin translations.
	load_plugin_textdomain( 'frm-acceptblue-lite', false, dirname( plugin_basename( FRM_AB_LITE_FILE ) ) . '/languages' );

	if ( ! class_exists( 'FrmHooksController' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="error"><p>';
			echo '<strong>' . esc_html__( 'Accept.Blue for Formidable Forms', 'frm-acceptblue-lite' ) . '</strong> ';
			echo esc_html__( 'requires Formidable Forms to be installed and active.', 'frm-acceptblue-lite' );
			echo '</p></div>';
		} );
		return;
	}
	foreach ( $frm_ab_lite_includes as $file ) {
		require_once FRM_AB_LITE_PATH . 'includes/' . $file;
	}
}
add_action( 'plugins_loaded', 'frm_ab_lite_boot', 20 );

register_activation_hook( __FILE__, function() {
	frm_ab_lite_create_table();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );

/**
 * Create (or upgrade) the plugin's own payments table.
 * Uses dbDelta so it is safe to call on every plugin load.
 */
function frm_ab_lite_create_table() {
	global $wpdb;
	$table      = $wpdb->prefix . 'frm_ab_lite_payments';
	$charset_db = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE {$table} (
		id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		item_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
		action_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
		paysys       VARCHAR(32)  NOT NULL DEFAULT 'acceptblue',
		amount       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
		status       VARCHAR(32)  NOT NULL DEFAULT 'pending',
		receipt_id   VARCHAR(128) NOT NULL DEFAULT '',
		meta_value   LONGTEXT,
		created_at   DATETIME     NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		KEY item_id  (item_id),
		KEY status   (status),
		KEY created_at (created_at)
	) {$charset_db};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( 'frm_ab_lite_db_version', '1.0' );
}
// Run on every load in case plugin was updated or table was dropped
add_action( 'plugins_loaded', function() {
	if ( get_option('frm_ab_lite_db_version') !== '1.0' ) {
		frm_ab_lite_create_table();
	}
}, 5 );
