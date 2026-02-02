<?php

declare(strict_types=1);

namespace AiElementorSync\Rest\Controllers;

use AiElementorSync\Services\TemplateResolver;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for list-templates endpoint.
 */
final class TemplatesController {

	/**
	 * Handle GET /ai-elementor/v1/list-templates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_templates( WP_REST_Request $request ): WP_REST_Response {
		$templates = TemplateResolver::listTemplates();
		return new WP_REST_Response( $templates, 200 );
	}
}
