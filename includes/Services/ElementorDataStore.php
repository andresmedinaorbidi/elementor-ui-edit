<?php

declare(strict_types=1);

namespace AiElementorSync\Services;

/**
 * Read/write Elementor page data from post meta _elementor_data.
 */
final class ElementorDataStore {

	/**
	 * Get Elementor data array for a post. Handles both JSON string and already-decoded array.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Decoded element tree array, or null if missing/invalid.
	 */
	public static function get( int $post_id ): ?array {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( $raw === '' || $raw === false ) {
			return null;
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) ) {
			return null;
		}
		// Try raw first (some hosts return already-clean JSON); then try stripslashes if WordPress stored slashed.
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = json_decode( stripslashes( $raw ), true );
		}
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Save Elementor data to post meta.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Element tree array.
	 * @return bool True on success.
	 */
	public static function save( int $post_id, array $data ): bool {
		$json = wp_json_encode( $data );
		if ( $json === false ) {
			return false;
		}
		// wp_slash compensates for WordPress stripping slashes when reading; matches Elementor save.
		return update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) ) !== false;
	}
}
