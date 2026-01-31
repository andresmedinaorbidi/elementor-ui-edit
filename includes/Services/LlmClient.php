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
			return [ 'edits' => [], 'error' => 'AI edit service not configured.' ];
		}

		$body = [
			'dictionary'  => $dictionary,
			'instruction' => $instruction,
		];

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
			Logger::log( 'AI edit service request failed', [ 'error' => $response->get_error_message() ] );
			return [ 'edits' => [], 'error' => 'AI edit service request failed.' ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			Logger::log( 'AI edit service returned non-2xx', [ 'code' => $code ] );
			return [ 'edits' => [], 'error' => 'AI edit service response invalid.' ];
		}

		$raw_body = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );
		if ( ! is_array( $decoded ) ) {
			Logger::log( 'AI edit service response body is not valid JSON.' );
			return [ 'edits' => [], 'error' => 'AI edit service response invalid.' ];
		}

		if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) && $decoded['error'] !== '' ) {
			Logger::log( 'AI edit service returned error', [ 'message' => $decoded['error'] ] );
			return [ 'edits' => [], 'error' => $decoded['error'] ];
		}

		$edits = $decoded['edits'] ?? null;
		if ( ! is_array( $edits ) ) {
			$edits = [];
		}

		return [
			'edits' => self::normalize_edits( $edits ),
			'error' => null,
		];
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
		$url = get_option( self::OPTION_URL, '' );
		$url = is_string( $url ) ? $url : '';
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
