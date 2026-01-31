<?php

declare(strict_types=1);

namespace AiElementorSync;

use AiElementorSync\Admin\AdminPage;
use AiElementorSync\Rest\Routes;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Plugin bootstrap. Registers REST routes on rest_api_init.
 */
final class Plugin {

	/**
	 * REST route paths that accept JSON body with user text (used to scope encoding fix).
	 *
	 * @var array<string>
	 */
	private const ROUTES_WITH_JSON_BODY = [
		'/ai-elementor/v1/replace-text',
		'/ai-elementor/v1/llm-edit',
		'/ai-elementor/v1/apply-edits',
	];

	/**
	 * Initialize the plugin.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );
		add_filter( 'rest_pre_dispatch', [ self::class, 'fix_request_body_encoding' ], 10, 3 );
		AdminPage::init();
	}

	/**
	 * Fix request body encoding for our route when JSON is invalid UTF-8 (e.g. Latin-1 from curl).
	 *
	 * @param mixed           $result  Response to short-circuit, or null.
	 * @param WP_REST_Server  $server  REST server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed Unchanged $result.
	 */
	public static function fix_request_body_encoding( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		if ( ! in_array( $request->get_route(), self::ROUTES_WITH_JSON_BODY, true ) ) {
			return $result;
		}
		$body = $request->get_body();
		if ( $body === '' || $body === null ) {
			return $result;
		}
		if ( mb_check_encoding( $body, 'UTF-8' ) ) {
			return $result;
		}
		$fixed = false;
		foreach ( [ 'Windows-1252', 'ISO-8859-1' ] as $from ) {
			$converted = @mb_convert_encoding( $body, 'UTF-8', $from );
			if ( $converted !== false && mb_check_encoding( $converted, 'UTF-8' ) ) {
				$request->set_body( $converted );
				$fixed = true;
				break;
			}
		}
		if ( ! $fixed && function_exists( 'iconv' ) ) {
			$converted = @iconv( 'ISO-8859-1', 'UTF-8//IGNORE', $body );
			if ( $converted !== false ) {
				$request->set_body( $converted );
			}
		}
		return $result;
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_rest_routes(): void {
		Routes::register();
	}
}
