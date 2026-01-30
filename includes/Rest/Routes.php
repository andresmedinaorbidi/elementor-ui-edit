<?php

declare(strict_types=1);

namespace AiElementorSync\Rest;

use AiElementorSync\Rest\Controllers\ReplaceTextController;
use AiElementorSync\Services\UrlResolver;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Registers REST API routes for ai-elementor-sync.
 */
final class Routes {

	/**
	 * REST namespace.
	 */
	private const NAMESPACE = 'ai-elementor/v1';

	/**
	 * Register routes on rest_api_init.
	 */
	public static function register(): void {
		register_rest_route( self::NAMESPACE, 'replace-text', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ ReplaceTextController::class, 'replace_text' ],
			'permission_callback' => [ self::class, 'permission_replace_text' ],
			'args'                => [
				'url'           => [
					'required'          => true,
					'type'              => 'string',
					'validate_callback' => function ( $v ) {
						return is_string( $v ) && trim( $v ) !== '';
					},
				],
				'find'          => [
					'required'          => true,
					'type'              => 'string',
				],
				'replace'        => [
					'required' => true,
					'type'     => 'string',
				],
				'widget_types'  => [
					'required' => false,
					'type'     => 'array',
					'default'  => [ 'text-editor', 'heading' ],
				],
			],
		] );

		register_rest_route( self::NAMESPACE, 'inspect', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ ReplaceTextController::class, 'inspect' ],
			'permission_callback' => [ self::class, 'permission_inspect' ],
			'args'                => [
				'url' => [
					'required'          => true,
					'type'              => 'string',
					'validate_callback' => function ( $v ) {
						return is_string( $v ) && trim( $v ) !== '';
					},
				],
			],
		] );
	}

	/**
	 * Permission callback for replace-text: require auth and edit_post on resolved post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_replace_text( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'Authentication required.', 'ai-elementor-sync' ), [ 'status' => 401 ] );
		}

		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}
		$url = isset( $params['url'] ) && is_string( $params['url'] ) ? trim( $params['url'] ) : '';
		if ( $url === '' ) {
			return new \WP_Error( 'missing_url', __( 'URL is required.', 'ai-elementor-sync' ), [ 'status' => 400 ] );
		}

		$post_id = UrlResolver::resolve( $url );
		if ( $post_id === 0 ) {
			return new \WP_Error( 'url_unresolved', __( 'URL could not be resolved.', 'ai-elementor-sync' ), [ 'status' => 403 ] );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'ai-elementor-sync' ), [ 'status' => 404 ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to edit this post.', 'ai-elementor-sync' ), [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * Permission callback for inspect: require auth and edit_post on resolved post (url from query).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_inspect( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'Authentication required.', 'ai-elementor-sync' ), [ 'status' => 401 ] );
		}
		$url = $request->get_param( 'url' );
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( $url === '' ) {
			return new \WP_Error( 'missing_url', __( 'URL is required.', 'ai-elementor-sync' ), [ 'status' => 400 ] );
		}
		$post_id = UrlResolver::resolve( $url );
		if ( $post_id === 0 ) {
			return new \WP_Error( 'url_unresolved', __( 'URL could not be resolved.', 'ai-elementor-sync' ), [ 'status' => 403 ] );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'ai-elementor-sync' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to edit this post.', 'ai-elementor-sync' ), [ 'status' => 403 ] );
		}
		return true;
	}
}
