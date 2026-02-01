<?php

declare(strict_types=1);

namespace AiElementorSync\Rest\Controllers;

use AiElementorSync\Services\CacheRegenerator;
use AiElementorSync\Services\ElementorDataStore;
use AiElementorSync\Services\ElementorTraverser;
use AiElementorSync\Services\UrlResolver;
use AiElementorSync\Support\Errors;
use AiElementorSync\Support\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for replace-text endpoint.
 */
final class ReplaceTextController {

	/**
	 * Handle POST /ai-elementor/v1/replace-text.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function replace_text( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}
		$url = isset( $params['url'] ) && is_string( $params['url'] ) ? trim( $params['url'] ) : '';
		$find = isset( $params['find'] ) && is_string( $params['find'] ) ? $params['find'] : null;
		$replace = array_key_exists( 'replace', $params ) && is_string( $params['replace'] ) ? $params['replace'] : null;
		$widget_types = isset( $params['widget_types'] ) && is_array( $params['widget_types'] ) ? $params['widget_types'] : ElementorTraverser::DEFAULT_WIDGET_TYPES;

		if ( $url === '' || $find === null || $replace === null ) {
			return Errors::error_response( 'Missing required parameters: url, find, replace.', 0, 400 );
		}

		$post_id = UrlResolver::resolve( $url );
		if ( $post_id === 0 ) {
			Logger::log( 'URL could not be resolved', [ 'url' => $url ] );
			return Errors::error_response( 'URL could not be resolved', 0, 400 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return Errors::error_response( 'Post not found.', $post_id, 404 );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Errors::forbidden( 'You do not have permission to edit this post.', $post_id );
		}

		$data = ElementorDataStore::get( $post_id );
		if ( $data === null ) {
			return Errors::error_response( 'No Elementor data found for this post.', $post_id, 400 );
		}

		$result = ElementorTraverser::findAndMaybeReplace( $data, $find, $replace, $widget_types );
		$matches_found = $result['matches_found'];
		$matches_replaced = $result['matches_replaced'];
		$candidates = $result['candidates'];
		$data = $result['data'];

		if ( $matches_found === 0 ) {
			return self::response( 'not_found', $post_id, 0, 0, [] );
		}

		if ( $matches_found > 1 ) {
			return self::response( 'ambiguous', $post_id, $matches_found, 0, $candidates );
		}

		// Exactly one match; replacement already applied in traverser.
		if ( $matches_replaced === 1 ) {
			$saved = ElementorDataStore::save( $post_id, $data );
			if ( ! $saved ) {
				Logger::log_ui( 'error', 'Failed to save Elementor data (replace-text).', [ 'post_id' => $post_id ] );
				return Errors::error_response( 'Failed to save Elementor data.', $post_id, 500 );
			}
			CacheRegenerator::regenerate( $post_id );
			Logger::log( 'Replaced text and saved', [ 'post_id' => $post_id ] );
		}

		return self::response( 'updated', $post_id, $matches_found, $matches_replaced, [] );
	}

	/**
	 * Handle GET /ai-elementor/v1/inspect?url=... — returns what we read from _elementor_data (for debugging).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function inspect( WP_REST_Request $request ): WP_REST_Response {
		$url = $request->get_param( 'url' );
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( $url === '' ) {
			return Errors::error_response( 'Missing required parameter: url.', 0, 400 );
		}

		$post_id = UrlResolver::resolve( $url );
		if ( $post_id === 0 ) {
			return Errors::error_response( 'URL could not be resolved', 0, 400 );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Errors::forbidden( 'You do not have permission to edit this post.', $post_id );
		}

		$data = ElementorDataStore::get( $post_id );
		if ( $data === null ) {
			return new WP_REST_Response( [
				'post_id'          => $post_id,
				'error'            => 'No Elementor data found for this post.',
				'data_structure'   => null,
				'elements_count'  => 0,
				'text_fields'      => [],
			], 200 );
		}

		$widget_types_param = $request->get_param( 'widget_types' );
		$widget_types = ElementorTraverser::DEFAULT_WIDGET_TYPES;
		if ( is_array( $widget_types_param ) && ! empty( $widget_types_param ) ) {
			$widget_types = array_values( array_filter( array_map( function ( $v ) {
				return is_string( $v ) ? trim( $v ) : null;
			}, $widget_types_param ) ) );
		} elseif ( is_string( $widget_types_param ) && $widget_types_param !== '' ) {
			$widget_types = array_values( array_filter( array_map( 'trim', explode( ',', $widget_types_param ) ) ) );
		}
		if ( empty( $widget_types ) ) {
			$widget_types = ElementorTraverser::DEFAULT_WIDGET_TYPES;
		}
		$info = ElementorTraverser::collectAllTextFields( $data, $widget_types );

		return new WP_REST_Response( [
			'post_id'         => $post_id,
			'data_structure'  => $info['data_structure'],
			'elements_count'  => $info['elements_count'],
			'text_fields'     => $info['text_fields'],
		], 200 );
	}

	/**
	 * Build success/non-error response with contract fields.
	 *
	 * @param string $status          updated | not_found | ambiguous.
	 * @param int    $post_id         Post ID.
	 * @param int    $matches_found   Number of matches.
	 * @param int    $matches_replaced Number replaced.
	 * @param array  $candidates      Candidates (only for ambiguous).
	 * @return WP_REST_Response
	 */
	private static function response( string $status, int $post_id, int $matches_found, int $matches_replaced, array $candidates ): WP_REST_Response {
		$body = [
			'status'            => $status,
			'post_id'           => $post_id,
			'matches_found'      => $matches_found,
			'matches_replaced'   => $matches_replaced,
		];
		if ( $status === 'ambiguous' && ! empty( $candidates ) ) {
			$body['candidates'] = $candidates;
		}
		return new WP_REST_Response( $body, 200 );
	}
}
