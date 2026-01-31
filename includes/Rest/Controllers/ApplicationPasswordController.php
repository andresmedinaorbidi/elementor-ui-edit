<?php

declare(strict_types=1);

namespace AiElementorSync\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for creating an application password (for external tools).
 */
final class ApplicationPasswordController {

	private const APP_NAME = 'AI Elementor Sync';

	/**
	 * Handle POST /ai-elementor/v1/create-application-password.
	 * Returns the plain password once. Requires manage_options and logged-in user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function create_application_password( WP_REST_Request $request ): WP_REST_Response {
		if ( ! is_user_logged_in() ) {
			return new WP_REST_Response( [ 'code' => 'rest_not_logged_in', 'message' => __( 'Authentication required.', 'ai-elementor-sync' ) ], 401 );
		}

		$user_id = get_current_user_id();
		if ( $user_id === 0 ) {
			return new WP_REST_Response( [ 'code' => 'rest_forbidden', 'message' => __( 'User not found.', 'ai-elementor-sync' ) ], 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response( [ 'code' => 'rest_forbidden', 'message' => __( 'You do not have permission to create application passwords.', 'ai-elementor-sync' ) ], 403 );
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new WP_REST_Response( [ 'code' => 'app_passwords_unavailable', 'message' => __( 'Application passwords are not available.', 'ai-elementor-sync' ) ], 503 );
		}

		if ( ! function_exists( 'wp_is_application_passwords_available' ) ) {
			$available = true;
		} else {
			$available = wp_is_application_passwords_available();
		}
		if ( ! $available ) {
			return new WP_REST_Response( [ 'code' => 'app_passwords_disabled', 'message' => __( 'Application passwords are disabled for this site.', 'ai-elementor-sync' ) ], 503 );
		}

		if ( method_exists( 'WP_Application_Passwords', 'application_name_exists_for_user' ) ) {
			$exists = \WP_Application_Passwords::application_name_exists_for_user( $user_id, self::APP_NAME );
			if ( $exists ) {
				return new WP_REST_Response( [ 'code' => 'app_password_exists', 'message' => __( 'An application password with this name already exists. Revoke it from your profile if you need a new one.', 'ai-elementor-sync' ) ], 400 );
			}
		}

		$result = \WP_Application_Passwords::create_new_application_password( $user_id, [ 'name' => self::APP_NAME ] );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ], 500 );
		}

		list( $plain_password, $item ) = $result;
		$user = get_userdata( $user_id );
		$username = $user && $user->user_login ? $user->user_login : '';

		return new WP_REST_Response( [
			'password' => $plain_password,
			'username' => $username,
		], 200 );
	}
}
