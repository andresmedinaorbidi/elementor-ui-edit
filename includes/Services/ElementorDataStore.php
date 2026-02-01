<?php

declare(strict_types=1);

namespace AiElementorSync\Services;

use AiElementorSync\Support\Logger;

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
	 * WordPress update_post_meta returns false when the new value equals the existing value (no change).
	 * We treat that as success. If update fails and value changed, try delete + add to force write.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Element tree array.
	 * @return bool True on success.
	 */
	public static function save( int $post_id, array $data ): bool {
		$json = wp_json_encode( $data );
		if ( $json === false ) {
			Logger::log_ui( 'error', 'ElementorDataStore::save failed: wp_json_encode returned false.', [ 'post_id' => $post_id ] );
			return false;
		}
		$to_save = wp_slash( $json );
		$result  = update_post_meta( $post_id, '_elementor_data', $to_save );

		if ( $result !== false ) {
			return true;
		}

		// update_post_meta returns false when value is unchanged; treat as success.
		$current = get_post_meta( $post_id, '_elementor_data', true );
		$current_str = is_string( $current ) ? $current : wp_json_encode( $current );
		if ( $current_str === $to_save ) {
			return true;
		}

		// Force write: delete then add (works around update_post_meta quirks on some hosts).
		delete_post_meta( $post_id, '_elementor_data' );
		$added = add_post_meta( $post_id, '_elementor_data', $to_save, true );
		if ( $added ) {
			return true;
		}

		Logger::log_ui( 'error', 'ElementorDataStore::save failed: update and delete+add both failed.', [
			'post_id'     => $post_id,
			'json_length' => strlen( $json ),
		] );
		return false;
	}
}
