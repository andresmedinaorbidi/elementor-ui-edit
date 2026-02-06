<?php

declare(strict_types=1);

namespace AiElementorSync\Rest;

use AiElementorSync\Rest\Controllers\ApplicationPasswordController;
use AiElementorSync\Rest\Controllers\KitSettingsController;
use AiElementorSync\Rest\Controllers\LlmEditController;
use AiElementorSync\Rest\Controllers\ReplaceTextController;
use AiElementorSync\Rest\Controllers\SettingsController;
use AiElementorSync\Rest\Controllers\TemplatesController;
use AiElementorSync\Services\EditTargetResolver;
use AiElementorSync\Services\ElementorTraverser;
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
		register_rest_route( self::NAMESPACE, 'list-templates', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ TemplatesController::class, 'list_templates' ],
			'permission_callback' => [ self::class, 'permission_list_templates' ],
			'args'                => [],
		] );

		register_rest_route( self::NAMESPACE, 'replace-text', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ ReplaceTextController::class, 'replace_text' ],
			'permission_callback' => [ self::class, 'permission_replace_text' ],
			'args'                => [
				'url'            => [
					'required' => false,
					'type'     => 'string',
					'description' => 'Page URL. One of url, template_id, or document_type is required.',
				],
				'template_id'    => [
					'required' => false,
					'type'     => 'integer',
					'description' => 'Elementor template post ID. One of url, template_id, or document_type is required.',
				],
				'document_type'  => [
					'required' => false,
					'type'     => 'string',
					'description' => 'Template document type (e.g. header, footer). One of url, template_id, or document_type is required.',
				],
				'slug'           => [
					'required' => false,
					'type'     => 'string',
					'description' => 'Optional template slug when using document_type.',
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
				'url'            => [
					'required' => false,
					'type'     => 'string',
					'description' => 'Page URL. One of url, template_id, or document_type is required.',
				],
				'template_id'    => [
					'required' => false,
					'type'     => 'integer',
					'description' => 'Elementor template post ID. One of url, template_id, or document_type is required.',
				],
				'document_type'  => [
					'required' => false,
					'type'     => 'string',
					'description' => 'Template document type (e.g. header, footer). One of url, template_id, or document_type is required.',
				],
				'slug'           => [
					'required' => false,
					'type'     => 'string',
					'description' => 'Optional template slug when using document_type.',
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
				'url'            => [
					'required' => false,
					'type'     => 'string',
					'description' => 'Page URL. One of url, template_id, or document_type is required.',
				],
				'template_id'    => [
					'required' => false,
					'type'     => 'integer',
					'description' => 'Elementor template post ID. One of url, template_id, or document_type is required.',
				],
				'document_type'  => [
					'required' => false,
					'type'     => 'string',
					'description' => 'Template document type (e.g. header, footer). One of url, template_id, or document_type is required.',
				],
				'slug'           => [
					'required' => false,
					'type'     => 'string',
					'description' => 'Optional template slug when using document_type.',
				],
				'instruction'   => [
					'required' => true,
					'type'     => 'string',
				],
				'target'        => [
					'required' => false,
					'type'     => 'string',
					'description' => 'Set to "kit" to edit global colors/typography (no url/template_id).',
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
				'url'            => [
					'required' => false,
					'type'     => 'string',
					'description' => 'Page URL. One of url, template_id, or document_type is required.',
				],
				'template_id'    => [
					'required' => false,
					'type'     => 'integer',
					'description' => 'Elementor template post ID. One of url, template_id, or document_type is required.',
				],
				'document_type'  => [
					'required' => false,
					'type'     => 'string',
					'description' => 'Template document type (e.g. header, footer). One of url, template_id, or document_type is required.',
				],
				'slug'           => [
					'required' => false,
					'type'     => 'string',
					'description' => 'Optional template slug when using document_type.',
				],
				'edits'         => [
					'required'    => true,
					'type'        => 'array',
					'description' => 'Each item: id or path; new_text or new_url/new_link (text/URL), or new_image_url/new_attachment_id/new_image (image). Image: new_image_url (string), new_attachment_id (int), or new_image: { url?, id? }.',
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

		register_rest_route( self::NAMESPACE, 'kit-settings', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ KitSettingsController::class, 'get_settings' ],
			'permission_callback' => [ self::class, 'permission_kit_settings' ],
			'args'                => [],
		] );
		register_rest_route( self::NAMESPACE, 'kit-settings', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ KitSettingsController::class, 'update_settings' ],
			'permission_callback' => [ self::class, 'permission_kit_settings' ],
			'args'                => [
				'colors'     => [ 'required' => false, 'type' => 'array', 'description' => 'Global colors (merged into system_colors).' ],
				'typography' => [ 'required' => false, 'type' => 'array', 'description' => 'Global typography (merged into system_typography).' ],
				'settings'   => [ 'required' => false, 'type' => 'object', 'description' => 'Arbitrary page_settings keys to merge.' ],
			],
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
				'sideload_images'  => [ 'required' => false, 'type' => 'boolean' ],
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
	 * Permission callback for list-templates: require auth and edit_posts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function permission_list_templates( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'Authentication required.', 'ai-elementor-sync' ), [ 'status' => 401 ] );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to list templates.', 'ai-elementor-sync' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * Permission callback for replace-text: require auth and edit_post on resolved post (page or template).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_replace_text( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'Authentication required.', 'ai-elementor-sync' ), [ 'status' => 401 ] );
		}
		$params = EditTargetResolver::getTargetParamsFromRequest( $request );
		$resolved = EditTargetResolver::fromRequest( $params );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$post_id = $resolved['post_id'];
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
	 * Permission callback for inspect: require auth and edit_post on resolved post (page or template).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_inspect( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'Authentication required.', 'ai-elementor-sync' ), [ 'status' => 401 ] );
		}
		$params = EditTargetResolver::getTargetParamsFromRequest( $request );
		$resolved = EditTargetResolver::fromRequest( $params );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$post_id = $resolved['post_id'];
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
	 * Permission callback for llm-edit: require auth; when target=kit require manage_options, else edit_post on resolved post (page or template).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_llm_edit( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'Authentication required.', 'ai-elementor-sync' ), [ 'status' => 401 ] );
		}
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}
		$target = isset( $params['target'] ) && is_string( $params['target'] ) ? trim( $params['target'] ) : '';
		if ( $target === 'kit' ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to edit kit settings.', 'ai-elementor-sync' ), [ 'status' => 403 ] );
			}
			return true;
		}
		$params = EditTargetResolver::getTargetParamsFromRequest( $request );
		$resolved = EditTargetResolver::fromRequest( $params );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$post_id = $resolved['post_id'];
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
	 * Permission callback for apply-edits: require auth and edit_post on resolved post (page or template).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_apply_edits( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'Authentication required.', 'ai-elementor-sync' ), [ 'status' => 401 ] );
		}
		$params = EditTargetResolver::getTargetParamsFromRequest( $request );
		$resolved = EditTargetResolver::fromRequest( $params );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$post_id = $resolved['post_id'];
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
	 * Permission callback for kit-settings: require manage_options.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function permission_kit_settings( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'Authentication required.', 'ai-elementor-sync' ), [ 'status' => 401 ] );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to manage kit settings.', 'ai-elementor-sync' ), [ 'status' => 403 ] );
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
