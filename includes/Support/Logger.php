<?php

declare(strict_types=1);

namespace AiElementorSync\Support;

/**
 * Logging helper. error_log when WP_DEBUG; UI log for admin visibility.
 */
final class Logger {

	private const UI_LOG_OPTION = 'ai_elementor_sync_ui_log';
	private const UI_LOG_MAX = 100;

	/**
	 * Log a message with optional context.
	 * Always appends to the UI log (visible in Settings → AI Elementor Sync → Log), regardless of WP_DEBUG.
	 * Also writes to error_log when WP_DEBUG is true.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context (e.g. post_id, url).
	 * @return void
	 */
	public static function log( string $message, array $context = [] ): void {
		self::log_ui( 'info', $message, $context );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$line = $message;
			if ( ! empty( $context ) ) {
				$line .= ' ' . wp_json_encode( $context );
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[ai-elementor-sync] ' . $line );
		}
	}

	/**
	 * Append an entry to the UI log (visible in Settings → AI Elementor Sync → Log).
	 *
	 * @param string $level   'info' | 'error' | 'request' | 'response'.
	 * @param string $message Short message.
	 * @param array  $context Optional context (e.g. url, code, edits_count); avoid large payloads.
	 * @return void
	 */
	public static function log_ui( string $level, string $message, array $context = [] ): void {
		$entries = get_option( self::UI_LOG_OPTION, [] );
		if ( ! is_array( $entries ) ) {
			$entries = [];
		}
		$entries[] = [
			'time'    => current_time( 'mysql' ),
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		];
		if ( count( $entries ) > self::UI_LOG_MAX ) {
			$entries = array_slice( $entries, -self::UI_LOG_MAX, null, true );
		}
		update_option( self::UI_LOG_OPTION, $entries, false );
	}

	/**
	 * Get UI log entries (newest last for display reverse).
	 *
	 * @return array<int, array{ time: string, level: string, message: string, context: array }>
	 */
	public static function get_ui_log(): array {
		$entries = get_option( self::UI_LOG_OPTION, [] );
		if ( ! is_array( $entries ) ) {
			return [];
		}
		return array_values( $entries );
	}

	/**
	 * Clear the UI log.
	 *
	 * @return void
	 */
	public static function clear_ui_log(): void {
		update_option( self::UI_LOG_OPTION, [], false );
	}
}
