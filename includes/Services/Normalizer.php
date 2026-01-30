<?php

declare(strict_types=1);

namespace AiElementorSync\Services;

/**
 * Normalizes text for exact visible-string comparison. Used only for matching; never for saving.
 */
final class Normalizer {

	/**
	 * Normalize text: decode entities, strip tags, collapse whitespace, trim.
	 * Case-sensitive for v1.
	 *
	 * @param string|null $text Raw text (e.g. HTML or plain).
	 * @return string
	 */
	public static function normalize( ?string $text ): string {
		if ( $text === null || $text === '' ) {
			return '';
		}
		$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$stripped = wp_strip_all_tags( $decoded );
		$collapsed = (string) preg_replace( '/\s+/', ' ', $stripped );
		return trim( $collapsed );
	}

	/**
	 * Build a short preview snippet from normalized text (e.g. 80–140 chars).
	 *
	 * @param string $normalized_text Normalized text.
	 * @param int    $max_length      Max length (default 140).
	 * @return string
	 */
	public static function preview_snippet( string $normalized_text, int $max_length = 140 ): string {
		$trimmed = trim( $normalized_text );
		if ( mb_strlen( $trimmed ) <= $max_length ) {
			return $trimmed;
		}
		return mb_substr( $trimmed, 0, $max_length - 3 ) . '...';
	}
}
