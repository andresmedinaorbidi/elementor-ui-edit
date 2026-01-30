<?php

declare(strict_types=1);

namespace AiElementorSync\Services;

/**
 * Resolves a page URL to a WordPress post ID.
 */
final class UrlResolver {

	/**
	 * Resolve URL to post ID. Uses url_to_postid then get_page_by_path fallback.
	 *
	 * @param string $url Page URL (e.g. https://example.com/home).
	 * @return int Post ID, or 0 if not found.
	 */
	public static function resolve( string $url ): int {
		$url = trim( $url );
		if ( $url === '' ) {
			return 0;
		}

		$post_id = url_to_postid( $url );
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			return ( $post && $post->post_status !== 'trash' ) ? $post_id : 0;
		}

		$path = parse_url( $url, PHP_URL_PATH );
		if ( $path === null || $path === '' ) {
			return 0;
		}
		$path = trim( $path, '/' );
		$page = get_page_by_path( $path );
		if ( $page ) {
			return (int) $page->ID;
		}
		return 0;
	}
}
