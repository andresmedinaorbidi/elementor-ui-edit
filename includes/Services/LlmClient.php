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
	 * Request edits from the external service: send dictionary + instruction, expect { edits, error? }.
	 *
	 * @param array  $dictionary Page dictionary (array of { id?, path, widget_type, text }).
	 * @param string $instruction User instruction (natural language).
	 * @return array{ edits: array<int, array{ id?: string, path?: string, new_text: string }>, error: string|null }
	 */
	public static function requestEdits( array $dictionary, string $instruction ): array {
		$url = self::get_service_url();
		if ( $url === '' ) {
			Logger::log( 'AI edit service not configured: missing service URL.' );
			Logger::log_ui( 'error', 'AI edit service not configured: missing service URL.', [] );
			return [ 'edits' => [], 'error' => 'AI edit service not configured.' ];
		}

		$body = [
			'dictionary'  => $dictionary,
			'instruction' => $instruction,
		];

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
	 * Normalize and validate edits: each item must have new_text and at least one of id or path.
	 *
	 * @param array $edits Raw parsed array.
	 * @return array<int, array{ id?: string, path?: string, new_text: string }>
	 */
	private static function normalize_edits( array $edits ): array {
		$out = [];
		foreach ( $edits as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$new_text = array_key_exists( 'new_text', $item ) ? $item['new_text'] : null;
			if ( $new_text === null ) {
				continue;
			}
			$id = isset( $item['id'] ) && is_string( $item['id'] ) ? trim( $item['id'] ) : '';
			$path = isset( $item['path'] ) && is_string( $item['path'] ) ? trim( $item['path'] ) : '';
			if ( $id === '' && $path === '' ) {
				continue;
			}
			$entry = [ 'new_text' => is_string( $new_text ) ? $new_text : (string) $new_text ];
			if ( $id !== '' ) {
				$entry['id'] = $id;
			}
			if ( $path !== '' ) {
				$entry['path'] = $path;
			}
			$out[] = $entry;
		}
		return $out;
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
