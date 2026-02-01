<?php

declare(strict_types=1);

namespace AiElementorSync\Rest\Controllers;

use AiElementorSync\Services\CacheRegenerator;
use AiElementorSync\Services\ElementorDataStore;
use AiElementorSync\Services\ElementorTraverser;
use AiElementorSync\Services\LlmClient;
use AiElementorSync\Services\UrlResolver;
use AiElementorSync\Support\Errors;
use AiElementorSync\Support\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for LLM-based edit and direct apply-edits endpoints.
 */
final class LlmEditController {

	/**
	 * Handle POST /ai-elementor/v1/llm-edit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function llm_edit( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}
		$url = isset( $params['url'] ) && is_string( $params['url'] ) ? trim( $params['url'] ) : '';
		$instruction = isset( $params['instruction'] ) && is_string( $params['instruction'] ) ? $params['instruction'] : null;
		$widget_types = isset( $params['widget_types'] ) && is_array( $params['widget_types'] ) ? $params['widget_types'] : [ 'text-editor', 'heading' ];

		if ( $url === '' || $instruction === null ) {
			return Errors::error_response( 'Missing required parameters: url, instruction.', 0, 400 );
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

		$dictionary = ElementorTraverser::buildPageDictionary( $data, $widget_types );

		$result = LlmClient::requestEdits( $dictionary, $instruction );
		$edits = $result['edits'];
		$error = $result['error'] ?? null;

		if ( $error !== null ) {
			return new WP_REST_Response( [
				'status'  => 'error',
				'message' => $error,
				'post_id' => $post_id,
			], 502 );
		}

		return self::apply_edits_to_data( $data, $post_id, $edits, $widget_types );
	}

	/**
	 * Handle POST /ai-elementor/v1/apply-edits (direct apply, no LLM).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function apply_edits( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}
		$url = isset( $params['url'] ) && is_string( $params['url'] ) ? trim( $params['url'] ) : '';
		$edits = isset( $params['edits'] ) && is_array( $params['edits'] ) ? $params['edits'] : null;
		$widget_types = isset( $params['widget_types'] ) && is_array( $params['widget_types'] ) ? $params['widget_types'] : [ 'text-editor', 'heading' ];

		if ( $url === '' || $edits === null ) {
			return Errors::error_response( 'Missing required parameters: url, edits.', 0, 400 );
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

		$normalized = [];
		foreach ( $edits as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$new_text = array_key_exists( 'new_text', $item ) ? $item['new_text'] : null;
			if ( $new_text === null ) {
				continue;
			}
			$id = isset( $item['id'] ) && is_string( $item['id'] ) ? trim( $item['id'] ) : '';
			$path = isset( $item['path'] ) && is_string( $item['path'] ) ? trim( $item['path'] ) : '';
			if ( $id === '' && $path === '' ) {
				continue;
			}
			$entry = [ 'new_text' => is_string( $new_text ) ? $new_text : (string) $new_text ];
			if ( $id !== '' ) {
				$entry['id'] = $id;
			}
			if ( $path !== '' ) {
				$entry['path'] = $path;
			}
			$normalized[] = $entry;
		}

		return self::apply_edits_to_data( $data, $post_id, $normalized, $widget_types );
	}

	/**
	 * Apply a list of edits to in-memory data, save once if any applied, return response.
	 * Each edit may have id (preferred) and/or path; prefer id when present.
	 *
	 * @param array $data         Elementor data (passed by reference; will be mutated).
	 * @param int   $post_id      Post ID.
	 * @param array $edits        List of { id?, path?, new_text }.
	 * @param array $widget_types Allowed widget types.
	 * @return WP_REST_Response
	 */
	private static function apply_edits_to_data( array &$data, int $post_id, array $edits, array $widget_types ): WP_REST_Response {
		$applied_count = 0;
		$failed = [];

		foreach ( $edits as $edit ) {
			$new_text = $edit['new_text'];
			$id = $edit['id'] ?? '';
			$path = $edit['path'] ?? '';
			$ok = false;
			if ( $id !== '' ) {
				$ok = ElementorTraverser::replaceById( $data, $id, $new_text, $widget_types );
			}
			if ( ! $ok && $path !== '' ) {
				$ok = ElementorTraverser::replaceByPath( $data, $path, $new_text, $widget_types );
			}
			if ( $ok ) {
				$applied_count++;
			} else {
				$failed[] = [
					'id'    => $id !== '' ? $id : null,
					'path'  => $path !== '' ? $path : null,
					'error' => 'Invalid id/path or not a target widget.',
				];
			}
		}

		if ( $applied_count > 0 ) {
			$saved = ElementorDataStore::save( $post_id, $data );
			if ( ! $saved ) {
				Logger::log_ui( 'error', 'Failed to save Elementor data (apply-edits).', [ 'post_id' => $post_id, 'applied_count' => $applied_count ] );
				return Errors::error_response( 'Failed to save Elementor data.', $post_id, 500 );
			}
			CacheRegenerator::regenerate( $post_id );
			Logger::log( 'Applied edits and saved', [ 'post_id' => $post_id, 'applied_count' => $applied_count ] );
		}

		return new WP_REST_Response( [
			'status'         => 'ok',
			'post_id'        => $post_id,
			'applied_count'  => $applied_count,
			'failed'         => $failed,
		], 200 );
	}
}
