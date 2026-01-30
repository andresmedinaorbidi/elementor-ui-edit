<?php

declare(strict_types=1);

namespace AiElementorSync\Services;

/**
 * Traverses Elementor element tree, finds matching text in Heading/Text Editor widgets,
 * and optionally replaces when exactly one match exists.
 */
final class ElementorTraverser {

	/** @var array<string, string> widgetType => field key in settings */
	private const WIDGET_FIELDS = [
		'heading'     => 'title',
		'text-editor' => 'editor',
	];

	/**
	 * Find all matches and optionally replace if exactly one. Mutates $data in-place only when exactly one match.
	 * Supports both formats: root = array of elements, or root = document object with "content" key (Elementor format).
	 *
	 * @param array  $data         Elementor data (full document with "content" key, or raw elements array). Passed by reference.
	 * @param string $find         Exact visible string to find (normalized for comparison).
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
					$text_fields[] = [
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
	 * Pass 1: collect candidates and count matches (no replacement).
	 *
	 * @param array  $elements     Elements array (may be root or children).
	 * @param string $find_norm    Normalized find string.
	 * @param string $find_raw     Raw find string (for reference).
	 * @param array  $widget_types Allowed widget types.
	 * @param array  $path_prefix  Current path indices.
	 * @param array  $candidates   Output: list of { widget_type, field, preview, path }.
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
					if ( $norm === $find_norm ) {
						$match_count++;
						$candidates[] = [
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
	 * Pass 2: find the single matching node and apply replacement (only when exactly one match).
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
					if ( $norm === $find_norm ) {
						if ( $widget_type === 'heading' ) {
							$settings[ $field ] = $replace;
							$replaced = 1;
							return;
						}
						if ( $widget_type === 'text-editor' ) {
							// Only replace if raw HTML contains the exact find string.
							if ( strpos( $value_str, $find_raw ) !== false ) {
								$settings[ $field ] = str_replace( $find_raw, $replace, $value_str );
								$replaced = 1;
								return;
							}
							// Normalized match but raw not present: do not modify (treat as no replace).
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
