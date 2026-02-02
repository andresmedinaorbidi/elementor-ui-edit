<?php

declare(strict_types=1);

namespace AiElementorSync\Rest\Controllers;

use AiElementorSync\Services\CacheRegenerator;
use AiElementorSync\Services\ElementorDataStore;
use AiElementorSync\Services\ElementorTraverser;
use AiElementorSync\Services\ImageSideload;
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
		$widget_types = isset( $params['widget_types'] ) && is_array( $params['widget_types'] ) ? $params['widget_types'] : ElementorTraverser::DEFAULT_WIDGET_TYPES;

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
		$image_slots = ElementorTraverser::buildImageSlots( $data );

		$result = LlmClient::requestEdits( $dictionary, $instruction, $image_slots );
		$edits = $result['edits'];
		$raw_edits_count = $result['raw_edits_count'] ?? 0;
		$error = $result['error'] ?? null;

		$received_from_llm = [
			'raw_edits_count'        => $raw_edits_count,
			'normalized_edits_count' => count( $edits ),
			'edits'                  => $edits,
		];
		if ( isset( $result['response_keys'] ) && is_array( $result['response_keys'] ) ) {
			$received_from_llm['response_keys'] = $result['response_keys'];
		}

		if ( $error !== null ) {
			return new WP_REST_Response( [
				'status'             => 'error',
				'message'            => $error,
				'post_id'            => $post_id,
				'received_from_llm'  => $received_from_llm,
			], 502 );
		}

		$response = self::apply_edits_to_data( $data, $post_id, $edits, $widget_types );
		$body = $response->get_data();
		if ( is_array( $body ) ) {
			$body['received_from_llm'] = $received_from_llm;
			$response->set_data( $body );
		}
		return $response;
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
		$widget_types = isset( $params['widget_types'] ) && is_array( $params['widget_types'] ) ? $params['widget_types'] : ElementorTraverser::DEFAULT_WIDGET_TYPES;

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

		$normalized = self::normalize_edit_items( $edits );
		if ( empty( $normalized ) ) {
			return Errors::error_response( 'No valid edits: each item must have id or path, and new_text, new_url/new_link, or new_image_url/new_attachment_id.', $post_id, 400 );
		}

		return self::apply_edits_to_data( $data, $post_id, $normalized, $widget_types );
	}

	/**
	 * Normalize edit items: at least one of id/path; at least one of new_text, new_url/new_link, or new_image_url/new_attachment_id/new_image. Pass through field, item_index.
	 * Image edits: new_image_url (string), new_attachment_id (int), or new_image: { url?, id? }.
	 *
	 * @param array $edits Raw edits array from request.
	 * @return array<int, array{ id?: string, path?: string, field?: string, item_index?: int, new_text?: string, new_url?: string, new_link?: array, new_image_url?: string, new_attachment_id?: int|null, type?: string }>
	 */
	private static function normalize_edit_items( array $edits ): array {
		$out = [];
		foreach ( $edits as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = isset( $item['id'] ) && is_string( $item['id'] ) ? trim( $item['id'] ) : '';
			$path = isset( $item['path'] ) && is_string( $item['path'] ) ? trim( $item['path'] ) : '';
			if ( $id === '' && $path === '' ) {
				continue;
			}
			$new_text = array_key_exists( 'new_text', $item ) ? $item['new_text'] : null;
			$new_url = array_key_exists( 'new_url', $item ) ? $item['new_url'] : null;
			$new_link = array_key_exists( 'new_link', $item ) && is_array( $item['new_link'] ) ? $item['new_link'] : null;
			$new_image = array_key_exists( 'new_image', $item ) && is_array( $item['new_image'] ) ? $item['new_image'] : null;
			$new_image_url = array_key_exists( 'new_image_url', $item ) ? $item['new_image_url'] : null;
			$new_attachment_id_raw = array_key_exists( 'new_attachment_id', $item ) ? $item['new_attachment_id'] : null;
			if ( $new_image !== null ) {
				$new_image_url = $new_image_url ?? ( isset( $new_image['url'] ) && is_string( $new_image['url'] ) ? $new_image['url'] : null );
				$new_attachment_id_raw = $new_attachment_id_raw ?? ( array_key_exists( 'id', $new_image ) ? $new_image['id'] : null );
			}
			$new_attachment_id = self::normalize_attachment_id( $new_attachment_id_raw );
			$has_text = $new_text !== null;
			$has_url = ( $new_url !== null && ( is_string( $new_url ) || is_numeric( $new_url ) ) ) || ( $new_link !== null && isset( $new_link['url'] ) );
			$has_image = ( $new_image_url !== null ) || ( $new_attachment_id_raw !== null );
			if ( ! $has_text && ! $has_url && ! $has_image ) {
				continue;
			}
			$entry = [];
			if ( $id !== '' ) {
				$entry['id'] = $id;
			}
			if ( $path !== '' ) {
				$entry['path'] = $path;
			}
			if ( isset( $item['field'] ) && is_string( $item['field'] ) && $item['field'] !== '' ) {
				$entry['field'] = trim( $item['field'] );
			}
			if ( isset( $item['item_index'] ) && is_numeric( $item['item_index'] ) ) {
				$entry['item_index'] = (int) $item['item_index'];
			}
			// One type per edit: image takes precedence, then url, then text.
			if ( $has_image ) {
				$entry['type'] = 'image';
				$entry['new_image_url'] = $new_image_url !== null ? (string) $new_image_url : '';
				$entry['new_attachment_id'] = $new_attachment_id;
			} elseif ( $has_url ) {
				$entry['type'] = 'url';
				if ( $new_url !== null && ( is_string( $new_url ) || is_numeric( $new_url ) ) ) {
					$entry['new_url'] = (string) $new_url;
				}
				if ( $new_link !== null && isset( $new_link['url'] ) ) {
					$entry['new_link'] = $new_link;
				}
			} else {
				$entry['type'] = 'text';
				$entry['new_text'] = is_string( $new_text ) ? $new_text : (string) $new_text;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Normalize attachment ID: integer or numeric string; empty/invalid = null (clear id).
	 *
	 * @param mixed $raw Raw value from request.
	 * @return int|null Positive int or null.
	 */
	private static function normalize_attachment_id( $raw ): ?int {
		if ( $raw === null || $raw === '' ) {
			return null;
		}
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : null;
		}
		if ( is_string( $raw ) && is_numeric( $raw ) ) {
			$n = (int) $raw;
			return $n > 0 ? $n : null;
		}
		return null;
	}

	/**
	 * Apply a list of edits to in-memory data, save once if any applied, return response.
	 * Each edit may have id (preferred) and/or path; field and item_index optional. new_text or new_url/new_link.
	 *
	 * @param array $data         Elementor data (passed by reference; will be mutated).
	 * @param int   $post_id      Post ID.
	 * @param array $edits        List of { id?, path?, field?, item_index?, new_text?, new_url?, new_link? }.
	 * @param array $widget_types Allowed widget types.
	 * @return WP_REST_Response
	 */
	private static function apply_edits_to_data( array &$data, int $post_id, array $edits, array $widget_types ): WP_REST_Response {
		$applied_count = 0;
		$failed = [];
		$applied_edits = []; // Track for verification: id, path, modified_path, field?, item_index?, new_text? or new_url/new_link?, type.
		$applied_image_edits = [];

		foreach ( $edits as $edit ) {
			$edit_type = $edit['type'] ?? 'text';
			$id = $edit['id'] ?? '';
			$path = $edit['path'] ?? '';
			$field = isset( $edit['field'] ) && is_string( $edit['field'] ) ? $edit['field'] : null;
			$item_index = isset( $edit['item_index'] ) ? (int) $edit['item_index'] : null;

			if ( $edit_type === 'image' ) {
				$new_image_url = isset( $edit['new_image_url'] ) ? (string) $edit['new_image_url'] : '';
				$new_attachment_id = isset( $edit['new_attachment_id'] ) ? $edit['new_attachment_id'] : null;
				if ( $new_attachment_id !== null && (int) $new_attachment_id <= 0 ) {
					$new_attachment_id = null;
				} elseif ( $new_attachment_id !== null ) {
					$new_attachment_id = (int) $new_attachment_id;
				}
				if ( $new_image_url === '' && $new_attachment_id !== null && $new_attachment_id > 0 ) {
					$resolved = wp_get_attachment_url( $new_attachment_id );
					if ( is_string( $resolved ) && $resolved !== '' ) {
						$new_image_url = $resolved;
					}
				}
				// When only URL is provided and sideload is enabled, download and create attachment.
				if ( $new_image_url !== '' && ( $new_attachment_id === null || $new_attachment_id <= 0 ) ) {
					$sideload_enabled = (bool) get_option( 'ai_elementor_sync_sideload_images', false );
					if ( $sideload_enabled ) {
						$sideload_result = ImageSideload::sideload( $new_image_url, $post_id );
						if ( $sideload_result !== null ) {
							$new_image_url = $sideload_result['url'];
							$new_attachment_id = $sideload_result['id'];
						}
					}
				}
				$slot_type = null;
				if ( $id !== '' ) {
					$slot_type = ElementorTraverser::getImageSlotTypeById( $data, $id );
				}
				if ( $slot_type === null && $path !== '' ) {
					$slot_type = ElementorTraverser::getImageSlotTypeByPath( $data, $path );
				}
				$ok = false;
				if ( $slot_type !== null ) {
					if ( $id !== '' ) {
						$ok = ElementorTraverser::replaceImageById( $data, $id, $new_image_url, $new_attachment_id, $slot_type );
					}
					if ( ! $ok && $path !== '' ) {
						$ok = ElementorTraverser::replaceImageByPath( $data, $path, $new_image_url, $new_attachment_id, $slot_type );
					}
				}
				if ( $ok ) {
					$applied_count++;
					$modified_path = $id !== '' ? ElementorTraverser::findPathById( $data, $id ) : $path;
					$applied_image_edits[] = [
						'id'               => $id !== '' ? $id : null,
						'path'             => $path !== '' ? $path : null,
						'modified_path'    => $modified_path,
						'slot_type'        => $slot_type,
						'new_image_url'    => $new_image_url,
						'new_attachment_id'=> $new_attachment_id,
					];
				} else {
					$failed[] = [
						'id'     => $id !== '' ? $id : null,
						'path'   => $path !== '' ? $path : null,
						'error'  => 'Invalid id/path or not an image/background_image slot.',
						'reason' => $slot_type === null ? 'id_not_found_or_path_invalid' : 'unknown',
					];
				}
				continue;
			}

			$new_text = $edit['new_text'] ?? null;
			$new_url = $edit['new_url'] ?? null;
			$new_link = $edit['new_link'] ?? null;
			$is_url_edit = $new_url !== null || ( $new_link !== null && isset( $new_link['url'] ) );
			$url_value = $new_link !== null && isset( $new_link['url'] ) ? (string) $new_link['url'] : ( $new_url !== null ? (string) $new_url : '' );

			$ok = false;
			if ( $is_url_edit ) {
				if ( $id !== '' ) {
					$ok = ElementorTraverser::replaceUrlById( $data, $id, $new_link ?? $url_value, $widget_types, $item_index );
				}
				if ( ! $ok && $path !== '' ) {
					$ok = ElementorTraverser::replaceUrlByPath( $data, $path, $new_link ?? $url_value, $widget_types, $item_index );
				}
			} else {
				if ( $id !== '' ) {
					$ok = ElementorTraverser::replaceById( $data, $id, $new_text, $widget_types, $field, $item_index );
				}
				if ( ! $ok && $path !== '' ) {
					$ok = ElementorTraverser::replaceByPath( $data, $path, $new_text, $widget_types, $field, $item_index );
				}
			}

			if ( $ok ) {
				$applied_count++;
				$modified_path = $id !== '' ? ElementorTraverser::findPathById( $data, $id ) : $path;
				$applied_edits[] = array_merge(
					[
						'id'            => $id !== '' ? $id : null,
						'path'          => $path !== '' ? $path : null,
						'modified_path' => $modified_path,
						'type'          => $is_url_edit ? 'url' : 'text',
					],
					$field !== null ? [ 'field' => $field ] : [],
					$item_index !== null ? [ 'item_index' => $item_index ] : [],
					$is_url_edit ? [ 'new_url' => $url_value ] : [ 'new_text' => $new_text ],
				);
			} else {
				$reason = self::get_edit_failure_reason( $data, $id, $path, $widget_types );
				$failed[] = [
					'id'     => $id !== '' ? $id : null,
					'path'   => $path !== '' ? $path : null,
					'error'  => 'Invalid id/path or not a target widget.',
					'reason' => $reason,
				];
			}
		}

		$verified_in_memory_before_save = null;
		$verified_after_save = null;
		if ( $applied_count > 0 ) {
			// Confirm $data (in memory) has the new text before we save (diagnostic for reference bugs).
			$verified_in_memory_before_save = self::verify_applied_edits_in_data( $data, $applied_edits, $widget_types );

			$saved = ElementorDataStore::save( $post_id, $data );
			if ( ! $saved ) {
				Logger::log_ui( 'error', 'Failed to save Elementor data (apply-edits).', [ 'post_id' => $post_id, 'applied_count' => $applied_count ] );
				return Errors::error_response( 'Failed to save Elementor data.', $post_id, 500 );
			}
			CacheRegenerator::regenerate( $post_id );
			Logger::log( 'Applied edits and saved', [ 'post_id' => $post_id, 'applied_count' => $applied_count ] );

			// Force fresh read from DB (clear post meta cache) then verify.
			wp_cache_delete( $post_id, 'post_meta' );
			$verified_after_save = self::verify_applied_edits_in_meta( $post_id, $applied_edits, $widget_types );
		}

		$body = [
			'status'         => 'ok',
			'post_id'        => $post_id,
			'applied_count'  => $applied_count,
			'failed'         => $failed,
		];
		if ( ! empty( $applied_edits ) ) {
			$body['applied_edits'] = $applied_edits;
		}
		if ( ! empty( $applied_image_edits ) ) {
			$body['applied_image_edits'] = $applied_image_edits;
		}
		if ( $verified_in_memory_before_save !== null ) {
			$body['verified_in_memory_before_save'] = $verified_in_memory_before_save;
		}
		if ( $verified_after_save !== null ) {
			$body['verified_after_save'] = $verified_after_save;
		}
		return new WP_REST_Response( $body, 200 );
	}

	/**
	 * Check that $data (in memory) has each applied edit at the correct slot. Diagnostic for reference bugs.
	 *
	 * @param array $data          Elementor data (same array we're about to save).
	 * @param array $applied_edits  List of applied edits (type, field?, item_index?, new_text? or new_url?).
	 * @param array $widget_types  Allowed widget types.
	 * @return array{ all_verified: bool, details: array }
	 */
	private static function verify_applied_edits_in_data( array $data, array $applied_edits, array $widget_types ): array {
		$details = [];
		$all_verified = true;
		foreach ( $applied_edits as $applied ) {
			$id = $applied['id'] ?? '';
			$path = $applied['modified_path'] ?? $applied['path'] ?? '';
			$node = null;
			if ( $id !== '' && $id !== null ) {
				$node = ElementorTraverser::findNodeById( $data, $id );
			}
			if ( $node === null && $path !== '' ) {
				$node = ElementorTraverser::findNodeByPath( $data, $path );
			}
			$found_in_memory = false;
			if ( $node !== null && is_array( $node ) ) {
				$widget_type = $node['widgetType'] ?? '';
				$field = $applied['field'] ?? null;
				$item_index = isset( $applied['item_index'] ) ? (int) $applied['item_index'] : null;
				if ( ( $applied['type'] ?? '' ) === 'url' ) {
					$expected_url = $applied['new_url'] ?? '';
					$current = ElementorTraverser::getLinkUrlAtSlot( $node, $widget_type, $item_index );
					$found_in_memory = (string) $current === (string) $expected_url;
				} else {
					$expected_text = $applied['new_text'] ?? '';
					$current = ElementorTraverser::getTextAtSlot( $node, $widget_type, $field, $item_index );
					$found_in_memory = (string) $current === (string) $expected_text;
				}
			}
			$details[] = [
				'id'                => $id ?: null,
				'path'              => $path ?: null,
				'found_in_memory'   => $found_in_memory,
			];
			if ( ! $found_in_memory ) {
				$all_verified = false;
			}
		}
		return [ 'all_verified' => $all_verified, 'details' => $details ];
	}

	/**
	 * Re-read _elementor_data and check if each applied edit is present at the slot. Diagnostic for save/cache issues.
	 *
	 * @param int   $post_id        Post ID.
	 * @param array $applied_edits  List of applied edits (type, field?, item_index?, new_text? or new_url?).
	 * @param array $widget_types   Allowed widget types.
	 * @return array{ all_verified: bool, details: array } Details per edit: path/id, expected_snippet, found_in_meta.
	 */
	private static function verify_applied_edits_in_meta( int $post_id, array $applied_edits, array $widget_types ): array {
		$reloaded = ElementorDataStore::get( $post_id );
		if ( $reloaded === null ) {
			return [ 'all_verified' => false, 'details' => [], 'error' => 'Could not re-read _elementor_data after save.' ];
		}
		$details = [];
		$all_verified = true;
		foreach ( $applied_edits as $applied ) {
			$id = $applied['id'] ?? '';
			$path = $applied['modified_path'] ?? $applied['path'] ?? '';
			$node = null;
			if ( $id !== '' && $id !== null ) {
				$node = ElementorTraverser::findNodeById( $reloaded, $id );
			}
			if ( $node === null && $path !== '' ) {
				$node = ElementorTraverser::findNodeByPath( $reloaded, $path );
			}
			$found_in_meta = false;
			$expected_snippet = '';
			if ( $node !== null && is_array( $node ) ) {
				$widget_type = $node['widgetType'] ?? '';
				$field = $applied['field'] ?? null;
				$item_index = isset( $applied['item_index'] ) ? (int) $applied['item_index'] : null;
				if ( ( $applied['type'] ?? '' ) === 'url' ) {
					$expected_url = $applied['new_url'] ?? '';
					$current = ElementorTraverser::getLinkUrlAtSlot( $node, $widget_type, $item_index );
					$found_in_meta = (string) $current === (string) $expected_url;
					$expected_snippet = mb_strlen( $expected_url ) > 80 ? mb_substr( $expected_url, 0, 80 ) . '...' : $expected_url;
				} else {
					$expected_text = $applied['new_text'] ?? '';
					$current = ElementorTraverser::getTextAtSlot( $node, $widget_type, $field, $item_index );
					$found_in_meta = (string) $current === (string) $expected_text;
					$expected_snippet = mb_strlen( $expected_text ) > 80 ? mb_substr( $expected_text, 0, 80 ) . '...' : $expected_text;
				}
			}
			$details[] = [
				'id'               => $id ?: null,
				'path'             => $path ?: null,
				'expected_snippet' => $expected_snippet,
				'found_in_meta'    => $found_in_meta,
			];
			if ( ! $found_in_meta ) {
				$all_verified = false;
			}
		}
		return [ 'all_verified' => $all_verified, 'details' => $details ];
	}

	/**
	 * Determine why an edit failed (for failed[] reason field).
	 *
	 * @param array  $data         Elementor data.
	 * @param string $id          Edit id (may be empty).
	 * @param string $path        Edit path (may be empty).
	 * @param array  $widget_types Allowed widget types.
	 * @return string One of: id_not_found, path_invalid, not_target_widget, unknown.
	 */
	private static function get_edit_failure_reason( array &$data, string $id, string $path, array $widget_types ): string {
		if ( $id !== '' ) {
			$node = ElementorTraverser::findNodeById( $data, $id );
			if ( $node === null ) {
				return 'id_not_found';
			}
			$el_type = $node['elType'] ?? '';
			$widget_type = $node['widgetType'] ?? '';
			if ( $el_type !== 'widget' || $widget_type === '' || ! in_array( $widget_type, $widget_types, true ) ) {
				return 'not_target_widget';
			}
			return 'unknown';
		}
		if ( $path !== '' ) {
			$node = ElementorTraverser::findNodeByPath( $data, $path );
			if ( $node === null ) {
				return 'path_invalid';
			}
			$el_type = $node['elType'] ?? '';
			$widget_type = $node['widgetType'] ?? '';
			if ( $el_type !== 'widget' || $widget_type === '' || ! in_array( $widget_type, $widget_types, true ) ) {
				return 'not_target_widget';
			}
			return 'unknown';
		}
		return 'unknown';
	}
}
