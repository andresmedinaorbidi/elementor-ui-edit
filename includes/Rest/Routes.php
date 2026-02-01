<?php

declare(strict_types=1);

namespace AiElementorSync\Rest;

use AiElementorSync\Rest\Controllers\ApplicationPasswordController;
use AiElementorSync\Rest\Controllers\LlmEditController;
use AiElementorSync\Rest\Controllers\ReplaceTextController;
use AiElementorSync\Rest\Controllers\SettingsController;
use AiElementorSync\Services\ElementorTraverser;
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
					'default'  => ElementorTraverser::SUPPORTED_WIDGET_TYPES,
				],
			],
		] );

		register_rest_route( self::NAMESPACE, 'inspect', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ ReplaceTextController::class, 'inspect' ],
			'permission_callback' => [ self::class, 'permission_inspect' ],
			'args'                => [
				'url'           => [
					'required'          => true,
					'type'              => 'string',
					'validate_callback' => function ( $v ) {
						return is_string( $v ) && trim( $v ) !== '';
					},
				],
				'widget_types'  => [
					'required' => false,
					'type'     => 'array',
					'default'  => ElementorTraverser::SUPPORTED_WIDGET_TYPES,
					'description' => 'Widget types to include (default: all supported types).',
				],
			],
		] );

		register_rest_route( self::NAMESPACE, 'llm-edit', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ LlmEditController::class, 'llm_edit' ],
			'permission_callback' => [ self::class, 'permission_llm_edit' ],
			'args'                => [
				'url'           => [
					'required'          => true,
					'type'              => 'string',
					'validate_callback' => function ( $v ) {
						return is_string( $v ) && trim( $v ) !== '';
					},
				],
				'instruction'   => [
					'required' => true,
					'type'     => 'string',
				],
				'widget_types'  => [
					'required' => false,
					'type'     => 'array',
					'default'  => ElementorTraverser::SUPPORTED_WIDGET_TYPES,
				],
			],
		] );

		register_rest_route( self::NAMESPACE, 'apply-edits', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ LlmEditController::class, 'apply_edits' ],
			'permission_callback' => [ self::class, 'permission_apply_edits' ],
			'args'                => [
				'url'           => [
					'required'          => true,
					'type'              => 'string',
					'validate_callback' => function ( $v ) {
						return is_string( $v ) && trim( $v ) !== '';
					},
				],
				'edits'         => [
					'required' => true,
					'type'     => 'array',
				],
				'widget_types'  => [
					'required' => false,
					'type'     => 'array',
					'default'  => ElementorTraverser::SUPPORTED_WIDGET_TYPES,
				],
			],
		] );

		register_rest_route( self::NAMESPACE, 'create-application-password', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ ApplicationPasswordController::class, 'create_application_password' ],
			'permission_callback' => [ self::class, 'permission_create_application_password' ],
			'args'                => [],
		] );

		register_rest_route( self::NAMESPACE, 'settings', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ SettingsController::class, 'get_settings' ],
			'permission_callback' => [ self::class, 'permission_manage_settings' ],
			'args'                => [],
		] );
		register_rest_route( self::NAMESPACE, 'settings', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ SettingsController::class, 'update_settings' ],
			'permission_callback' => [ self::class, 'permission_manage_settings' ],
			'args'                => [
				'ai_service_url'   => [ 'required' => false, 'type' => 'string' ],
				'llm_register_url' => [ 'required' => false, 'type' => 'string' ],
			],
		] );
		register_rest_route( self::NAMESPACE, 'log', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ SettingsController::class, 'get_log' ],
			'permission_callback' => [ self::class, 'permission_log' ],
			'args'                => [],
		] );
		register_rest_route( self::NAMESPACE, 'clear-log', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ SettingsController::class, 'clear_log' ],
			'permission_callback' => [ self::class, 'permission_log' ],
			'args'                => [],
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

	/**
	 * Permission callback for llm-edit: require auth and edit_post on resolved post (url from body).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_llm_edit( WP_REST_Request $request ) {
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
	 * Permission callback for apply-edits: require auth and edit_post on resolved post (url from body).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_apply_edits( WP_REST_Request $request ) {
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
	 * Permission callback for create-application-password: require auth and manage_options.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function permission_create_application_password( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'Authentication required.', 'ai-elementor-sync' ), [ 'status' => 401 ] );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to create application passwords.', 'ai-elementor-sync' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * Permission callback for settings: require manage_options.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function permission_manage_settings( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'Authentication required.', 'ai-elementor-sync' ), [ 'status' => 401 ] );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to manage settings.', 'ai-elementor-sync' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * Permission callback for log (view/clear): require edit_posts so editors can see logs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function permission_log( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'Authentication required.', 'ai-elementor-sync' ), [ 'status' => 401 ] );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to view the log.', 'ai-elementor-sync' ), [ 'status' => 403 ] );
		}
		return true;
	}
}
