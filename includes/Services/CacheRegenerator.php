<?php

declare(strict_types=1);

namespace AiElementorSync\Services;

/**
 * Best-effort Elementor cache/CSS regeneration. No-op if Elementor not active.
 */
final class CacheRegenerator {

	/**
	 * Trigger Elementor cache/CSS regeneration for the post if Elementor is active.
	 * No-op on failure or if Elementor not installed/active.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function regenerate( int $post_id ): void {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}
		try {
			$plugin = \Elementor\Plugin::$instance;
			if ( isset( $plugin->files ) && is_object( $plugin->files ) && method_exists( $plugin->files, 'clear_cache' ) ) {
				$plugin->files->clear_cache();
			}
			// Post-specific: some versions use CSS file regeneration per document.
			if ( isset( $plugin->documents ) && is_object( $plugin->documents ) && method_exists( $plugin->documents, 'get' ) ) {
				$doc = $plugin->documents->get( $post_id );
				if ( $doc && method_exists( $doc, 'save_template' ) === false && method_exists( $doc, 'get_css_wrapper_selector' ) ) {
					// Trigger CSS regeneration for this document if API exists.
					do_action( 'elementor/css_file/post/parse', $doc );
				}
			}
			do_action( 'elementor/core/files/clear_cache' );
		} catch ( \Throwable $e ) {
			// Silent no-op; never fatal.
		}
	}
}
