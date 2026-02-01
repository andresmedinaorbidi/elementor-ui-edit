<?php

declare(strict_types=1);

namespace AiElementorSync\Services;

/**
 * Best-effort Elementor cache/CSS regeneration so frontend shows updated _elementor_data.
 * No-op if Elementor not active. Uses files_manager (Elementor API) then clear_cache action.
 */
final class CacheRegenerator {

	/**
	 * Trigger Elementor cache/CSS regeneration for the post if Elementor is active.
	 * Ensures frontend reads fresh _elementor_data. No-op on failure or if Elementor not installed/active.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function regenerate( int $post_id ): void {
		// Delete this post's Elementor CSS meta so next load regenerates from _elementor_data.
		delete_post_meta( $post_id, '_elementor_css' );

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}
		try {
			$plugin = \Elementor\Plugin::$instance;
			// Elementor uses files_manager (not "files"); clear_cache() regenerates CSS and data cache.
			$files = isset( $plugin->files_manager ) && is_object( $plugin->files_manager )
				? $plugin->files_manager
				: ( isset( $plugin->files ) && is_object( $plugin->files ) ? $plugin->files : null );
			if ( $files && method_exists( $files, 'clear_cache' ) ) {
				$files->clear_cache();
			}
			// Post-specific: trigger CSS/data parse for this document so frontend picks up new content.
			if ( isset( $plugin->documents ) && is_object( $plugin->documents ) && method_exists( $plugin->documents, 'get' ) ) {
				$doc = $plugin->documents->get( $post_id );
				if ( $doc && method_exists( $doc, 'get_css_wrapper_selector' ) ) {
					do_action( 'elementor/css_file/post/parse', $doc );
				}
			}
			do_action( 'elementor/core/files/clear_cache' );
		} catch ( \Throwable $e ) {
			// Silent no-op; never fatal.
		}
	}
}
