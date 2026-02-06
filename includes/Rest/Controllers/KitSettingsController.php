<?php

declare(strict_types=1);

namespace AiElementorSync\Rest\Controllers;

use AiElementorSync\Services\KitStore;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for Elementor Kit settings (global colors, typography).
 */
final class KitSettingsController {

	/**
	 * GET /ai-elementor/v1/kit-settings — return active Kit page_settings (colors, typography).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_settings( WP_REST_Request $request ): WP_REST_Response {
		$kit_id = KitStore::getActiveKitId();
		if ( $kit_id === null ) {
			return new WP_REST_Response( [
				'code'    => 'no_active_kit',
				'message' => 'No active Elementor kit found.',
			], 404 );
		}
		$settings = KitStore::getPageSettings();
		if ( $settings === null ) {
			return new WP_REST_Response( [
				'code'    => 'invalid_settings',
				'message' => 'Kit page settings could not be read.',
			], 500 );
		}
		$colors     = KitStore::extractColors( $settings );
		$typography = KitStore::extractTypography( $settings );
		$colors     = KitStore::normalizeColorsForApi( $colors );
		return new WP_REST_Response( [
			'kit_id'       => $kit_id,
			'colors'       => $colors,
			'typography'   => $typography,
			'raw_settings' => $settings,
		], 200 );
	}

	/**
	 * POST /ai-elementor/v1/kit-settings — update Kit page_settings (partial: colors, typography, or settings).
	 *
	 * Body: { colors?, typography?, settings? }. Merged into current page_settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$kit_id = KitStore::getActiveKitId();
		if ( $kit_id === null ) {
			return new WP_REST_Response( [
				'code'    => 'no_active_kit',
				'message' => 'No active Elementor kit found.',
			], 404 );
		}
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}
		if ( ! is_array( $params ) ) {
			return new WP_REST_Response( [
				'code'    => 'invalid_payload',
				'message' => 'Request body must be a JSON object.',
			], 400 );
		}
		$patch = [];
		if ( isset( $params['colors'] ) && is_array( $params['colors'] ) ) {
			$patch['system_colors'] = $params['colors'];
		}
		if ( isset( $params['typography'] ) && is_array( $params['typography'] ) ) {
			$patch['system_typography'] = $params['typography'];
		}
		if ( isset( $params['settings'] ) && is_array( $params['settings'] ) ) {
			foreach ( $params['settings'] as $key => $value ) {
				if ( is_string( $key ) ) {
					$patch[ $key ] = $value;
				}
			}
		}
		if ( empty( $patch ) ) {
			return new WP_REST_Response( [
				'code'    => 'invalid_payload',
				'message' => 'Provide at least one of: colors, typography, or settings.',
			], 400 );
		}
		$updated = KitStore::updatePageSettings( $patch );
		if ( ! $updated ) {
			return new WP_REST_Response( [
				'code'    => 'save_failed',
				'message' => 'Failed to save kit settings.',
			], 500 );
		}
		return self::get_settings( $request );
	}
}
