<?php

declare(strict_types=1);

namespace AiElementorSync\Rest\Controllers;

use AiElementorSync\Support\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for settings and UI log.
 */
final class SettingsController {

	private const OPTION_AI_SERVICE = 'ai_elementor_sync_ai_service_url';
	private const OPTION_LLM_REGISTER = 'ai_elementor_sync_llm_register_url';
	private const OPTION_SIDELOAD_IMAGES = 'ai_elementor_sync_sideload_images';
	private const DEFAULT_AI_SERVICE_URL = 'https://elementor-ui-edit-server.onrender.com/edits';

	/**
	 * GET /ai-elementor/v1/settings — return current AI service URL and LLM register URL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_settings( WP_REST_Request $request ): WP_REST_Response {
		try {
			$ai_url = get_option( self::OPTION_AI_SERVICE, self::DEFAULT_AI_SERVICE_URL );
			$llm_url = get_option( self::OPTION_LLM_REGISTER, '' );
			$ai_url = is_string( $ai_url ) ? trim( $ai_url ) : self::DEFAULT_AI_SERVICE_URL;
			if ( $ai_url === '' ) {
				$ai_url = self::DEFAULT_AI_SERVICE_URL;
			}
			$sideload = get_option( self::OPTION_SIDELOAD_IMAGES, false );
			return new WP_REST_Response( [
				'ai_service_url'     => $ai_url,
				'llm_register_url'   => is_string( $llm_url ) ? trim( $llm_url ) : '',
				'sideload_images'    => (bool) $sideload,
			], 200 );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( [
				'code'    => 'internal_error',
				'message' => $e->getMessage(),
			], 500 );
		}
	}

	/**
	 * POST /ai-elementor/v1/settings — update AI service URL and/or LLM register URL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}
		if ( array_key_exists( 'ai_service_url', $params ) ) {
			$val = is_string( $params['ai_service_url'] ) ? trim( $params['ai_service_url'] ) : '';
			update_option( self::OPTION_AI_SERVICE, $val, false );
		}
		if ( array_key_exists( 'llm_register_url', $params ) ) {
			$val = is_string( $params['llm_register_url'] ) ? trim( $params['llm_register_url'] ) : '';
			update_option( self::OPTION_LLM_REGISTER, $val, false );
		}
		if ( array_key_exists( 'sideload_images', $params ) ) {
			update_option( self::OPTION_SIDELOAD_IMAGES, ! empty( $params['sideload_images'] ), false );
		}
		return self::get_settings( $request );
	}

	/**
	 * GET /ai-elementor/v1/log — return UI log entries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_log( WP_REST_Request $request ): WP_REST_Response {
		try {
			$entries = Logger::get_ui_log();
			return new WP_REST_Response( [ 'entries' => $entries ], 200 );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( [
				'code'    => 'internal_error',
				'message' => $e->getMessage(),
			], 500 );
		}
	}

	/**
	 * POST /ai-elementor/v1/clear-log — clear UI log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function clear_log( WP_REST_Request $request ): WP_REST_Response {
		try {
			Logger::clear_ui_log();
			return new WP_REST_Response( [ 'ok' => true ], 200 );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( [
				'code'    => 'internal_error',
				'message' => $e->getMessage(),
			], 500 );
		}
	}
}
