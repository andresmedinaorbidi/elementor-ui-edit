<?php

declare(strict_types=1);

namespace AiElementorSync\Support;

/**
 * Optional logging helper. Logs only when WP_DEBUG is true.
 */
final class Logger {

	/**
	 * Log a message with optional context. Only logs when WP_DEBUG is true.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context (e.g. post_id, url).
	 * @return void
	 */
	public static function log( string $message, array $context = [] ): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		$line = $message;
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[ai-elementor-sync] ' . $line );
	}
}
