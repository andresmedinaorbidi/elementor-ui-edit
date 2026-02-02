<?php

declare(strict_types=1);

namespace AiElementorSync\Services;

/**
 * Resolves Elementor Theme Builder templates (header, footer, etc.) to post IDs.
 * Templates are stored as posts of type elementor_library with meta _elementor_template_type.
 */
final class TemplateResolver {

	/**
	 * Elementor library post type.
	 */
	public const POST_TYPE = 'elementor_library';

	/**
	 * Post meta key for template type (e.g. header, footer, page).
	 */
	public const META_TEMPLATE_TYPE = '_elementor_template_type';

	/**
	 * Resolve template by post ID. Verifies post exists, is elementor_library, and not trashed.
	 *
	 * @param int $template_id Template post ID.
	 * @return int Post ID, or 0 if not found or invalid.
	 */
	public static function resolveById( int $template_id ): int {
		if ( $template_id <= 0 ) {
			return 0;
		}
		$post = get_post( $template_id );
		if ( ! $post || $post->post_type !== self::POST_TYPE || $post->post_status === 'trash' ) {
			return 0;
		}
		return (int) $post->ID;
	}

	/**
	 * Resolve template by document type and optional slug.
	 * Returns first matching template (by date order) when slug is empty.
	 *
	 * @param string $document_type Document type (e.g. header, footer, page, single).
	 * @param string $slug          Optional post_name/slug to match.
	 * @return int Post ID, or 0 if not found.
	 */
	public static function resolveByTypeAndSlug( string $document_type, string $slug = '' ): int {
		$document_type = trim( $document_type );
		if ( $document_type === '' ) {
			return 0;
		}
		$args = [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => [
				[
					'key'   => self::META_TEMPLATE_TYPE,
					'value' => $document_type,
					'compare' => '=',
				],
			],
		];
		if ( $slug !== '' ) {
			$args['name'] = trim( $slug );
		}
		$query = new \WP_Query( $args );
		$posts = $query->posts;
		wp_reset_postdata();
		if ( empty( $posts ) || ! $posts[0] instanceof \WP_Post ) {
			return 0;
		}
		return (int) $posts[0]->ID;
	}

	/**
	 * List all Elementor library templates (not trashed) with id, name, document_type, slug.
	 *
	 * @return array<int, array{ id: int, name: string, document_type: string, slug: string }>
	 */
	public static function listTemplates(): array {
		$query = new \WP_Query( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => [ 'publish', 'draft', 'private' ],
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
		$posts = $query->posts;
		wp_reset_postdata();
		$out = [];
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post || $post->post_status === 'trash' ) {
				continue;
			}
			$document_type = get_post_meta( $post->ID, self::META_TEMPLATE_TYPE, true );
			if ( ! is_string( $document_type ) || $document_type === '' ) {
				$document_type = 'page';
			}
			$out[] = [
				'id'             => (int) $post->ID,
				'name'           => $post->post_title ?: (string) $post->ID,
				'document_type'  => $document_type,
				'slug'           => $post->post_name ?: '',
			];
		}
		return $out;
	}
}
