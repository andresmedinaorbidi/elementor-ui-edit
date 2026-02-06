<?php

declare(strict_types=1);

namespace AiElementorSync\Services;

use AiElementorSync\Support\Logger;

/**
 * Calls an external AI edit service (your proxy). Sends dictionary + instruction,
 * expects JSON { edits: [{ id?, path?, new_text }] }. No API key or model in the plugin.
 */
final class LlmClient {

	private const OPTION_URL = 'ai_elementor_sync_ai_service_url';
	private const DEFAULT_SERVICE_URL = 'https://elementor-ui-edit-server.onrender.com/edits';
	private const DEFAULT_TIMEOUT = 30;

	/**
	 * Request edits from the external service: send dictionary + instruction + image_slots, expect { edits, error? }.
	 *
	 * @param array       $dictionary  Page dictionary (array of { id?, path, widget_type, text, link_url? }).
	 * @param string      $instruction User instruction (natural language).
	 * @param array|null  $image_slots Optional image slots (id, path, slot_type, image_url, image_id?); when provided, AI may return image edits.
	 * @return array{ edits: array<int, array{ id?: string, path?: string, new_text?: string, new_url?: string, new_link?: array, new_image_url?: string, new_attachment_id?: int, type?: string }>, error: string|null }
	 */
	public static function requestEdits( array $dictionary, string $instruction, array $image_slots = [] ): array {
		$url = self::get_service_url();
		if ( $url === '' ) {
			Logger::log( 'AI edit service not configured: missing service URL.' );
			Logger::log_ui( 'error', 'AI edit service not configured: missing service URL.', [] );
			return [ 'edits' => [], 'error' => 'AI edit service not configured.' ];
		}

		$body = [
			'dictionary'       => $dictionary,
			'instruction'      => $instruction,
			'edit_capabilities'=> [ 'text', 'url', 'image' ],
		];
		if ( ! empty( $image_slots ) ) {
			$body['image_slots'] = $image_slots;
		}

		Logger::log_ui( 'request', 'LLM request', [
			'url'            => $url,
			'dict_count'     => count( $dictionary ),
			'instruction_len' => strlen( $instruction ),
		] );

		$response = wp_remote_post(
			$url,
			[
				'timeout' => self::get_timeout(),
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			$err_msg = $response->get_error_message();
			Logger::log( 'AI edit service request failed', [ 'error' => $err_msg ] );
			Logger::log_ui( 'error', 'LLM request failed', [ 'error' => $err_msg ] );
			return [ 'edits' => [], 'error' => 'AI edit service request failed.' ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			Logger::log( 'AI edit service returned non-2xx', [ 'code' => $code ] );
			$raw_body = wp_remote_retrieve_body( $response );
			$snippet = strlen( $raw_body ) > 200 ? substr( $raw_body, 0, 200 ) . '...' : $raw_body;
			Logger::log_ui( 'error', 'LLM response non-2xx', [ 'code' => $code, 'body_snippet' => $snippet ] );
			return [ 'edits' => [], 'error' => 'AI edit service response invalid.' ];
		}

		$raw_body = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );
		if ( ! is_array( $decoded ) ) {
			Logger::log( 'AI edit service response body is not valid JSON.' );
			Logger::log_ui( 'error', 'LLM response invalid JSON', [ 'body_snippet' => strlen( $raw_body ) > 200 ? substr( $raw_body, 0, 200 ) . '...' : $raw_body ] );
			return [ 'edits' => [], 'error' => 'AI edit service response invalid.' ];
		}

		if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) && $decoded['error'] !== '' ) {
			Logger::log( 'AI edit service returned error', [ 'message' => $decoded['error'] ] );
			Logger::log_ui( 'error', 'LLM service returned error', [ 'message' => $decoded['error'] ] );
			return [ 'edits' => [], 'error' => $decoded['error'] ];
		}

		// Accept "edits", "changes", or "results" so different LLM apps can work.
		$raw_edits = $decoded['edits'] ?? $decoded['changes'] ?? $decoded['results'] ?? null;
		$raw_edits_count = is_array( $raw_edits ) ? count( $raw_edits ) : 0;
		if ( ! is_array( $raw_edits ) ) {
			Logger::log_ui( 'response', 'LLM response: edits missing or not array', [
				'raw_edits_count'   => 0,
				'edits_type'        => gettype( $raw_edits ),
				'response_keys'     => array_keys( $decoded ),
				'body_snippet'       => strlen( $raw_body ) > 500 ? substr( $raw_body, 0, 500 ) . '...' : $raw_body,
			] );
			$raw_edits = [];
		}
		$edits = self::normalize_edits( $raw_edits );

		if ( $raw_edits_count === 0 ) {
			Logger::log_ui( 'response', 'LLM returned zero edits (check response shape in Log)', [
				'response_keys' => array_keys( $decoded ),
				'body_snippet'  => strlen( $raw_body ) > 500 ? substr( $raw_body, 0, 500 ) . '...' : $raw_body,
			] );
		}

		if ( $raw_edits_count > 0 && count( $edits ) === 0 ) {
			$sample = array_slice( $raw_edits, 0, 2 );
			Logger::log_ui( 'response', 'LLM edits normalized to zero (check keys: id/path, new_text)', [
				'raw_edits_count' => $raw_edits_count,
				'sample'          => $sample,
			] );
		}

		Logger::log_ui( 'response', 'LLM response OK', [
			'raw_edits_count'   => $raw_edits_count,
			'normalized_count'  => count( $edits ),
		] );

		$out = [
			'edits'           => $edits,
			'raw_edits_count' => $raw_edits_count,
			'error'           => null,
		];
		if ( $raw_edits_count === 0 ) {
			$out['response_keys'] = array_keys( $decoded );
		}
		return $out;
	}

	/**
	 * Request kit edits from the external service: send kit_settings + instruction, expect { kit_patch }.
	 * Body: context_type: 'kit', kit_settings: { colors, typography }, instruction.
	 * Response: kit_patch (or kit_edits / patch) — object with optional colors, typography, settings.
	 *
	 * @param array  $kit_settings Current kit settings: { colors: array, typography: array }.
	 * @param string $instruction User instruction (natural language).
	 * @return array{ kit_patch: array, error: string|null }
	 */
	public static function requestKitEdits( array $kit_settings, string $instruction ): array {
		$url = self::get_service_url();
		if ( $url === '' ) {
			Logger::log( 'AI edit service not configured: missing service URL.' );
			Logger::log_ui( 'error', 'AI edit service not configured: missing service URL.', [] );
			return [ 'kit_patch' => [], 'error' => 'AI edit service not configured.' ];
		}

		$body = [
			'context_type'  => 'kit',
			'kit_settings'  => $kit_settings,
			'instruction'  => $instruction,
		];

		Logger::log_ui( 'request', 'LLM kit edit request', [
			'url'            => $url,
			'instruction_len' => strlen( $instruction ),
		] );

		$response = wp_remote_post(
			$url,
			[
				'timeout' => self::get_timeout(),
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			$err_msg = $response->get_error_message();
			Logger::log( 'AI edit service request failed (kit)', [ 'error' => $err_msg ] );
			Logger::log_ui( 'error', 'LLM kit request failed', [ 'error' => $err_msg ] );
			return [ 'kit_patch' => [], 'error' => 'AI edit service request failed.' ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			Logger::log( 'AI edit service returned non-2xx (kit)', [ 'code' => $code ] );
			$raw_body = wp_remote_retrieve_body( $response );
			$snippet = strlen( $raw_body ) > 200 ? substr( $raw_body, 0, 200 ) . '...' : $raw_body;
			Logger::log_ui( 'error', 'LLM kit response non-2xx', [ 'code' => $code, 'body_snippet' => $snippet ] );
			return [ 'kit_patch' => [], 'error' => 'AI edit service response invalid.' ];
		}

		$raw_body = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );
		if ( ! is_array( $decoded ) ) {
			Logger::log( 'AI edit service response body is not valid JSON (kit).' );
			Logger::log_ui( 'error', 'LLM kit response invalid JSON', [ 'body_snippet' => strlen( $raw_body ) > 200 ? substr( $raw_body, 0, 200 ) . '...' : $raw_body ] );
			return [ 'kit_patch' => [], 'error' => 'AI edit service response invalid.' ];
		}

		if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) && $decoded['error'] !== '' ) {
			Logger::log( 'AI edit service returned error (kit)', [ 'message' => $decoded['error'] ] );
			Logger::log_ui( 'error', 'LLM kit service returned error', [ 'message' => $decoded['error'] ] );
			return [ 'kit_patch' => [], 'error' => $decoded['error'] ];
		}

		$kit_patch = $decoded['kit_patch'] ?? $decoded['kit_edits'] ?? $decoded['patch'] ?? null;
		if ( ! is_array( $kit_patch ) ) {
			Logger::log_ui( 'response', 'LLM kit response: kit_patch missing or not object', [
				'response_keys' => array_keys( $decoded ),
			] );
			return [ 'kit_patch' => [], 'error' => null ];
		}

		Logger::log_ui( 'response', 'LLM kit response OK', [ 'kit_patch_keys' => array_keys( $kit_patch ) ] );
		return [ 'kit_patch' => $kit_patch, 'error' => null ];
	}

	/**
	 * Normalize and validate edits: each item must have at least one of id or path, and at least one of new_text, new_url/new_link, or new_image_url/new_attachment_id/new_image.
	 * Passes through field, item_index; sets type (text, url, image) so apply_edits_to_data branches correctly.
	 *
	 * @param array $edits Raw parsed array.
	 * @return array<int, array{ id?: string, path?: string, field?: string, item_index?: int, type?: string, new_text?: string, new_url?: string, new_link?: array, new_image_url?: string, new_attachment_id?: int|null }>
	 */
	private static function normalize_edits( array $edits ): array {
		$out = [];
		foreach ( $edits as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = isset( $item['id'] ) && is_string( $item['id'] ) ? trim( $item['id'] ) : '';
			$path = isset( $item['path'] ) && is_string( $item['path'] ) ? trim( $item['path'] ) : '';
			if ( $id === '' && $path === '' ) {
				continue;
			}
			$new_text = array_key_exists( 'new_text', $item ) ? $item['new_text'] : null;
			$new_url = array_key_exists( 'new_url', $item ) ? $item['new_url'] : null;
			$new_link = array_key_exists( 'new_link', $item ) && is_array( $item['new_link'] ) ? $item['new_link'] : null;
			$new_image = array_key_exists( 'new_image', $item ) && is_array( $item['new_image'] ) ? $item['new_image'] : null;
			$new_image_url = array_key_exists( 'new_image_url', $item ) ? $item['new_image_url'] : null;
			$new_attachment_id_raw = array_key_exists( 'new_attachment_id', $item ) ? $item['new_attachment_id'] : null;
			if ( $new_image !== null ) {
				$new_image_url = $new_image_url ?? ( isset( $new_image['url'] ) && is_string( $new_image['url'] ) ? $new_image['url'] : null );
				$new_attachment_id_raw = $new_attachment_id_raw ?? ( array_key_exists( 'id', $new_image ) ? $new_image['id'] : null );
			}
			$new_attachment_id = self::normalize_attachment_id( $new_attachment_id_raw );
			$has_text = $new_text !== null;
			$has_url = ( $new_url !== null && ( is_string( $new_url ) || is_numeric( $new_url ) ) ) || ( $new_link !== null && isset( $new_link['url'] ) );
			$has_image = ( $new_image_url !== null ) || ( $new_attachment_id_raw !== null );
			if ( ! $has_text && ! $has_url && ! $has_image ) {
				continue;
			}
			$entry = [];
			if ( $id !== '' ) {
				$entry['id'] = $id;
			}
			if ( $path !== '' ) {
				$entry['path'] = $path;
			}
			if ( isset( $item['field'] ) && is_string( $item['field'] ) && $item['field'] !== '' ) {
				$entry['field'] = trim( $item['field'] );
			}
			if ( isset( $item['item_index'] ) && is_numeric( $item['item_index'] ) ) {
				$entry['item_index'] = (int) $item['item_index'];
			}
			// One type per edit: image takes precedence, then url, then text.
			if ( $has_image ) {
				$entry['type'] = 'image';
				$entry['new_image_url'] = $new_image_url !== null ? (string) $new_image_url : '';
				$entry['new_attachment_id'] = $new_attachment_id;
			} elseif ( $has_url ) {
				$entry['type'] = 'url';
				if ( $new_url !== null && ( is_string( $new_url ) || is_numeric( $new_url ) ) ) {
					$entry['new_url'] = (string) $new_url;
				}
				if ( $new_link !== null && isset( $new_link['url'] ) ) {
					$entry['new_link'] = $new_link;
				}
			} else {
				$entry['type'] = 'text';
				$entry['new_text'] = is_string( $new_text ) ? $new_text : (string) $new_text;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Normalize attachment ID: integer or numeric string; empty/invalid = null.
	 *
	 * @param mixed $raw Raw value from AI response.
	 * @return int|null Positive int or null.
	 */
	private static function normalize_attachment_id( $raw ): ?int {
		if ( $raw === null || $raw === '' ) {
			return null;
		}
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : null;
		}
		if ( is_string( $raw ) && is_numeric( $raw ) ) {
			$n = (int) $raw;
			return $n > 0 ? $n : null;
		}
		return null;
	}

	private static function get_service_url(): string {
		if ( defined( 'AI_ELEMENTOR_SYNC_AI_SERVICE_URL' ) && is_string( AI_ELEMENTOR_SYNC_AI_SERVICE_URL ) ) {
			return trim( AI_ELEMENTOR_SYNC_AI_SERVICE_URL );
		}
		$url = get_option( self::OPTION_URL, self::DEFAULT_SERVICE_URL );
		$url = is_string( $url ) ? $url : self::DEFAULT_SERVICE_URL;
		if ( function_exists( 'apply_filters' ) ) {
			$url = (string) apply_filters( 'ai_elementor_sync_ai_service_url', $url );
		}
		return trim( $url );
	}

	private static function get_timeout(): int {
		if ( function_exists( 'apply_filters' ) ) {
			$t = apply_filters( 'ai_elementor_sync_ai_service_timeout', self::DEFAULT_TIMEOUT );
			return is_numeric( $t ) ? max( 5, (int) $t ) : self::DEFAULT_TIMEOUT;
		}
		return self::DEFAULT_TIMEOUT;
	}
}
