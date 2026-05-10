<?php
/**
 * Accept.Blue — Dedicated Logger
 *
 * Writes timestamped log entries to:
 *   wp-content/uploads/frm-ab-lite-logs/accept-blue-YYYY-MM.log
 *
 * Separate from PHP error_log / debug.log to keep things clean.
 * Log files rotate monthly. Only writes when debug logging is enabled
 * in Settings > Accept.Blue > Debug Logging (except errors which always log).
 *
 * Usage:
 *   Frm_AB_Lite_Logger::log( 'INFO',  'Charge created', array( 'id' => 123 ) );
 *   Frm_AB_Lite_Logger::info( 'Nonce received',  array( 'field' => 154 ) );
 *   Frm_AB_Lite_Logger::error( 'Charge failed',  array( 'msg'   => $e ) );
 *   Frm_AB_Lite_Logger::request( 'POST /transactions/charge', $body );
 *   Frm_AB_Lite_Logger::response( 'HTTP 200', $result );
 *
 * @package FrmAcceptBlue
 *
 * @package FrmAcceptBlue
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class Frm_AB_Lite_Logger {

	const LOG_DIR  = 'frm-ab-lite-logs';

	/** @var string|null cached log file path */
	private static $log_file = null;

	// ─────────────────────────────────────────────────────────────────────────
	// Public API
	// ─────────────────────────────────────────────────────────────────────────

	/** Always logs — errors are written regardless of debug setting. */
	public static function error( string $message, array $context = [] ): void {
		self::write( 'ERROR', $message, $context );
	}

	/** Only logs when debug is enabled. */
	public static function info( string $message, array $context = [] ): void {
		if ( ! self::debug_enabled() ) return;
		self::write( 'INFO', $message, $context );
	}

	/** API request log — debug only. Redacts sensitive source/nonce values. */
	public static function request( string $label, array $body = [] ): void {
		if ( ! self::debug_enabled() ) return;
		$safe = $body;
		if ( isset( $safe['source'] ) ) {
			$safe['source'] = substr( $safe['source'], 0, 15 ) . '...[redacted]';
		}
		self::write( 'REQUEST', $label, $safe );
	}

	/** API response log — debug only. Truncates large bodies. */
	public static function response( string $label, $body = null ): void {
		if ( ! self::debug_enabled() ) return;
		$context = [];
		if ( $body !== null ) {
			$encoded = is_string( $body ) ? $body : wp_json_encode( $body );
			$context['body'] = strlen( $encoded ) > 2000
				? substr( $encoded, 0, 2000 ) . '...[truncated]'
				: $encoded;
		}
		self::write( 'RESPONSE', $label, $context );
	}

	/** Generic log — always writes. */
	public static function log( string $level, string $message, array $context = [] ): void {
		self::write( strtoupper( $level ), $message, $context );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// View log (for admin UI)
	// ─────────────────────────────────────────────────────────────────────────

	/** Return last N lines of the current log file. */
	public static function tail( int $lines = 200 ): string {
		$file = self::get_log_file();
		if ( ! $file || ! file_exists( $file ) ) return '';
		$all = file( $file, FILE_IGNORE_NEW_LINES );
		if ( ! $all ) return '';
		return implode( "\n", array_slice( $all, -$lines ) );
	}

	/** Return the full path to the current log file. */
	public static function get_log_file(): ?string {
		if ( self::$log_file ) return self::$log_file;
		$dir = self::ensure_log_dir();
		if ( ! $dir ) return null;
		self::$log_file = $dir . '/accept-blue-' . gmdate( 'Y-m' ) . '.log';
		return self::$log_file;
	}

	/** List all existing log files, newest first. */
	public static function list_files(): array {
		$dir = self::ensure_log_dir();
		if ( ! $dir ) return [];
		$files = glob( $dir . '/accept-blue-*.log' );
		if ( ! $files ) return [];
		rsort( $files );
		return $files;
	}

	/** Clear (truncate) the current log file. */
	public static function clear(): void {
		$file = self::get_log_file();
		if ( $file && file_exists( $file ) ) {
			file_put_contents( $file, '' );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Internal
	// ─────────────────────────────────────────────────────────────────────────

	private static function write( string $level, string $message, array $context = [] ): void {
		$file = self::get_log_file();
		if ( ! $file ) return;

		$ts      = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		$ctx_str = empty( $context ) ? '' : ' ' . wp_json_encode( $context );
		$line    = "[{$ts}] [{$level}] {$message}{$ctx_str}" . PHP_EOL;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	private static function ensure_log_dir(): ?string {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . self::LOG_DIR;

		if ( ! is_dir( $dir ) ) {
			// wp_mkdir_p handles recursive creation and returns false on failure — no @ needed
			if ( ! wp_mkdir_p( $dir ) ) {
				return null;
			}
			// Protect the directory from direct browser access
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "Options -Indexes\nDeny from all\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
		}
		return $dir;
	}

	private static function debug_enabled(): bool {
		return class_exists( 'Frm_AB_Lite_Settings' ) && Frm_AB_Lite_Settings::is_debug_enabled();
	}
}
