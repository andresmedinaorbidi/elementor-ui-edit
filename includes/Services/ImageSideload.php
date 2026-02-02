<?php

declare(strict_types=1);

namespace AiElementorSync\Services;

use AiElementorSync\Support\Logger;

/**
 * Sideload an image from a URL into the WordPress media library.
 * Used when applying image edits with only new_image_url (no attachment ID).
 */
final class ImageSideload {

	private const DOWNLOAD_TIMEOUT = 15;

	/**
	 * Download image from URL and create an attachment. Requires upload_files capability.
	 *
	 * @param string $image_url Full URL of the image to download.
	 * @param int    $post_id   Post ID to attach the media to (0 = unattached).
	 * @return array{ url: string, id: int }|null On success returns url and attachment id; on failure null.
	 */
	public static function sideload( string $image_url, int $post_id = 0 ): ?array {
		$image_url = trim( $image_url );
		if ( $image_url === '' ) {
			return null;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			Logger::log_ui( 'error', 'ImageSideload: user cannot upload_files.', [ 'url' => $image_url ] );
			return null;
		}
		$parsed = wp_parse_url( $image_url );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			Logger::log_ui( 'error', 'ImageSideload: invalid URL.', [ 'url' => $image_url ] );
			return null;
		}
		if ( ! in_array( strtolower( $parsed['scheme'] ), [ 'http', 'https' ], true ) ) {
			Logger::log_ui( 'error', 'ImageSideload: URL scheme must be http or https.', [ 'url' => $image_url ] );
			return null;
		}

		self::load_media_deps();
		$attachment_id = media_sideload_image( $image_url, $post_id, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			Logger::log_ui( 'error', 'ImageSideload: sideload failed.', [
				'url'   => $image_url,
				'error' => $attachment_id->get_error_message(),
			] );
			return null;
		}
		if ( ! is_numeric( $attachment_id ) || (int) $attachment_id <= 0 ) {
			return null;
		}
		$attachment_id = (int) $attachment_id;
		$url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $url ) || $url === '' ) {
			Logger::log_ui( 'error', 'ImageSideload: could not get attachment URL after sideload.', [ 'attachment_id' => $attachment_id ] );
			return null;
		}
		return [ 'url' => $url, 'id' => $attachment_id ];
	}

	/**
	 * Load WordPress admin media includes required for media_sideload_image.
	 */
	private static function load_media_deps(): void {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}
}
