<?php

declare(strict_types=1);

namespace AiElementorSync\Services;

use AiElementorSync\Support\Logger;

/**
 * Read/write Elementor active Kit page_settings (global colors, typography, etc.).
 *
 * Storage: option elementor_active_kit = kit post ID; post meta _elementor_page_settings
 * holds site settings. Structure of colors/typography (e.g. system_colors, system_typography)
 * may vary by Elementor version; this service reads/writes the raw structure.
 */
final class KitStore {

	/**
	 * WordPress option key for the active Kit post ID.
	 *
	 * @var string
	 */
	private const OPTION_ACTIVE_KIT = 'elementor_active_kit';

	/**
	 * Post meta key for Kit page settings (site-wide colors, typography, etc.).
	 *
	 * @var string
	 */
	private const META_PAGE_SETTINGS = '_elementor_page_settings';

	/**
	 * Known keys for global colors in page_settings (version-dependent; may be absent).
	 *
	 * @var string
	 */
	private const KEY_SYSTEM_COLORS = 'system_colors';

	/**
	 * Known keys for global typography in page_settings (version-dependent; may be absent).
	 *
	 * @var string
	 */
	private const KEY_SYSTEM_TYPOGRAPHY = 'system_typography';

	/**
	 * Get the active Kit post ID.
	 *
	 * @return int|null Kit post ID, or null if missing or invalid.
	 */
	public static function getActiveKitId(): ?int {
		$raw = get_option( self::OPTION_ACTIVE_KIT, null );
		if ( $raw === null || $raw === '' || $raw === false ) {
			return null;
		}
		$id = is_numeric( $raw ) ? (int) $raw : 0;
		if ( $id <= 0 ) {
			return null;
		}
		$post = get_post( $id );
		if ( ! $post || $post->post_status === 'trash' ) {
			return null;
		}
		return $id;
	}

	/**
	 * Get Kit page_settings array for the active Kit.
	 *
	 * Handles both array and serialized storage. Returns normalized array or null.
	 *
	 * @return array|null Page settings array, or null if no active kit or missing/invalid meta.
	 */
	public static function getPageSettings(): ?array {
		$kit_id = self::getActiveKitId();
		if ( $kit_id === null ) {
			return null;
		}
		$raw = get_post_meta( $kit_id, self::META_PAGE_SETTINGS, true );
		if ( $raw === '' || $raw === false ) {
			return [];
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		$unserialized = @unserialize( $raw, [ 'allowed_classes' => false ] );
		if ( is_array( $unserialized ) ) {
			return $unserialized;
		}
		return null;
	}

	/**
	 * Get the kit item id (_id or id) from a color/typography item for matching.
	 *
	 * @param array $item Single color or typography item.
	 * @return string|null Non-empty string id or null.
	 */
	private static function getItemKitId( array $item ): ?string {
		if ( isset( $item['_id'] ) && is_string( $item['_id'] ) && $item['_id'] !== '' ) {
			return $item['_id'];
		}
		if ( isset( $item['id'] ) && is_string( $item['id'] ) && $item['id'] !== '' ) {
			return $item['id'];
		}
		if ( isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
			return (string) $item['id'];
		}
		return null;
	}

	/**
	 * Merge a list of kit items (colors or typography) by _id/id: update existing, append new.
	 * Preserves order: existing items stay in place (updated), new items appended.
	 *
	 * @param array $current Current list (indexed array of items).
	 * @param array $patch   Incoming list (indexed array of items) to merge by _id/id.
	 * @return array Merged list.
	 */
	private static function mergeKitListById( array $current, array $patch ): array {
		$by_id = [];
		foreach ( $current as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$kid = self::getItemKitId( $item );
			if ( $kid !== null ) {
				$by_id[ $kid ] = $item;
			}
		}
		foreach ( $patch as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$kid = self::getItemKitId( $item );
			if ( $kid !== null ) {
				$merged = array_merge( isset( $by_id[ $kid ] ) ? $by_id[ $kid ] : [], $item );
				// Elementor may use "color" for the hex; keep value and color in sync.
				if ( isset( $merged['value'] ) && is_string( $merged['value'] ) && $merged['value'] !== '' ) {
					$merged['color'] = $merged['value'];
				}
				if ( isset( $merged['color'] ) && is_string( $merged['color'] ) && $merged['color'] !== '' ) {
					if ( ! isset( $merged['value'] ) || ! is_string( $merged['value'] ) || $merged['value'] === '' ) {
						$merged['value'] = $merged['color'];
					}
				}
				$by_id[ $kid ] = $merged;
			}
		}
		// Preserve original order: output existing in current order (updated), then new ones.
		$seen = [];
		$out  = [];
		foreach ( $current as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$kid = self::getItemKitId( $item );
			if ( $kid !== null && isset( $by_id[ $kid ] ) ) {
				$out[]   = $by_id[ $kid ];
				$seen[]  = $kid;
			}
		}
		foreach ( $by_id as $kid => $merged ) {
			if ( ! in_array( $kid, $seen, true ) ) {
				$out[] = $merged;
			}
		}
		return $out;
	}

	/**
	 * Update Kit page_settings by merging a patch into current settings.
	 *
	 * For system_colors and system_typography, merges by _id/id (update existing item, append new).
	 * Other keys: replace or set as-is.
	 * Saves back to post meta. Does not clear cache; caller may trigger regeneration.
	 *
	 * @param array $patch Associative array of keys to merge (e.g. system_colors, system_typography).
	 * @return bool True on success.
	 */
	public static function updatePageSettings( array $patch ): bool {
		$kit_id = self::getActiveKitId();
		if ( $kit_id === null ) {
			return false;
		}
		$current = self::getPageSettings();
		if ( $current === null ) {
			$current = [];
		}
		foreach ( $patch as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			if ( $key === self::KEY_SYSTEM_COLORS && is_array( $value ) ) {
				$current_list = isset( $current[ $key ] ) && is_array( $current[ $key ] ) ? $current[ $key ] : [];
				$current[ $key ] = self::mergeKitListById( $current_list, $value );
			} elseif ( $key === self::KEY_SYSTEM_TYPOGRAPHY && is_array( $value ) ) {
				$current_list = isset( $current[ $key ] ) && is_array( $current[ $key ] ) ? $current[ $key ] : [];
				$current[ $key ] = self::mergeKitListById( $current_list, $value );
			} elseif ( is_array( $value ) && isset( $current[ $key ] ) && is_array( $current[ $key ] ) ) {
				$current[ $key ] = array_merge( $current[ $key ], $value );
			} else {
				$current[ $key ] = $value;
			}
		}
		$result = update_post_meta( $kit_id, self::META_PAGE_SETTINGS, $current );
		if ( $result === false ) {
			Logger::log_ui( 'error', 'KitStore::updatePageSettings failed.', [ 'kit_id' => $kit_id ] );
			return false;
		}
		return true;
	}

	/**
	 * Normalize a single color item for API: ensure "value" is set from "color" if missing/empty.
	 *
	 * @param array $item Single color item.
	 * @return array Same item with value set from color when value is missing or empty.
	 */
	public static function normalizeColorForApi( array $item ): array {
		if ( ! is_array( $item ) ) {
			return $item;
		}
		$value = isset( $item['value'] ) && is_string( $item['value'] ) ? trim( $item['value'] ) : '';
		$color = isset( $item['color'] ) && is_string( $item['color'] ) ? trim( $item['color'] ) : '';
		if ( $value !== '' ) {
			return $item;
		}
		if ( $color !== '' ) {
			$item['value'] = $color;
		}
		return $item;
	}

	/**
	 * Normalize colors array for API: each item gets "value" from "color" if missing/empty.
	 *
	 * @param array $colors List of color items.
	 * @return array Same list with each item normalized.
	 */
	public static function normalizeColorsForApi( array $colors ): array {
		$out = [];
		foreach ( $colors as $item ) {
			$out[] = is_array( $item ) ? self::normalizeColorForApi( $item ) : $item;
		}
		return $out;
	}

	/**
	 * Extract colors array from page_settings for API response.
	 *
	 * Uses known key system_colors if present; otherwise returns empty array.
	 * Schema may vary by Elementor version.
	 *
	 * @param array $settings Full page_settings array.
	 * @return array Colors list or associative array.
	 */
	public static function extractColors( array $settings ): array {
		if ( isset( $settings[ self::KEY_SYSTEM_COLORS ] ) && is_array( $settings[ self::KEY_SYSTEM_COLORS ] ) ) {
			return $settings[ self::KEY_SYSTEM_COLORS ];
		}
		return [];
	}

	/**
	 * Extract typography array from page_settings for API response.
	 *
	 * Uses known key system_typography if present; otherwise returns empty array.
	 * Schema may vary by Elementor version.
	 *
	 * @param array $settings Full page_settings array.
	 * @return array Typography list or associative array.
	 */
	public static function extractTypography( array $settings ): array {
		if ( isset( $settings[ self::KEY_SYSTEM_TYPOGRAPHY ] ) && is_array( $settings[ self::KEY_SYSTEM_TYPOGRAPHY ] ) ) {
			return $settings[ self::KEY_SYSTEM_TYPOGRAPHY ];
		}
		return [];
	}
}
