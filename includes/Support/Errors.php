<?php

declare(strict_types=1);

namespace AiElementorSync\Support;

use WP_REST_Response;

/**
 * Helper to build consistent REST error responses.
 */
final class Errors {

	/**
	 * Build a REST response with status "error" and optional HTTP status code.
	 *
	 * @param string   $message   Error message.
	 * @param int      $post_id   Post ID (0 if unresolved).
	 * @param int      $http_code HTTP status code (400, 401, 403, 500).
	 * @return WP_REST_Response
	 */
	public static function error_response( string $message, int $post_id = 0, int $http_code = 400 ): WP_REST_Response {
		$body = [
			'status'          => 'error',
			'message'         => $message,
			'post_id'         => $post_id,
			'matches_found'   => 0,
			'matches_replaced' => 0,
		];
		$response = new WP_REST_Response( $body, $http_code );
		return $response;
	}

	/**
	 * 401 Unauthorized (not logged in).
	 *
	 * @return WP_REST_Response
	 */
	public static function unauthorized(): WP_REST_Response {
		return self::error_response( 'Authentication required.', 0, 401 );
	}

	/**
	 * 403 Forbidden (no edit_post capability or URL unresolved).
	 *
	 * @param string $message Optional message.
	 * @param int    $post_id Post ID (0 if URL unresolved).
	 * @return WP_REST_Response
	 */
	public static function forbidden( string $message = 'You do not have permission to edit this post.', int $post_id = 0 ): WP_REST_Response {
		return self::error_response( $message, $post_id, 403 );
	}
}
