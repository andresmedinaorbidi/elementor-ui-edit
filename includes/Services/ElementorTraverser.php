<?php

declare(strict_types=1);

namespace AiElementorSync\Services;

/**
 * Traverses Elementor element tree, finds matching text in Heading/Text Editor widgets,
 * and optionally replaces when exactly one widget contains the find string (normalized).
 */
final class ElementorTraverser {

	/** @var array<string, string> widgetType => field key in settings */
	private const WIDGET_FIELDS = [
		'heading'     => 'title',
		'text-editor' => 'editor',
	];

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
	 * Build a per-page dictionary of widgets (id, path, text) for target widget types only.
	 * Used for AI edit service context; full text per widget, not just preview.
	 *
	 * @param array $data         Elementor data (document with "content" or raw elements array).
	 * @param array $widget_types Widget types to include (e.g. ['text-editor','heading']).
	 * @param int   $max_text_len Optional max length per widget text for token limits (0 = no limit).
	 * @return array<int, array{ id: string, path: string, widget_type: string, text: string }>
	 */
	public static function buildPageDictionary( array $data, array $widget_types = [ 'text-editor', 'heading' ], int $max_text_len = 0 ): array {
		$elements = self::getElementsRoot( $data );
		$dictionary = [];
		self::traverseBuildDictionary( $elements, $widget_types, [], $dictionary, $max_text_len );
		return $dictionary;
	}

	/**
	 * Replace the text of a widget at the given path. Path format: "0/1/2" (element indices).
	 *
	 * @param array  $data         Elementor data (passed by reference). Mutated if path is valid.
	 * @param string $path         Path string from dictionary (e.g. "0/1/2").
	 * @param string $new_text     New text to set (raw; for heading = title, for text-editor = editor HTML).
	 * @param array  $widget_types Allowed widget types (e.g. ['text-editor','heading']).
	 * @return bool True if replacement was applied, false if path invalid or not a target widget.
	 */
	public static function replaceByPath( array &$data, string $path, string $new_text, array $widget_types ): bool {
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
		$field = self::WIDGET_FIELDS[ $widget_type ] ?? null;
		if ( $field === null ) {
			return false;
		}
		if ( ! is_array( $node['settings'] ?? null ) ) {
			$node['settings'] = [];
		}
		$node['settings'][ $field ] = $new_text;
		return true;
	}

	/**
	 * Replace the text of a widget with the given Elementor id. Id is stable across reorders.
	 * Implemented by resolving id to path then calling replaceByPath so the mutation uses the same
	 * code path as path-based edits (avoids reference bugs with getNodeById in recursion).
	 *
	 * @param array  $data         Elementor data (passed by reference). Mutated if id is valid.
	 * @param string $id           Element id from dictionary (e.g. from $node['id']).
	 * @param string $new_text     New text to set (raw; for heading = title, for text-editor = editor HTML).
	 * @param array  $widget_types Allowed widget types (e.g. ['text-editor','heading']).
	 * @return bool True if replacement was applied, false if id invalid or not a target widget.
	 */
	public static function replaceById( array &$data, string $id, string $new_text, array $widget_types ): bool {
		$id = trim( $id );
		if ( $id === '' ) {
			return false;
		}
		$path = self::findPathById( $data, $id );
		if ( $path === null ) {
			return false;
		}
		return self::replaceByPath( $data, $path, $new_text, $widget_types );
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
	 * Recursively build dictionary entries (path, widget_type, full text) for target widgets.
	 *
	 * @param array $elements     Elements array.
	 * @param array $widget_types Allowed widget types.
	 * @param array $path_prefix  Current path indices.
	 * @param array $dictionary   Output: list of { path, widget_type, text }.
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

			if ( $el_type === 'widget' && $widget_type !== '' && in_array( $widget_type, $widget_types, true ) ) {
				$field = self::WIDGET_FIELDS[ $widget_type ] ?? null;
				if ( $field !== null ) {
					$settings = $node['settings'] ?? [];
					$value = $settings[ $field ] ?? '';
					$text = is_string( $value ) ? $value : (string) $value;
					if ( $max_text_len > 0 && mb_strlen( $text ) > $max_text_len ) {
						$text = mb_substr( $text, 0, $max_text_len ) . '...';
					}
					$el_id = isset( $node['id'] ) && is_string( $node['id'] ) ? $node['id'] : '';
					$dictionary[] = [
						'id'          => $el_id,
						'path'        => $path_str,
						'widget_type' => $widget_type,
						'text'        => $text,
					];
				}
			}

			$children = $node['elements'] ?? [];
			if ( is_array( $children ) && ! empty( $children ) ) {
				self::traverseBuildDictionary( $children, $widget_types, $path, $dictionary, $max_text_len );
			}
		}
	}

	/**
	 * Collect all heading/text-editor fields from the tree (for diagnostics). Does not mutate data.
	 *
	 * @param array $data         Elementor data (document with "content" or raw elements array).
	 * @param array $widget_types Widget types to include (e.g. ['text-editor','heading']).
	 * @return array{ data_structure: string, elements_count: int, text_fields: array }
	 */
	public static function collectAllTextFields( array $data, array $widget_types = [ 'text-editor', 'heading' ] ): array {
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
	 * Recursively collect every heading/text-editor field (widget_type, field, preview, path).
	 *
	 * @param array $elements     Elements array.
	 * @param array $widget_types Allowed widget types.
	 * @param array $path_prefix  Current path.
	 * @param array $text_fields  Output: list of { widget_type, field, preview, path }.
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
			$el_type = $node['elType'] ?? '';
			$widget_type = $node['widgetType'] ?? '';

			if ( $el_type === 'widget' && $widget_type !== '' && in_array( $widget_type, $widget_types, true ) ) {
				$field = self::WIDGET_FIELDS[ $widget_type ] ?? null;
				if ( $field !== null ) {
					$settings = $node['settings'] ?? [];
					$value = $settings[ $field ] ?? '';
					$value_str = is_string( $value ) ? $value : (string) $value;
					$norm = Normalizer::normalize( $value_str );
					$el_id = isset( $node['id'] ) && is_string( $node['id'] ) ? $node['id'] : '';
					$text_fields[] = [
						'id'          => $el_id,
						'widget_type' => $widget_type,
						'field'       => $field,
						'preview'     => Normalizer::preview_snippet( $norm ),
						'path'        => implode( '/', array_map( 'strval', $path ) ),
					];
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
	 *
	 * @param array  $elements     Elements array (may be root or children).
	 * @param string $find_norm    Normalized find string.
	 * @param string $find_raw     Raw find string (for reference).
	 * @param array  $widget_types Allowed widget types.
	 * @param array  $path_prefix  Current path indices.
	 * @param array  $candidates   Output: list of { id, widget_type, field, preview, path }.
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
			$el_type = $node['elType'] ?? '';
			$widget_type = $node['widgetType'] ?? '';

			if ( $el_type === 'widget' && $widget_type !== '' && in_array( $widget_type, $widget_types, true ) ) {
				$field = self::WIDGET_FIELDS[ $widget_type ] ?? null;
				if ( $field !== null ) {
					$settings = $node['settings'] ?? [];
					$value = $settings[ $field ] ?? '';
					$value_str = is_string( $value ) ? $value : (string) $value;
					$norm = Normalizer::normalize( $value_str );
					if ( $find_norm !== '' && mb_strpos( $norm, $find_norm ) !== false ) {
						$match_count++;
						$el_id = isset( $node['id'] ) && is_string( $node['id'] ) ? $node['id'] : '';
						$candidates[] = [
							'id'          => $el_id,
							'widget_type' => $widget_type,
							'field'       => $field,
							'preview'     => Normalizer::preview_snippet( $norm ),
							'path'        => implode( '/', array_map( 'strval', $path ) ),
						];
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
	 * Pass 2: find the single matching node (containment) and apply replacement (only when exactly one match).
	 * If raw contains find_raw, replace that substring; else replace entire widget value.
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
				$field = self::WIDGET_FIELDS[ $widget_type ] ?? null;
				if ( $field !== null ) {
					$settings = &$node['settings'];
					if ( ! is_array( $settings ) ) {
						$settings = [];
					}
					$value = $settings[ $field ] ?? '';
					$value_str = is_string( $value ) ? $value : (string) $value;
					$norm = Normalizer::normalize( $value_str );
					if ( $find_norm !== '' && mb_strpos( $norm, $find_norm ) !== false ) {
						if ( strpos( $value_str, $find_raw ) !== false ) {
							$settings[ $field ] = str_replace( $find_raw, $replace, $value_str );
						} else {
							$settings[ $field ] = $replace;
						}
						$replaced = 1;
						return;
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
