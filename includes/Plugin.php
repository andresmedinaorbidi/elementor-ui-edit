<?php

declare(strict_types=1);

namespace AiElementorSync;

use AiElementorSync\Rest\Routes;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Plugin bootstrap. Registers REST routes on rest_api_init.
 */
final class Plugin {

	/**
	 * REST route path for replace-text (used to scope encoding fix).
	 */
	private const REPLACE_TEXT_ROUTE = '/ai-elementor/v1/replace-text';

	/**
	 * Initialize the plugin.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );
		add_filter( 'rest_pre_dispatch', [ self::class, 'fix_request_body_encoding' ], 10, 3 );
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
		if ( $request->get_route() !== self::REPLACE_TEXT_ROUTE ) {
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
