<?php

declare(strict_types=1);

namespace AiElementorSync\Services;

use WP_REST_Request;

/**
 * Resolves edit target from request params: url (page), or template_id / document_type + slug (template).
 * Returns post_id and source for permission checks and controller use.
 */
final class EditTargetResolver {

	/**
	 * Get target params (url, template_id, document_type, slug) from request.
	 * For GET (e.g. inspect) uses query params; for POST uses JSON/body params.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{ url?: string, template_id?: int, document_type?: string, slug?: string }
	 */
	public static function getTargetParamsFromRequest( WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}
		$url = isset( $params['url'] ) && is_string( $params['url'] ) ? trim( $params['url'] ) : '';
		$template_id = isset( $params['template_id'] ) ? (int) $params['template_id'] : 0;
		$document_type = isset( $params['document_type'] ) && is_string( $params['document_type'] ) ? trim( $params['document_type'] ) : '';
		$slug = isset( $params['slug'] ) && is_string( $params['slug'] ) ? trim( $params['slug'] ) : '';
		return [
			'url'            => $url,
			'template_id'     => $template_id,
			'document_type'   => $document_type,
			'slug'            => $slug,
		];
	}

	/**
	 * Resolve target from params. Requires one of: url, template_id, or document_type.
	 *
	 * @param array $params Keys: url?, template_id?, document_type?, slug?.
	 * @return array{ post_id: int, source: string }|\WP_Error On success: post_id and source ('url'|'template'); on failure WP_Error.
	 */
	public static function fromRequest( array $params ): array|\WP_Error {
		$url = isset( $params['url'] ) && is_string( $params['url'] ) ? trim( $params['url'] ) : '';
		$template_id = isset( $params['template_id'] ) ? (int) $params['template_id'] : 0;
		$document_type = isset( $params['document_type'] ) && is_string( $params['document_type'] ) ? trim( $params['document_type'] ) : '';
		$slug = isset( $params['slug'] ) && is_string( $params['slug'] ) ? trim( $params['slug'] ) : '';

		if ( $template_id > 0 ) {
			$post_id = TemplateResolver::resolveById( $template_id );
			if ( $post_id === 0 ) {
				return new \WP_Error( 'template_not_found', __( 'Template not found.', 'ai-elementor-sync' ), [ 'status' => 404 ] );
			}
			return [ 'post_id' => $post_id, 'source' => 'template' ];
		}

		if ( $document_type !== '' ) {
			$post_id = TemplateResolver::resolveByTypeAndSlug( $document_type, $slug );
			if ( $post_id === 0 ) {
				return new \WP_Error( 'template_not_found', __( 'Template not found.', 'ai-elementor-sync' ), [ 'status' => 404 ] );
			}
			return [ 'post_id' => $post_id, 'source' => 'template' ];
		}

		if ( $url !== '' ) {
			$post_id = UrlResolver::resolve( $url );
			if ( $post_id === 0 ) {
				return new \WP_Error( 'url_unresolved', __( 'URL could not be resolved.', 'ai-elementor-sync' ), [ 'status' => 403 ] );
			}
			return [ 'post_id' => $post_id, 'source' => 'url' ];
		}

		return new \WP_Error( 'missing_target', __( 'Provide url, template_id, or document_type.', 'ai-elementor-sync' ), [ 'status' => 400 ] );
	}
}
