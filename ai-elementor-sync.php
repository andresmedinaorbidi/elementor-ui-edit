<?php

/**
 * Plugin Name: AI Elementor Sync
 * Description: REST API to perform unambiguous text replacement in Elementor widgets (Text Editor + Heading) on a page by URL.
 * Version: 1.0.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Text Domain: ai-elementor-sync
 * Author: AI Elementor Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AI_ELEMENTOR_SYNC_VERSION', '1.0.0' );
define( 'AI_ELEMENTOR_SYNC_PATH', plugin_dir_path( __FILE__ ) );

spl_autoload_register( function ( string $class ): void {
	$prefix = 'AiElementorSync\\';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$path     = AI_ELEMENTOR_SYNC_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_readable( $path ) ) {
		require_once $path;
	}
} );

\AiElementorSync\Plugin::init();
