<?php

declare(strict_types=1);

namespace AiElementorSync\Services;

/**
 * Traverses Elementor element tree, finds matching text in supported widgets,
 * and optionally replaces when exactly one widget contains the find string (normalized).
 * Supports simple widgets (multiple text fields + optional link), repeater widgets (per-item text/link),
 * and price-table (title + features array). Dictionary entries include field and item_index where applicable.
 */
final class ElementorTraverser {

	/** @var array<string, string> widgetType => primary field (backward compat when edit has no field) */
	private const WIDGET_PRIMARY = [
		'heading'          => 'title',
		'text-editor'      => 'editor',
		'button'           => 'text',
		'icon'             => null,
		'image-box'        => 'title_text',
		'icon-box'         => 'title_text',
		'testimonial'      => 'content',
		'counter'          => 'prefix',
		'animated-headline'=> 'title',
		'flip-box'         => 'title_text',
		'icon-list'        => null,
		'accordion'         => null,
		'tabs'              => null,
		'price-list'       => null,
		'price-table'      => 'title',
	];

	/**
	 * Simple widgets: text_fields, optional link_field, primary.
	 * Repeater: repeater key, item_text_fields, optional item_link_field.
	 * Price-table: text_fields (title), features_field (array of strings).
	 * Control IDs match Elementor core; verify in elementor/includes/widgets/ if needed.
	 *
	 * @var array<string, array{ type: string, text_fields?: array, link_field?: ?string, primary?: ?string, repeater?: string, item_text_fields?: array, item_link_field?: ?string, features_field?: string }>
	 */
	private const WIDGET_CONFIG = [
		'heading'           => [ 'type' => 'simple', 'text_fields' => [ 'title' ], 'link_field' => null, 'primary' => 'title' ],
		'text-editor'       => [ 'type' => 'simple', 'text_fields' => [ 'editor' ], 'link_field' => null, 'primary' => 'editor' ],
		'button'            => [ 'type' => 'simple', 'text_fields' => [ 'text' ], 'link_field' => 'link', 'primary' => 'text' ],
		'icon'              => [ 'type' => 'simple', 'text_fields' => [], 'link_field' => 'link', 'primary' => null ],
		'image-box'         => [ 'type' => 'simple', 'text_fields' => [ 'title_text', 'description_text' ], 'link_field' => 'link', 'primary' => 'title_text' ],
		'icon-box'          => [ 'type' => 'simple', 'text_fields' => [ 'title_text', 'description_text' ], 'link_field' => 'link', 'primary' => 'title_text' ],
		'testimonial'       => [ 'type' => 'simple', 'text_fields' => [ 'content', 'title' ], 'link_field' => null, 'primary' => 'content' ],
		'counter'           => [ 'type' => 'simple', 'text_fields' => [ 'prefix', 'suffix' ], 'link_field' => null, 'primary' => 'prefix' ],
		'animated-headline' => [ 'type' => 'simple', 'text_fields' => [ 'title' ], 'link_field' => null, 'primary' => 'title' ],
		'flip-box'          => [ 'type' => 'simple', 'text_fields' => [ 'title_text', 'description_text', 'title_text_back', 'description_text_back' ], 'link_field' => 'link', 'primary' => 'title_text' ],
		'icon-list'         => [ 'type' => 'repeater', 'repeater' => 'icon_list', 'item_text_fields' => [ 'text' ], 'item_link_field' => 'link', 'primary' => null ],
		'accordion'         => [ 'type' => 'repeater', 'repeater' => 'tabs', 'item_text_fields' => [ 'tab_title', 'tab_content' ], 'item_link_field' => null, 'primary' => null ],
		'tabs'              => [ 'type' => 'repeater', 'repeater' => 'tabs', 'item_text_fields' => [ 'tab_title', 'tab_content' ], 'item_link_field' => null, 'primary' => null ],
		'price-list'        => [ 'type' => 'repeater', 'repeater' => 'price_list', 'item_text_fields' => [ 'title', 'price', 'item_description' ], 'item_link_field' => 'link', 'primary' => null ],
		'price-table'       => [ 'type' => 'price_table', 'text_fields' => [ 'title' ], 'features_field' => 'features', 'primary' => 'title' ],
	];

	/** All supported widget types (simple + repeater + price-table). */
	public const SUPPORTED_WIDGET_TYPES = [
		'heading', 'text-editor', 'button', 'icon', 'image-box', 'icon-box', 'testimonial', 'counter',
		'animated-headline', 'flip-box', 'icon-list', 'accordion', 'tabs', 'price-list', 'price-table',
	];

	/** Default widget types used when none specified (all supported types). */
	public const DEFAULT_WIDGET_TYPES = self::SUPPORTED_WIDGET_TYPES;

	/**
	 * Find all widgets that contain the find string (normalized) and optionally replace if exactly one.
	 * Mutates $data in-place only when exactly one widget contains the find.
	 *
	 * @param array  $data         Elementor data (full document with "content" key, or raw elements array). Passed by reference.
	 * @param string $find         Visible string to find (normalized for comparison; match is containment, not exact equality).
	 * @param string $replace      Replacement string.
	 * @param array  $widget_types Allowed widget types (e.g. ['text-editor','heading']).
	 * @return array{ matches_found: int, matches_replaced: int, candidates: array, data: array }
	 */
	public static function findAndMaybeReplace( array &$data, string $find, string $replace, array $widget_types ): array {
		$find_norm = Normalizer::normalize( $find );
		if ( $find_norm === '' ) {
			return [
				'matches_found'    => 0,
				'matches_replaced' => 0,
				'candidates'       => [],
				'data'             => $data,
			];
		}

		// Elementor stores document as { "title", "type", "version", "page_settings", "content": [ elements ] }.
		$elements = &self::getElementsRoot( $data );

		$candidates = [];
		$match_count = 0;
		self::traverseCollect( $elements, $find_norm, $find, $widget_types, [], $candidates, $match_count );

		if ( $match_count === 0 ) {
			return [
				'matches_found'    => 0,
				'matches_replaced' => 0,
				'candidates'       => [],
				'data'             => $data,
			];
		}

		if ( $match_count > 1 ) {
			return [
				'matches_found'    => $match_count,
				'matches_replaced' => 0,
				'candidates'       => $candidates,
				'data'             => $data,
			];
		}

		// Exactly one match: apply replacement.
		$replaced = 0;
		self::traverseReplace( $elements, $find_norm, $find, $replace, $widget_types, [], $replaced );
		return [
			'matches_found'    => 1,
			'matches_replaced' => $replaced,
			'candidates'       => [],
			'data'             => $data,
		];
	}

	/**
	 * Return the elements array to traverse (by reference). Elementor uses document with "content" key; fallback to root as elements.
	 *
	 * @param array $data Full document or raw elements array.
	 * @return array Reference to the elements array (content or root).
	 */
	private static function &getElementsRoot( array &$data ): array {
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			return $data['content'];
		}
		return $data;
	}

	/**
	 * Build a per-page dictionary of widgets for target widget types only.
	 * Each entry: id, path, widget_type, field, text; optional item_index (repeaters), link_url (when widget has link).
	 * Used for AI edit service context; full text per slot, not just preview.
	 *
	 * @param array $data         Elementor data (document with "content" or raw elements array).
	 * @param array $widget_types Widget types to include (e.g. ['text-editor','heading'] or SUPPORTED_WIDGET_TYPES).
	 * @param int   $max_text_len Optional max length per widget text for token limits (0 = no limit).
	 * @return array<int, array{ id: string, path: string, widget_type: string, field: string, text: string, item_index?: int, link_url?: string }>
	 */
	public static function buildPageDictionary( array $data, array $widget_types = null, int $max_text_len = 0 ): array {
		$widget_types = $widget_types ?? self::DEFAULT_WIDGET_TYPES;
		$elements = self::getElementsRoot( $data );
		$dictionary = [];
		self::traverseBuildDictionary( $elements, $widget_types, [], $dictionary, $max_text_len );
		return $dictionary;
	}

	/**
	 * Replace the text of a widget at the given path. Path format: "0/1/2" (element indices).
	 * When field is omitted, uses primary field for that widget type. For repeaters, item_index is required when targeting an item field.
	 *
	 * @param array  $data         Elementor data (passed by reference). Mutated if path is valid.
	 * @param string $path         Path string from dictionary (e.g. "0/1/2").
	 * @param string $new_text     New text to set.
	 * @param array  $widget_types Allowed widget types.
	 * @param string|null $field   Optional. Setting key (e.g. title_text, description_text). If null, primary field is used.
	 * @param int|null $item_index Optional. For repeater/price-table slots; 0-based index.
	 * @return bool True if replacement was applied, false if path invalid or not a target widget.
	 */
	public static function replaceByPath( array &$data, string $path, string $new_text, array $widget_types, ?string $field = null, ?int $item_index = null ): bool {
		$path = trim( $path );
		if ( $path === '' ) {
			return false;
		}
		$indices = array_map( 'intval', explode( '/', $path ) );
		foreach ( $indices as $i ) {
			if ( $i < 0 ) {
				return false;
			}
		}
		$elements = &self::getElementsRoot( $data );
		$node = &self::getNodeByPath( $elements, $indices );
		if ( $node === null ) {
			return false;
		}
		$el_type = $node['elType'] ?? '';
		$widget_type = $node['widgetType'] ?? '';
		if ( $el_type !== 'widget' || $widget_type === '' || ! in_array( $widget_type, $widget_types, true ) ) {
			return false;
		}
		$config = self::WIDGET_CONFIG[ $widget_type ] ?? null;
		if ( $config === null ) {
			return false;
		}
		if ( ! is_array( $node['settings'] ?? null ) ) {
			$node['settings'] = [];
		}
		$settings = &$node['settings'];

		if ( ( $config['type'] ?? '' ) === 'simple' ) {
			$target_field = $field ?? ( self::WIDGET_PRIMARY[ $widget_type ] ?? null );
			if ( $target_field === null || ! in_array( $target_field, $config['text_fields'] ?? [], true ) ) {
				return false;
			}
			$settings[ $target_field ] = $new_text;
			return true;
		}
		if ( ( $config['type'] ?? '' ) === 'repeater' ) {
			$repeater_key = $config['repeater'] ?? '';
			if ( $repeater_key === '' || $item_index === null ) {
				return false;
			}
			if ( ! isset( $settings[ $repeater_key ] ) || ! is_array( $settings[ $repeater_key ] ) ) {
				$settings[ $repeater_key ] = [];
			}
			$items = &$settings[ $repeater_key ];
			$target_field = $field ?? ( $config['item_text_fields'][0] ?? null );
			if ( $target_field === null || ! in_array( $target_field, $config['item_text_fields'] ?? [], true ) ) {
				return false;
			}
			self::ensureRepeaterItemExists( $items, $item_index );
			$items[ $item_index ][ $target_field ] = $new_text;
			return true;
		}
		if ( ( $config['type'] ?? '' ) === 'price_table' ) {
			$features_field = $config['features_field'] ?? 'features';
			if ( $field === $features_field && $item_index !== null ) {
				if ( ! isset( $settings[ $features_field ] ) || ! is_array( $settings[ $features_field ] ) ) {
					$settings[ $features_field ] = [];
				}
				$features = &$settings[ $features_field ];
				self::ensureArrayLength( $features, $item_index + 1 );
				$features[ $item_index ] = $new_text;
				return true;
			}
			$target_field = $field ?? ( self::WIDGET_PRIMARY[ $widget_type ] ?? null );
			if ( $target_field !== null && in_array( $target_field, $config['text_fields'] ?? [], true ) ) {
				$settings[ $target_field ] = $new_text;
				return true;
			}
			return false;
		}
		return false;
	}

	/**
	 * Replace the text of a widget with the given Elementor id. Id is stable across reorders.
	 *
	 * @param array  $data         Elementor data (passed by reference). Mutated if id is valid.
	 * @param string $id           Element id from dictionary.
	 * @param string $new_text     New text to set.
	 * @param array  $widget_types Allowed widget types.
	 * @param string|null $field   Optional. Setting key. If null, primary field is used.
	 * @param int|null $item_index Optional. For repeater/price-table; 0-based.
	 * @return bool True if replacement was applied.
	 */
	public static function replaceById( array &$data, string $id, string $new_text, array $widget_types, ?string $field = null, ?int $item_index = null ): bool {
		$id = trim( $id );
		if ( $id === '' ) {
			return false;
		}
		$path = self::findPathById( $data, $id );
		if ( $path === null ) {
			return false;
		}
		return self::replaceByPath( $data, $path, $new_text, $widget_types, $field, $item_index );
	}

	/**
	 * Replace the link URL of a widget at the given path. Supports simple link control or repeater item link.
	 *
	 * @param array  $data         Elementor data (passed by reference).
	 * @param string $path         Path string from dictionary.
	 * @param string|array $new_url_or_link New URL string, or object { url, is_external?, nofollow? }.
	 * @param array  $widget_types Allowed widget types.
	 * @param int|null $item_index For repeater widgets, 0-based item index; null for simple widget link.
	 * @return bool True if replacement was applied.
	 */
	public static function replaceUrlByPath( array &$data, string $path, $new_url_or_link, array $widget_types, ?int $item_index = null ): bool {
		$path = trim( $path );
		if ( $path === '' ) {
			return false;
		}
		$indices = array_map( 'intval', explode( '/', $path ) );
		foreach ( $indices as $i ) {
			if ( $i < 0 ) {
				return false;
			}
		}
		$elements = &self::getElementsRoot( $data );
		$node = &self::getNodeByPath( $elements, $indices );
		if ( $node === null ) {
			return false;
		}
		$el_type = $node['elType'] ?? '';
		$widget_type = $node['widgetType'] ?? '';
		if ( $el_type !== 'widget' || $widget_type === '' || ! in_array( $widget_type, $widget_types, true ) ) {
			return false;
		}
		$config = self::WIDGET_CONFIG[ $widget_type ] ?? null;
		if ( $config === null ) {
			return false;
		}
		$url = is_string( $new_url_or_link ) ? $new_url_or_link : ( isset( $new_url_or_link['url'] ) && is_string( $new_url_or_link['url'] ) ? $new_url_or_link['url'] : '' );
		if ( $url === '' && ! is_string( $new_url_or_link ) && is_array( $new_url_or_link ) && array_key_exists( 'url', $new_url_or_link ) ) {
			$url = (string) $new_url_or_link['url'];
		}
		if ( ! is_array( $node['settings'] ?? null ) ) {
			$node['settings'] = [];
		}
		$settings = &$node['settings'];

		if ( ( $config['type'] ?? '' ) === 'simple' ) {
			$link_field = $config['link_field'] ?? null;
			if ( $link_field === null ) {
				return false;
			}
			if ( ! isset( $settings[ $link_field ] ) || ! is_array( $settings[ $link_field ] ) ) {
				$settings[ $link_field ] = [];
			}
			$settings[ $link_field ]['url'] = $url;
			if ( is_array( $new_url_or_link ) ) {
				if ( array_key_exists( 'is_external', $new_url_or_link ) ) {
					$settings[ $link_field ]['is_external'] = $new_url_or_link['is_external'];
				}
				if ( array_key_exists( 'nofollow', $new_url_or_link ) ) {
					$settings[ $link_field ]['nofollow'] = $new_url_or_link['nofollow'];
				}
			}
			return true;
		}
		if ( ( $config['type'] ?? '' ) === 'repeater' ) {
			$repeater_key = $config['repeater'] ?? '';
			$item_link_field = $config['item_link_field'] ?? null;
			if ( $repeater_key === '' || $item_link_field === null || $item_index === null ) {
				return false;
			}
			if ( ! isset( $settings[ $repeater_key ] ) || ! is_array( $settings[ $repeater_key ] ) ) {
				$settings[ $repeater_key ] = [];
			}
			$items = &$settings[ $repeater_key ];
			self::ensureRepeaterItemExists( $items, $item_index );
			if ( ! isset( $items[ $item_index ][ $item_link_field ] ) || ! is_array( $items[ $item_index ][ $item_link_field ] ) ) {
				$items[ $item_index ][ $item_link_field ] = [];
			}
			$items[ $item_index ][ $item_link_field ]['url'] = $url;
			if ( is_array( $new_url_or_link ) ) {
				if ( array_key_exists( 'is_external', $new_url_or_link ) ) {
					$items[ $item_index ][ $item_link_field ]['is_external'] = $new_url_or_link['is_external'];
				}
				if ( array_key_exists( 'nofollow', $new_url_or_link ) ) {
					$items[ $item_index ][ $item_link_field ]['nofollow'] = $new_url_or_link['nofollow'];
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * Replace the link URL of a widget with the given Elementor id.
	 *
	 * @param array  $data         Elementor data (passed by reference).
	 * @param string $id           Element id from dictionary.
	 * @param string|array $new_url_or_link New URL string or { url, is_external?, nofollow? }.
	 * @param array  $widget_types Allowed widget types.
	 * @param int|null $item_index For repeater item link; null for simple widget.
	 * @return bool True if replacement was applied.
	 */
	public static function replaceUrlById( array &$data, string $id, $new_url_or_link, array $widget_types, ?int $item_index = null ): bool {
		$id = trim( $id );
		if ( $id === '' ) {
			return false;
		}
		$path = self::findPathById( $data, $id );
		if ( $path === null ) {
			return false;
		}
		return self::replaceUrlByPath( $data, $path, $new_url_or_link, $widget_types, $item_index );
	}

	/** Ensure repeater array has an item at index (fill with empty arrays). */
	private static function ensureRepeaterItemExists( array &$items, int $index ): void {
		while ( count( $items ) <= $index ) {
			$items[] = [];
		}
	}

	/** Ensure array has at least $length elements (fill with empty string). */
	private static function ensureArrayLength( array &$arr, int $length ): void {
		while ( count( $arr ) < $length ) {
			$arr[] = '';
		}
	}

	/**
	 * Get text at a specific slot (for verification). Returns null if widget/slot not supported.
	 *
	 * @param array       $node        Element node (settings must be present).
	 * @param string      $widget_type Widget type.
	 * @param string|null $field       Field key; null = primary.
	 * @param int|null    $item_index  For repeater/price_table; null for simple.
	 * @return string|null Text value or null.
	 */
	public static function getTextAtSlot( array $node, string $widget_type, ?string $field = null, ?int $item_index = null ): ?string {
		$config = self::WIDGET_CONFIG[ $widget_type ] ?? null;
		if ( $config === null ) {
			return null;
		}
		$settings = $node['settings'] ?? [];
		if ( ( $config['type'] ?? '' ) === 'simple' ) {
			$target = $field ?? ( self::WIDGET_PRIMARY[ $widget_type ] ?? null );
			if ( $target === null || ! in_array( $target, $config['text_fields'] ?? [], true ) ) {
				return null;
			}
			$v = $settings[ $target ] ?? '';
			return is_string( $v ) ? $v : (string) $v;
		}
		if ( ( $config['type'] ?? '' ) === 'repeater' && $item_index !== null ) {
			$repeater_key = $config['repeater'] ?? '';
			$items = $settings[ $repeater_key ] ?? [];
			$target = $field ?? ( $config['item_text_fields'][0] ?? null );
			if ( $target === null || ! isset( $items[ $item_index ] ) || ! is_array( $items[ $item_index ] ) ) {
				return null;
			}
			$v = $items[ $item_index ][ $target ] ?? '';
			return is_string( $v ) ? $v : (string) $v;
		}
		if ( ( $config['type'] ?? '' ) === 'price_table' ) {
			$features_field = $config['features_field'] ?? 'features';
			if ( $field === $features_field && $item_index !== null ) {
				$features = $settings[ $features_field ] ?? [];
				$v = $features[ $item_index ] ?? '';
				return is_string( $v ) ? $v : (string) $v;
			}
			$target = $field ?? ( self::WIDGET_PRIMARY[ $widget_type ] ?? null );
			if ( $target !== null && in_array( $target, $config['text_fields'] ?? [], true ) ) {
				$v = $settings[ $target ] ?? '';
				return is_string( $v ) ? $v : (string) $v;
			}
			return null;
		}
		return null;
	}

	/**
	 * Get link URL at a specific slot (for verification). Returns null if widget has no link or slot invalid.
	 *
	 * @param array    $node        Element node.
	 * @param string   $widget_type Widget type.
	 * @param int|null $item_index  For repeater item link; null for simple widget link.
	 * @return string|null URL or null.
	 */
	public static function getLinkUrlAtSlot( array $node, string $widget_type, ?int $item_index = null ): ?string {
		$config = self::WIDGET_CONFIG[ $widget_type ] ?? null;
		if ( $config === null ) {
			return null;
		}
		$settings = $node['settings'] ?? [];
		if ( ( $config['type'] ?? '' ) === 'simple' ) {
			$link_field = $config['link_field'] ?? null;
			if ( $link_field === null ) {
				return null;
			}
			$link = $settings[ $link_field ] ?? [];
			if ( ! is_array( $link ) || ! isset( $link['url'] ) ) {
				return null;
			}
			return is_string( $link['url'] ) ? $link['url'] : (string) $link['url'];
		}
		if ( ( $config['type'] ?? '' ) === 'repeater' && $item_index !== null ) {
			$repeater_key = $config['repeater'] ?? '';
			$item_link_field = $config['item_link_field'] ?? null;
			if ( $repeater_key === '' || $item_link_field === null ) {
				return null;
			}
			$items = $settings[ $repeater_key ] ?? [];
			if ( ! isset( $items[ $item_index ] ) || ! is_array( $items[ $item_index ] ) ) {
				return null;
			}
			$link = $items[ $item_index ][ $item_link_field ] ?? [];
			if ( ! is_array( $link ) || ! isset( $link['url'] ) ) {
				return null;
			}
			return is_string( $link['url'] ) ? $link['url'] : (string) $link['url'];
		}
		return null;
	}

	/**
	 * Find a node by Elementor id (read-only). For diagnostics when an edit fails.
	 *
	 * @param array  $data Elementor data (document with "content" or raw elements array). Passed by reference.
	 * @param string $id  Element id (e.g. from dictionary).
	 * @return array|null Node array if found, null otherwise.
	 */
	public static function findNodeById( array &$data, string $id ): ?array {
		$id = trim( $id );
		if ( $id === '' ) {
			return null;
		}
		$elements = &self::getElementsRoot( $data );
		$node = &self::getNodeById( $elements, $id );
		return $node;
	}

	/**
	 * Find the path string (e.g. "0/1/2") of a node by id. For diagnostics (which node was modified).
	 *
	 * @param array  $data Elementor data (document with "content" or raw elements array).
	 * @param string $id  Element id.
	 * @return string|null Path string if found, null otherwise.
	 */
	public static function findPathById( array $data, string $id ): ?string {
		$id = trim( $id );
		if ( $id === '' ) {
			return null;
		}
		$elements = self::getElementsRoot( $data );
		$path = self::traverseFindPathById( $elements, $id, [] );
		return $path;
	}

	/**
	 * Traverse elements to find path (indices) to node with given id.
	 *
	 * @param array $elements  Elements array.
	 * @param string $id       Element id.
	 * @param array $path_so_far Current path indices.
	 * @return string|null Path string if found, null otherwise.
	 */
	private static function traverseFindPathById( array $elements, string $id, array $path_so_far ): ?string {
		if ( ! is_array( $elements ) ) {
			return null;
		}
		foreach ( $elements as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$node_id = $node['id'] ?? '';
			if ( is_string( $node_id ) && $node_id !== '' && $node_id === $id ) {
				$path = array_merge( $path_so_far, [ $index ] );
				return implode( '/', array_map( 'strval', $path ) );
			}
			$children = $node['elements'] ?? [];
			if ( is_array( $children ) && ! empty( $children ) ) {
				$found = self::traverseFindPathById( $children, $id, array_merge( $path_so_far, [ $index ] ) );
				if ( $found !== null ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Find a node by path string "0/1/2" (read-only). For diagnostics when an edit fails.
	 *
	 * @param array  $data Elementor data (document with "content" or raw elements array). Passed by reference.
	 * @param string $path Path string (e.g. "0/1/2").
	 * @return array|null Node array if found, null otherwise.
	 */
	public static function findNodeByPath( array &$data, string $path ): ?array {
		$path = trim( $path );
		if ( $path === '' ) {
			return null;
		}
		$indices = array_map( 'intval', explode( '/', $path ) );
		foreach ( $indices as $i ) {
			if ( $i < 0 ) {
				return null;
			}
		}
		$elements = &self::getElementsRoot( $data );
		$node = &self::getNodeByPath( $elements, $indices );
		return $node;
	}

	/**
	 * Resolve element id to a reference to the node in the elements tree, or null if not found.
	 *
	 * @param array  $elements Elements array (root or nested).
	 * @param string $id       Element id (e.g. from $node['id']).
	 * @return array|null Reference to node or null.
	 */
	private static function &getNodeById( array &$elements, string $id ) {
		static $null = null;
		if ( ! is_array( $elements ) ) {
			return $null;
		}
		foreach ( $elements as $index => &$node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$node_id = $node['id'] ?? '';
			if ( is_string( $node_id ) && $node_id !== '' && $node_id === $id ) {
				return $node;
			}
			$children = $node['elements'] ?? [];
			if ( is_array( $children ) && ! empty( $children ) ) {
				$found = &self::getNodeById( $children, $id );
				if ( $found !== null ) {
					return $found;
				}
			}
		}
		unset( $node );
		return $null;
	}

	/**
	 * Resolve path indices to a reference to the node in the elements tree, or null if invalid.
	 *
	 * @param array $elements Elements array (root or nested).
	 * @param array $indices  List of indices (e.g. [0, 1, 2]).
	 * @return array|null Reference to node or null.
	 */
	private static function &getNodeByPath( array &$elements, array $indices ) {
		static $null = null;
		if ( ! is_array( $elements ) || empty( $indices ) ) {
			return $null;
		}
		$current = &$elements;
		$depth = count( $indices );
		for ( $i = 0; $i < $depth; $i++ ) {
			$idx = $indices[ $i ];
			if ( ! isset( $current[ $idx ] ) || ! is_array( $current[ $idx ] ) ) {
				return $null;
			}
			if ( $i === $depth - 1 ) {
				return $current[ $idx ];
			}
			$children = &$current[ $idx ]['elements'];
			if ( ! is_array( $children ) ) {
				return $null;
			}
			$current = &$children;
		}
		return $null;
	}

	/**
	 * Recursively build dictionary entries (path, widget_type, field, text; optional item_index, link_url) for target widgets.
	 *
	 * @param array $elements     Elements array.
	 * @param array $widget_types  Allowed widget types.
	 * @param array $path_prefix   Current path indices.
	 * @param array $dictionary   Output: list of entries with id, path, widget_type, field, text.
	 * @param int   $max_text_len Max length per text (0 = no truncation).
	 */
	private static function traverseBuildDictionary( array $elements, array $widget_types, array $path_prefix, array &$dictionary, int $max_text_len ): void {
		if ( ! is_array( $elements ) ) {
			return;
		}
		foreach ( $elements as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$path = array_merge( $path_prefix, [ $index ] );
			$path_str = implode( '/', array_map( 'strval', $path ) );
			$el_type = $node['elType'] ?? '';
			$widget_type = $node['widgetType'] ?? '';
			$el_id = isset( $node['id'] ) && is_string( $node['id'] ) ? $node['id'] : '';

			if ( $el_type === 'widget' && $widget_type !== '' && in_array( $widget_type, $widget_types, true ) ) {
				$config = self::WIDGET_CONFIG[ $widget_type ] ?? null;
				if ( $config === null ) {
					$children = $node['elements'] ?? [];
					if ( is_array( $children ) && ! empty( $children ) ) {
						self::traverseBuildDictionary( $children, $widget_types, $path, $dictionary, $max_text_len );
					}
					continue;
				}
				$settings = $node['settings'] ?? [];

				if ( ( $config['type'] ?? '' ) === 'simple' ) {
					$text_fields = $config['text_fields'] ?? [];
					$link_url = null;
					$link_field = $config['link_field'] ?? null;
					if ( $link_field !== null && isset( $settings[ $link_field ] ) && is_array( $settings[ $link_field ] ) ) {
						$link = $settings[ $link_field ];
						$link_url = isset( $link['url'] ) && is_string( $link['url'] ) ? $link['url'] : '';
					}
					foreach ( $text_fields as $field_key ) {
						$value = $settings[ $field_key ] ?? '';
						$text = is_string( $value ) ? $value : (string) $value;
						if ( $max_text_len > 0 && mb_strlen( $text ) > $max_text_len ) {
							$text = mb_substr( $text, 0, $max_text_len ) . '...';
						}
						$entry = [
							'id'          => $el_id,
							'path'        => $path_str,
							'widget_type' => $widget_type,
							'field'       => $field_key,
							'text'        => $text,
						];
						if ( $link_url !== null && $link_url !== '' ) {
							$entry['link_url'] = $link_url;
						}
						$dictionary[] = $entry;
					}
					// Link-only widget (e.g. icon): one entry with empty text if we have link, so AI has context.
					if ( empty( $text_fields ) && $link_url !== null && $link_url !== '' ) {
						$dictionary[] = [
							'id'          => $el_id,
							'path'        => $path_str,
							'widget_type' => $widget_type,
							'field'       => 'link',
							'text'        => '',
							'link_url'    => $link_url,
						];
					}
				} elseif ( ( $config['type'] ?? '' ) === 'repeater' ) {
					$repeater_key = $config['repeater'] ?? '';
					$item_text_fields = $config['item_text_fields'] ?? [];
					$item_link_field = $config['item_link_field'] ?? null;
					$items = isset( $settings[ $repeater_key ] ) && is_array( $settings[ $repeater_key ] ) ? $settings[ $repeater_key ] : [];
					foreach ( $items as $item_index => $item ) {
						if ( ! is_array( $item ) ) {
							continue;
						}
						foreach ( $item_text_fields as $field_key ) {
							$value = $item[ $field_key ] ?? '';
							$text = is_string( $value ) ? $value : (string) $value;
							if ( $max_text_len > 0 && mb_strlen( $text ) > $max_text_len ) {
								$text = mb_substr( $text, 0, $max_text_len ) . '...';
							}
							$entry = [
								'id'          => $el_id,
								'path'        => $path_str,
								'widget_type' => $widget_type,
								'field'       => $field_key,
								'item_index'  => (int) $item_index,
								'text'        => $text,
							];
							if ( $item_link_field !== null && isset( $item[ $item_link_field ] ) ) {
								$link = $item[ $item_link_field ];
								if ( is_array( $link ) && isset( $link['url'] ) && is_string( $link['url'] ) ) {
									$entry['link_url'] = $link['url'];
								}
							}
							$dictionary[] = $entry;
						}
						if ( $item_link_field !== null && ! empty( $item_link_field ) ) {
							$link = $item[ $item_link_field ] ?? null;
							$link_url = '';
							if ( is_array( $link ) && isset( $link['url'] ) && is_string( $link['url'] ) ) {
								$link_url = $link['url'];
							}
							$dictionary[] = [
								'id'          => $el_id,
								'path'        => $path_str,
								'widget_type' => $widget_type,
								'field'       => $item_link_field,
								'item_index'  => (int) $item_index,
								'text'        => '',
								'link_url'    => $link_url,
							];
						}
					}
				} elseif ( ( $config['type'] ?? '' ) === 'price_table' ) {
					$text_fields = $config['text_fields'] ?? [];
					$features_field = $config['features_field'] ?? 'features';
					foreach ( $text_fields as $field_key ) {
						$value = $settings[ $field_key ] ?? '';
						$text = is_string( $value ) ? $value : (string) $value;
						if ( $max_text_len > 0 && mb_strlen( $text ) > $max_text_len ) {
							$text = mb_substr( $text, 0, $max_text_len ) . '...';
						}
						$dictionary[] = [
							'id'          => $el_id,
							'path'        => $path_str,
							'widget_type' => $widget_type,
							'field'       => $field_key,
							'text'        => $text,
						];
					}
					$features = isset( $settings[ $features_field ] ) && is_array( $settings[ $features_field ] ) ? $settings[ $features_field ] : [];
					foreach ( $features as $fi => $feature_text ) {
						$text = is_string( $feature_text ) ? $feature_text : (string) $feature_text;
						if ( $max_text_len > 0 && mb_strlen( $text ) > $max_text_len ) {
							$text = mb_substr( $text, 0, $max_text_len ) . '...';
						}
						$dictionary[] = [
							'id'          => $el_id,
							'path'        => $path_str,
							'widget_type' => $widget_type,
							'field'       => $features_field,
							'item_index'  => (int) $fi,
							'text'        => $text,
						];
					}
				}
			}

			$children = $node['elements'] ?? [];
			if ( is_array( $children ) && ! empty( $children ) ) {
				self::traverseBuildDictionary( $children, $widget_types, $path, $dictionary, $max_text_len );
			}
		}
	}

	/**
	 * Collect all supported text/link slots from the tree (for diagnostics). Does not mutate data.
	 *
	 * @param array $data         Elementor data (document with "content" or raw elements array).
	 * @param array|null $widget_types Widget types to include; null = DEFAULT_WIDGET_TYPES.
	 * @return array{ data_structure: string, elements_count: int, text_fields: array }
	 */
	public static function collectAllTextFields( array $data, array $widget_types = null ): array {
		$widget_types = $widget_types ?? self::DEFAULT_WIDGET_TYPES;
		$elements = self::getElementsRoot( $data );
		$data_structure = isset( $data['content'] ) && is_array( $data['content'] ) ? 'document_with_content' : 'raw_elements_array';
		$elements_count = is_array( $elements ) ? count( $elements ) : 0;
		$text_fields = [];
		self::traverseCollectAll( $elements, $widget_types, [], $text_fields );
		return [
			'data_structure'  => $data_structure,
			'elements_count'  => $elements_count,
			'text_fields'     => $text_fields,
		];
	}

	/**
	 * Recursively collect every supported text/link slot (widget_type, field, item_index?, preview, path).
	 *
	 * @param array $elements     Elements array.
	 * @param array $widget_types Allowed widget types.
	 * @param array $path_prefix  Current path.
	 * @param array $text_fields  Output: list of { id, widget_type, field, preview, path, item_index? }.
	 */
	private static function traverseCollectAll( array $elements, array $widget_types, array $path_prefix, array &$text_fields ): void {
		if ( ! is_array( $elements ) ) {
			return;
		}
		foreach ( $elements as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$path = array_merge( $path_prefix, [ $index ] );
			$path_str = implode( '/', array_map( 'strval', $path ) );
			$el_type = $node['elType'] ?? '';
			$widget_type = $node['widgetType'] ?? '';
			$el_id = isset( $node['id'] ) && is_string( $node['id'] ) ? $node['id'] : '';

			if ( $el_type === 'widget' && $widget_type !== '' && in_array( $widget_type, $widget_types, true ) ) {
				$config = self::WIDGET_CONFIG[ $widget_type ] ?? null;
				if ( $config === null ) {
					$children = $node['elements'] ?? [];
					if ( is_array( $children ) && ! empty( $children ) ) {
						self::traverseCollectAll( $children, $widget_types, $path, $text_fields );
					}
					continue;
				}
				$settings = $node['settings'] ?? [];

				if ( ( $config['type'] ?? '' ) === 'simple' ) {
					$text_fields_list = $config['text_fields'] ?? [];
					$link_field = $config['link_field'] ?? null;
					foreach ( $text_fields_list as $field_key ) {
						$value = $settings[ $field_key ] ?? '';
						$value_str = is_string( $value ) ? $value : (string) $value;
						$norm = Normalizer::normalize( $value_str );
						$text_fields[] = [
							'id'          => $el_id,
							'widget_type' => $widget_type,
							'field'       => $field_key,
							'preview'     => Normalizer::preview_snippet( $norm ),
							'path'        => $path_str,
						];
					}
					if ( $link_field !== null ) {
						$link = $settings[ $link_field ] ?? [];
						$url = is_array( $link ) && isset( $link['url'] ) ? (string) $link['url'] : '';
						$text_fields[] = [
							'id'          => $el_id,
							'widget_type' => $widget_type,
							'field'       => $link_field,
							'preview'     => $url !== '' ? Normalizer::preview_snippet( Normalizer::normalize( $url ) ) : '(empty)',
							'path'        => $path_str,
						];
					}
				} elseif ( ( $config['type'] ?? '' ) === 'repeater' ) {
					$repeater_key = $config['repeater'] ?? '';
					$item_text_fields = $config['item_text_fields'] ?? [];
					$item_link_field = $config['item_link_field'] ?? null;
					$items = isset( $settings[ $repeater_key ] ) && is_array( $settings[ $repeater_key ] ) ? $settings[ $repeater_key ] : [];
					foreach ( $items as $item_index => $item ) {
						if ( ! is_array( $item ) ) {
							continue;
						}
						foreach ( $item_text_fields as $field_key ) {
							$value = $item[ $field_key ] ?? '';
							$value_str = is_string( $value ) ? $value : (string) $value;
							$norm = Normalizer::normalize( $value_str );
							$text_fields[] = [
								'id'          => $el_id,
								'widget_type' => $widget_type,
								'field'       => $field_key,
								'item_index'  => (int) $item_index,
								'preview'     => Normalizer::preview_snippet( $norm ),
								'path'        => $path_str,
							];
						}
						if ( $item_link_field !== null ) {
							$link = $item[ $item_link_field ] ?? [];
							$url = is_array( $link ) && isset( $link['url'] ) ? (string) $link['url'] : '';
							$text_fields[] = [
								'id'          => $el_id,
								'widget_type' => $widget_type,
								'field'       => $item_link_field,
								'item_index'  => (int) $item_index,
								'preview'     => $url !== '' ? Normalizer::preview_snippet( Normalizer::normalize( $url ) ) : '(empty)',
								'path'        => $path_str,
							];
						}
					}
				} elseif ( ( $config['type'] ?? '' ) === 'price_table' ) {
					$text_fields_list = $config['text_fields'] ?? [];
					$features_field = $config['features_field'] ?? 'features';
					foreach ( $text_fields_list as $field_key ) {
						$value = $settings[ $field_key ] ?? '';
						$value_str = is_string( $value ) ? $value : (string) $value;
						$norm = Normalizer::normalize( $value_str );
						$text_fields[] = [
							'id'          => $el_id,
							'widget_type' => $widget_type,
							'field'       => $field_key,
							'preview'     => Normalizer::preview_snippet( $norm ),
							'path'        => $path_str,
						];
					}
					$features = isset( $settings[ $features_field ] ) && is_array( $settings[ $features_field ] ) ? $settings[ $features_field ] : [];
					foreach ( $features as $fi => $feature_text ) {
						$value_str = is_string( $feature_text ) ? $feature_text : (string) $feature_text;
						$norm = Normalizer::normalize( $value_str );
						$text_fields[] = [
							'id'          => $el_id,
							'widget_type' => $widget_type,
							'field'       => $features_field,
							'item_index'  => (int) $fi,
							'preview'     => Normalizer::preview_snippet( $norm ),
							'path'        => $path_str,
						];
					}
				}
			}

			$children = $node['elements'] ?? [];
			if ( is_array( $children ) && ! empty( $children ) ) {
				self::traverseCollectAll( $children, $widget_types, $path, $text_fields );
			}
		}
	}

	/**
	 * Pass 1: collect candidates and count matches (containment: normalized text contains find). No replacement.
	 * Searches across all supported text fields (simple, repeater, price_table).
	 *
	 * @param array  $elements     Elements array (may be root or children).
	 * @param string $find_norm    Normalized find string.
	 * @param string $find_raw     Raw find string (for reference).
	 * @param array  $widget_types Allowed widget types.
	 * @param array  $path_prefix  Current path indices.
	 * @param array  $candidates   Output: list of { id, widget_type, field, item_index?, preview, path }.
	 * @param int    $match_count  Output: number of matches.
	 */
	private static function traverseCollect( array $elements, string $find_norm, string $find_raw, array $widget_types, array $path_prefix, array &$candidates, int &$match_count ): void {
		if ( ! is_array( $elements ) ) {
			return;
		}
		foreach ( $elements as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$path = array_merge( $path_prefix, [ $index ] );
			$path_str = implode( '/', array_map( 'strval', $path ) );
			$el_type = $node['elType'] ?? '';
			$widget_type = $node['widgetType'] ?? '';
			$el_id = isset( $node['id'] ) && is_string( $node['id'] ) ? $node['id'] : '';

			if ( $el_type === 'widget' && $widget_type !== '' && in_array( $widget_type, $widget_types, true ) ) {
				$config = self::WIDGET_CONFIG[ $widget_type ] ?? null;
				if ( $config !== null ) {
					$settings = $node['settings'] ?? [];
					if ( ( $config['type'] ?? '' ) === 'simple' ) {
						foreach ( $config['text_fields'] ?? [] as $field_key ) {
							$value = $settings[ $field_key ] ?? '';
							$value_str = is_string( $value ) ? $value : (string) $value;
							$norm = Normalizer::normalize( $value_str );
							if ( $find_norm !== '' && mb_strpos( $norm, $find_norm ) !== false ) {
								$match_count++;
								$candidates[] = [
									'id'          => $el_id,
									'widget_type' => $widget_type,
									'field'       => $field_key,
									'preview'     => Normalizer::preview_snippet( $norm ),
									'path'        => $path_str,
								];
							}
						}
					} elseif ( ( $config['type'] ?? '' ) === 'repeater' ) {
						$repeater_key = $config['repeater'] ?? '';
						$items = isset( $settings[ $repeater_key ] ) && is_array( $settings[ $repeater_key ] ) ? $settings[ $repeater_key ] : [];
						foreach ( $items as $item_index => $item ) {
							if ( ! is_array( $item ) ) {
								continue;
							}
							foreach ( $config['item_text_fields'] ?? [] as $field_key ) {
								$value = $item[ $field_key ] ?? '';
								$value_str = is_string( $value ) ? $value : (string) $value;
								$norm = Normalizer::normalize( $value_str );
								if ( $find_norm !== '' && mb_strpos( $norm, $find_norm ) !== false ) {
									$match_count++;
									$candidates[] = [
										'id'          => $el_id,
										'widget_type' => $widget_type,
										'field'       => $field_key,
										'item_index'  => (int) $item_index,
										'preview'     => Normalizer::preview_snippet( $norm ),
										'path'        => $path_str,
									];
								}
							}
						}
					} elseif ( ( $config['type'] ?? '' ) === 'price_table' ) {
						foreach ( $config['text_fields'] ?? [] as $field_key ) {
							$value = $settings[ $field_key ] ?? '';
							$value_str = is_string( $value ) ? $value : (string) $value;
							$norm = Normalizer::normalize( $value_str );
							if ( $find_norm !== '' && mb_strpos( $norm, $find_norm ) !== false ) {
								$match_count++;
								$candidates[] = [
									'id'          => $el_id,
									'widget_type' => $widget_type,
									'field'       => $field_key,
									'preview'     => Normalizer::preview_snippet( $norm ),
									'path'        => $path_str,
								];
							}
						}
						$features_field = $config['features_field'] ?? 'features';
						$features = isset( $settings[ $features_field ] ) && is_array( $settings[ $features_field ] ) ? $settings[ $features_field ] : [];
						foreach ( $features as $fi => $feature_text ) {
							$value_str = is_string( $feature_text ) ? $feature_text : (string) $feature_text;
							$norm = Normalizer::normalize( $value_str );
							if ( $find_norm !== '' && mb_strpos( $norm, $find_norm ) !== false ) {
								$match_count++;
								$candidates[] = [
									'id'          => $el_id,
									'widget_type' => $widget_type,
									'field'       => $features_field,
									'item_index'  => (int) $fi,
									'preview'     => Normalizer::preview_snippet( $norm ),
									'path'        => $path_str,
								];
							}
						}
					}
				}
			}

			$children = $node['elements'] ?? [];
			if ( is_array( $children ) && ! empty( $children ) ) {
				self::traverseCollect( $children, $find_norm, $find_raw, $widget_types, $path, $candidates, $match_count );
			}
		}
	}

	/**
	 * Pass 2: find the single matching slot (containment) and apply replacement (only when exactly one match).
	 * If raw contains find_raw, replace that substring; else replace entire slot value.
	 *
	 * @param array  $elements     Elements array.
	 * @param string $find_norm    Normalized find string.
	 * @param string $find_raw     Raw find string.
	 * @param string $replace      Replacement string.
	 * @param array  $widget_types Allowed widget types.
	 * @param array  $path_prefix  Current path.
	 * @param int    $replaced     Output: 1 if replacement was applied.
	 */
	private static function traverseReplace( array &$elements, string $find_norm, string $find_raw, string $replace, array $widget_types, array $path_prefix, int &$replaced ): void {
		if ( ! is_array( $elements ) || $replaced > 0 ) {
			return;
		}
		foreach ( array_keys( $elements ) as $index ) {
			$node = &$elements[ $index ];
			if ( ! is_array( $node ) ) {
				continue;
			}
			$path = array_merge( $path_prefix, [ $index ] );
			$el_type = $node['elType'] ?? '';
			$widget_type = $node['widgetType'] ?? '';

			if ( $el_type === 'widget' && $widget_type !== '' && in_array( $widget_type, $widget_types, true ) ) {
				$config = self::WIDGET_CONFIG[ $widget_type ] ?? null;
				if ( $config !== null ) {
					if ( ! is_array( $node['settings'] ?? null ) ) {
						$node['settings'] = [];
					}
					$settings = &$node['settings'];

					if ( ( $config['type'] ?? '' ) === 'simple' ) {
						foreach ( $config['text_fields'] ?? [] as $field_key ) {
							$value = $settings[ $field_key ] ?? '';
							$value_str = is_string( $value ) ? $value : (string) $value;
							$norm = Normalizer::normalize( $value_str );
							if ( $find_norm !== '' && mb_strpos( $norm, $find_norm ) !== false ) {
								if ( strpos( $value_str, $find_raw ) !== false ) {
									$settings[ $field_key ] = str_replace( $find_raw, $replace, $value_str );
								} else {
									$settings[ $field_key ] = $replace;
								}
								$replaced = 1;
								return;
							}
						}
					} elseif ( ( $config['type'] ?? '' ) === 'repeater' ) {
						$repeater_key = $config['repeater'] ?? '';
						if ( ! isset( $settings[ $repeater_key ] ) || ! is_array( $settings[ $repeater_key ] ) ) {
							$settings[ $repeater_key ] = [];
						}
						$items = &$settings[ $repeater_key ];
						foreach ( $items as $item_index => &$item ) {
							if ( ! is_array( $item ) ) {
								continue;
							}
							foreach ( $config['item_text_fields'] ?? [] as $field_key ) {
								$value = $item[ $field_key ] ?? '';
								$value_str = is_string( $value ) ? $value : (string) $value;
								$norm = Normalizer::normalize( $value_str );
								if ( $find_norm !== '' && mb_strpos( $norm, $find_norm ) !== false ) {
									if ( strpos( $value_str, $find_raw ) !== false ) {
										$item[ $field_key ] = str_replace( $find_raw, $replace, $value_str );
									} else {
										$item[ $field_key ] = $replace;
									}
									$replaced = 1;
									unset( $item );
									return;
								}
							}
						}
						unset( $item );
					} elseif ( ( $config['type'] ?? '' ) === 'price_table' ) {
						foreach ( $config['text_fields'] ?? [] as $field_key ) {
							$value = $settings[ $field_key ] ?? '';
							$value_str = is_string( $value ) ? $value : (string) $value;
							$norm = Normalizer::normalize( $value_str );
							if ( $find_norm !== '' && mb_strpos( $norm, $find_norm ) !== false ) {
								if ( strpos( $value_str, $find_raw ) !== false ) {
									$settings[ $field_key ] = str_replace( $find_raw, $replace, $value_str );
								} else {
									$settings[ $field_key ] = $replace;
								}
								$replaced = 1;
								return;
							}
						}
						$features_field = $config['features_field'] ?? 'features';
						if ( isset( $settings[ $features_field ] ) && is_array( $settings[ $features_field ] ) ) {
							$features = &$settings[ $features_field ];
							foreach ( $features as $fi => $feature_text ) {
								$value_str = is_string( $feature_text ) ? $feature_text : (string) $feature_text;
								$norm = Normalizer::normalize( $value_str );
								if ( $find_norm !== '' && mb_strpos( $norm, $find_norm ) !== false ) {
									if ( strpos( $value_str, $find_raw ) !== false ) {
										$features[ $fi ] = str_replace( $find_raw, $replace, $value_str );
									} else {
										$features[ $fi ] = $replace;
									}
									$replaced = 1;
									return;
								}
							}
						}
					}
				}
			}

			$children = &$node['elements'];
			if ( is_array( $children ) && ! empty( $children ) ) {
				self::traverseReplace( $children, $find_norm, $find_raw, $replace, $widget_types, $path, $replaced );
				if ( $replaced > 0 ) {
					return;
				}
			}
		}
	}
}
