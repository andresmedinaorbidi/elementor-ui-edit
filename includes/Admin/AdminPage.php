<?php

declare(strict_types=1);

namespace AiElementorSync\Admin;

/**
 * Registers the admin menu and renders the AI Elementor Sync tools page.
 */
final class AdminPage {

	/**
	 * Admin page slug.
	 */
	public const PAGE_SLUG = 'ai-elementor-sync';

	/**
	 * Hook into WordPress.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ], 10, 1 );
	}

	/**
	 * Register menu under Settings.
	 */
	public static function register_menu(): void {
		add_options_page(
			__( 'AI Elementor Sync', 'ai-elementor-sync' ),
			__( 'AI Elementor Sync', 'ai-elementor-sync' ),
			'edit_posts',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	/**
	 * Enqueue CSS and JS only on our admin page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'settings_page_' . self::PAGE_SLUG ) {
			return;
		}

		$plugin_file = AI_ELEMENTOR_SYNC_PATH . 'ai-elementor-sync.php';
		$version     = (string) ( defined( 'AI_ELEMENTOR_SYNC_VERSION' ) ? AI_ELEMENTOR_SYNC_VERSION : '1.0' );

		wp_enqueue_style(
			'ai-elementor-sync-admin',
			plugins_url( 'assets/admin.css', $plugin_file ),
			[],
			$version
		);

		wp_enqueue_script(
			'ai-elementor-sync-admin',
			plugins_url( 'assets/admin.js', $plugin_file ),
			[],
			$version,
			true
		);

		wp_localize_script( 'ai-elementor-sync-admin', 'aiElementorSync', [
			'rest_url'        => rest_url( 'ai-elementor/v1' ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'site_url'        => home_url( '/' ),
			'ai_service'      => self::get_ai_service_url_for_display(),
			'llm_register_url' => self::get_llm_register_url_for_display(),
		] );
	}

	/**
	 * Get AI service URL for display only (not for sending from JS).
	 *
	 * @return string
	 */
	private static function get_ai_service_url_for_display(): string {
		$url = get_option( 'ai_elementor_sync_ai_service_url', '' );
		if ( is_string( $url ) ) {
			return trim( $url );
		}
		return '';
	}

	/**
	 * Get LLM app register URL for pre-filling the Register field (optional).
	 *
	 * @return string
	 */
	private static function get_llm_register_url_for_display(): string {
		$url = get_option( 'ai_elementor_sync_llm_register_url', '' );
		if ( is_string( $url ) ) {
			return trim( $url );
		}
		return '';
	}

	/**
	 * Render the admin page HTML.
	 */
	public static function render_page(): void {
		?>
		<div class="wrap ai-elementor-sync-admin">
			<h1><?php esc_html_e( 'AI Elementor Sync', 'ai-elementor-sync' ); ?></h1>

			<nav class="nav-tab-wrapper ai-elementor-sync-tabs" role="tablist">
				<button type="button" class="nav-tab nav-tab-active" role="tab" data-tab="inspect" aria-selected="true"><?php esc_html_e( 'Inspect', 'ai-elementor-sync' ); ?></button>
				<button type="button" class="nav-tab" role="tab" data-tab="replace-text"><?php esc_html_e( 'Replace text', 'ai-elementor-sync' ); ?></button>
				<button type="button" class="nav-tab" role="tab" data-tab="llm-edit"><?php esc_html_e( 'LLM edit', 'ai-elementor-sync' ); ?></button>
				<button type="button" class="nav-tab" role="tab" data-tab="apply-edits"><?php esc_html_e( 'Apply edits', 'ai-elementor-sync' ); ?></button>
				<button type="button" class="nav-tab" role="tab" data-tab="theme"><?php esc_html_e( 'Theme', 'ai-elementor-sync' ); ?></button>
				<button type="button" class="nav-tab" role="tab" data-tab="app-password"><?php esc_html_e( 'Application password', 'ai-elementor-sync' ); ?></button>
				<button type="button" class="nav-tab" role="tab" data-tab="settings"><?php esc_html_e( 'Settings', 'ai-elementor-sync' ); ?></button>
				<button type="button" class="nav-tab" role="tab" data-tab="log"><?php esc_html_e( 'Log', 'ai-elementor-sync' ); ?></button>
			</nav>

			<div id="tab-inspect" class="ai-elementor-sync-panel" role="tabpanel">
				<h2><?php esc_html_e( 'Inspect', 'ai-elementor-sync' ); ?></h2>
				<p><?php esc_html_e( 'Get post ID, structure, text/link fields, and image slots (Image widget + container/section background image) for a page URL or an Elementor template (header, footer, etc.).', 'ai-elementor-sync' ); ?></p>
				<form id="form-inspect" class="ai-elementor-sync-form">
					<fieldset class="ai-elementor-sync-target">
						<legend><?php esc_html_e( 'Target', 'ai-elementor-sync' ); ?></legend>
						<p>
							<label><input type="radio" name="inspect-target" value="url" checked /> <?php esc_html_e( 'Page (URL)', 'ai-elementor-sync' ); ?></label>
							<label><input type="radio" name="inspect-target" value="template" /> <?php esc_html_e( 'Template', 'ai-elementor-sync' ); ?></label>
						</p>
						<p id="inspect-url-wrap">
							<label for="inspect-url"><?php esc_html_e( 'Page URL', 'ai-elementor-sync' ); ?></label>
							<input type="url" id="inspect-url" name="url" class="large-text" placeholder="https://example.com/page/" />
						</p>
						<p id="inspect-template-wrap" class="hidden">
							<label for="inspect-template-id"><?php esc_html_e( 'Template', 'ai-elementor-sync' ); ?></label>
							<select id="inspect-template-id" name="template_id"><option value=""><?php esc_html_e( 'Loading…', 'ai-elementor-sync' ); ?></option></select>
							<button type="button" class="button js-retry-templates" style="display:none; margin-left: 0.5em;"><?php esc_html_e( 'Retry load templates', 'ai-elementor-sync' ); ?></button>
						</p>
						<p class="description"><?php esc_html_e( 'For header/footer templates, use Template and select from the dropdown (template permalinks are not resolved as page URL).', 'ai-elementor-sync' ); ?></p>
					</fieldset>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Inspect', 'ai-elementor-sync' ); ?></button>
					</p>
					<div id="result-inspect" class="ai-elementor-sync-result" aria-live="polite"></div>
				</form>
			</div>

			<div id="tab-replace-text" class="ai-elementor-sync-panel hidden" role="tabpanel">
				<h2><?php esc_html_e( 'Replace text', 'ai-elementor-sync' ); ?></h2>
				<p><?php esc_html_e( 'Find and replace text in exactly one matching widget (containment match). Target a page by URL or an Elementor template.', 'ai-elementor-sync' ); ?></p>
				<form id="form-replace-text" class="ai-elementor-sync-form">
					<fieldset class="ai-elementor-sync-target">
						<legend><?php esc_html_e( 'Target', 'ai-elementor-sync' ); ?></legend>
						<p>
							<label><input type="radio" name="replace-target" value="url" checked /> <?php esc_html_e( 'Page (URL)', 'ai-elementor-sync' ); ?></label>
							<label><input type="radio" name="replace-target" value="template" /> <?php esc_html_e( 'Template', 'ai-elementor-sync' ); ?></label>
						</p>
						<p id="replace-url-wrap">
							<label for="replace-url"><?php esc_html_e( 'Page URL', 'ai-elementor-sync' ); ?></label>
							<input type="url" id="replace-url" name="url" class="large-text" placeholder="https://example.com/page/" />
						</p>
						<p id="replace-template-wrap" class="hidden">
							<label for="replace-template-id"><?php esc_html_e( 'Template', 'ai-elementor-sync' ); ?></label>
							<select id="replace-template-id" name="template_id"><option value=""><?php esc_html_e( 'Loading…', 'ai-elementor-sync' ); ?></option></select>
							<button type="button" class="button js-retry-templates" style="display:none; margin-left: 0.5em;"><?php esc_html_e( 'Retry load templates', 'ai-elementor-sync' ); ?></button>
						</p>
					</fieldset>
					<p>
						<label for="replace-find"><?php esc_html_e( 'Find', 'ai-elementor-sync' ); ?></label>
						<input type="text" id="replace-find" name="find" class="large-text" required />
					</p>
					<p>
						<label for="replace-replace"><?php esc_html_e( 'Replace with', 'ai-elementor-sync' ); ?></label>
						<input type="text" id="replace-replace" name="replace" class="large-text" required />
					</p>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Replace', 'ai-elementor-sync' ); ?></button>
					</p>
					<div id="result-replace-text" class="ai-elementor-sync-result" aria-live="polite"></div>
				</form>
			</div>

			<div id="tab-llm-edit" class="ai-elementor-sync-panel hidden" role="tabpanel">
				<h2><?php esc_html_e( 'LLM edit', 'ai-elementor-sync' ); ?></h2>
				<p><?php esc_html_e( 'Send page or template text to the AI service and apply returned edits. Use "Auto" to detect footer/header from your instruction (e.g. "In the footer, change the copyright to 2025"). Requires AI service URL.', 'ai-elementor-sync' ); ?></p>
				<form id="form-llm-edit" class="ai-elementor-sync-form">
					<fieldset class="ai-elementor-sync-target">
						<legend><?php esc_html_e( 'Target', 'ai-elementor-sync' ); ?></legend>
						<p>
							<label><input type="radio" name="llm-target" value="url" checked /> <?php esc_html_e( 'Page (URL)', 'ai-elementor-sync' ); ?></label>
							<label><input type="radio" name="llm-target" value="template" /> <?php esc_html_e( 'Template', 'ai-elementor-sync' ); ?></label>
							<label><input type="radio" name="llm-target" value="auto" /> <?php esc_html_e( 'Auto (footer/header from instruction)', 'ai-elementor-sync' ); ?></label>
							<label><input type="radio" name="llm-target" value="kit" /> <?php esc_html_e( 'Kit (Theme)', 'ai-elementor-sync' ); ?></label>
						</p>
						<p id="llm-url-wrap">
							<label for="llm-url"><?php esc_html_e( 'Page URL', 'ai-elementor-sync' ); ?></label>
							<input type="url" id="llm-url" name="url" class="large-text" placeholder="https://example.com/page/" />
						</p>
						<p id="llm-template-wrap" class="hidden">
							<label for="llm-template-id"><?php esc_html_e( 'Template', 'ai-elementor-sync' ); ?></label>
							<select id="llm-template-id" name="template_id"><option value=""><?php esc_html_e( 'Loading…', 'ai-elementor-sync' ); ?></option></select>
							<button type="button" class="button js-retry-templates" style="display:none; margin-left: 0.5em;"><?php esc_html_e( 'Retry load templates', 'ai-elementor-sync' ); ?></button>
						</p>
						<p id="llm-auto-hint" class="hidden description"><?php esc_html_e( 'No URL or template needed. Mention "footer" or "header" in your instruction; the first matching template will be used.', 'ai-elementor-sync' ); ?></p>
						<p id="llm-kit-hint" class="hidden description"><?php esc_html_e( 'Edit global colors and typography. No URL or template.', 'ai-elementor-sync' ); ?></p>
					</fieldset>
					<p>
						<label for="llm-instruction"><?php esc_html_e( 'Instruction', 'ai-elementor-sync' ); ?></label>
						<textarea id="llm-instruction" name="instruction" class="large-text" rows="4" required placeholder="<?php esc_attr_e( 'e.g. Change the copyright year to 2025', 'ai-elementor-sync' ); ?>"></textarea>
					</p>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'LLM edit', 'ai-elementor-sync' ); ?></button>
					</p>
					<div id="result-llm-edit" class="ai-elementor-sync-result" aria-live="polite"></div>
				</form>
			</div>

			<div id="tab-apply-edits" class="ai-elementor-sync-panel hidden" role="tabpanel">
				<h2><?php esc_html_e( 'Apply edits', 'ai-elementor-sync' ); ?></h2>
				<p><?php esc_html_e( 'Apply edits directly (no AI). Target a page by URL or an Elementor template. Each edit: id or path; new_text or new_url/new_link (text/URL); or new_image_url/new_attachment_id (image/background).', 'ai-elementor-sync' ); ?></p>
				<form id="form-apply-edits" class="ai-elementor-sync-form">
					<fieldset class="ai-elementor-sync-target">
						<legend><?php esc_html_e( 'Target', 'ai-elementor-sync' ); ?></legend>
						<p>
							<label><input type="radio" name="apply-target" value="url" checked /> <?php esc_html_e( 'Page (URL)', 'ai-elementor-sync' ); ?></label>
							<label><input type="radio" name="apply-target" value="template" /> <?php esc_html_e( 'Template', 'ai-elementor-sync' ); ?></label>
						</p>
						<p id="apply-url-wrap">
							<label for="apply-url"><?php esc_html_e( 'Page URL', 'ai-elementor-sync' ); ?></label>
							<input type="url" id="apply-url" name="url" class="large-text" placeholder="https://example.com/page/" />
						</p>
						<p id="apply-template-wrap" class="hidden">
							<label for="apply-template-id"><?php esc_html_e( 'Template', 'ai-elementor-sync' ); ?></label>
							<select id="apply-template-id" name="template_id"><option value=""><?php esc_html_e( 'Loading…', 'ai-elementor-sync' ); ?></option></select>
							<button type="button" class="button js-retry-templates" style="display:none; margin-left: 0.5em;"><?php esc_html_e( 'Retry load templates', 'ai-elementor-sync' ); ?></button>
						</p>
					</fieldset>
					<p class="description" style="margin-top: -0.5em;"><?php esc_html_e( 'For header/footer, use Template and select from the dropdown (template permalinks are not resolved as page URL).', 'ai-elementor-sync' ); ?></p>
					<p>
						<label for="apply-edits"><?php esc_html_e( 'Edits (JSON array)', 'ai-elementor-sync' ); ?></label>
						<textarea id="apply-edits" name="edits" class="large-text code" rows="8" placeholder='[{"id":"abc123","new_text":"Hello"},{"path":"0/1/2","new_image_url":"https://example.com/image.jpg"},{"id":"img1","new_attachment_id":42}]' required></textarea>
					</p>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply edits', 'ai-elementor-sync' ); ?></button>
					</p>
					<div id="result-apply-edits" class="ai-elementor-sync-result" aria-live="polite"></div>
				</form>
			</div>

			<div id="tab-theme" class="ai-elementor-sync-panel hidden" role="tabpanel">
				<h2><?php esc_html_e( 'Theme', 'ai-elementor-sync' ); ?></h2>
				<p><?php esc_html_e( 'View and edit the active Elementor Kit global colors and typography. No page URL or template — applies site-wide.', 'ai-elementor-sync' ); ?></p>
				<p>
					<button type="button" id="btn-load-kit-settings" class="button button-primary"><?php esc_html_e( 'Load kit settings', 'ai-elementor-sync' ); ?></button>
					<button type="button" id="btn-test-kit-settings" class="button" data-test-kit="1" onclick="if(window.aiElementorSyncTestKit){window.aiElementorSyncTestKit();} return false;"><?php esc_html_e( 'Test kit connection', 'ai-elementor-sync' ); ?></button>
					<span class="description" style="margin-left: 0.5em;"><?php esc_html_e( 'Test: verifies the plugin can read Elementor theme settings (GET kit-settings).', 'ai-elementor-sync' ); ?></span>
				</p>
				<div id="result-kit-test" class="ai-elementor-sync-result" aria-live="polite" style="margin-top: 0.5em; min-height: 2em;"></div>
				<div id="result-kit-settings" class="ai-elementor-sync-result" aria-live="polite"></div>
				<p>
					<button type="button" id="btn-save-direct-edits" class="button"><?php esc_html_e( 'Save direct edits', 'ai-elementor-sync' ); ?></button>
					<span class="description"><?php esc_html_e( 'Save changes from the color and font controls in the tables above.', 'ai-elementor-sync' ); ?></span>
				</p>
				<form id="form-theme" class="ai-elementor-sync-form">
					<p>
						<label for="theme-patch-json"><?php esc_html_e( 'Patch (JSON)', 'ai-elementor-sync' ); ?></label>
						<textarea id="theme-patch-json" name="patch" class="large-text code" rows="6" placeholder='{"colors": [...]} or {"typography": [...]} or {"settings": {"system_colors": [...]}}'></textarea>
						<span class="description"><?php esc_html_e( 'Partial JSON to merge into kit settings. Use colors, typography, or settings keys.', 'ai-elementor-sync' ); ?></span>
					</p>
					<p>
						<button type="submit" id="btn-save-kit-patch" class="button button-primary"><?php esc_html_e( 'Save patch', 'ai-elementor-sync' ); ?></button>
					</p>
				</form>
			</div>

			<div id="tab-app-password" class="ai-elementor-sync-panel hidden" role="tabpanel">
				<h2><?php esc_html_e( 'Application password', 'ai-elementor-sync' ); ?></h2>
				<p><?php esc_html_e( 'Create an application password so external tools (e.g. your LLM app) can call this site\'s REST API. The password is shown only once.', 'ai-elementor-sync' ); ?></p>
				<div id="app-password-section">
					<p id="app-password-unavailable" class="hidden"><?php esc_html_e( 'Application passwords are not available on this site.', 'ai-elementor-sync' ); ?></p>
					<p>
						<button type="button" id="btn-create-app-password" class="button button-primary"><?php esc_html_e( 'Create application password', 'ai-elementor-sync' ); ?></button>
					</p>
					<div id="app-password-result" class="ai-elementor-sync-result" aria-live="polite"></div>
					<div id="app-password-register" class="hidden">
						<p>
							<label for="llm-register-url"><?php esc_html_e( 'LLM app register URL', 'ai-elementor-sync' ); ?></label>
							<input type="url" id="llm-register-url" class="large-text" placeholder="https://your-llm-app.com/register-site" />
						</p>
						<p>
							<button type="button" id="btn-register-llm" class="button"><?php esc_html_e( 'Register this site with LLM app', 'ai-elementor-sync' ); ?></button>
						</p>
						<div id="result-register-llm" class="ai-elementor-sync-result" aria-live="polite"></div>
					</div>
				</div>
			</div>

			<div id="tab-settings" class="ai-elementor-sync-panel hidden" role="tabpanel">
				<h2><?php esc_html_e( 'Settings', 'ai-elementor-sync' ); ?></h2>
				<p><?php esc_html_e( 'Configure the AI service URL and optional LLM app register URL. Changes apply immediately.', 'ai-elementor-sync' ); ?></p>
				<form id="form-settings" class="ai-elementor-sync-form">
					<p>
						<label for="settings-ai-service-url"><?php esc_html_e( 'AI service URL', 'ai-elementor-sync' ); ?></label>
						<input type="url" id="settings-ai-service-url" name="ai_service_url" class="large-text" placeholder="https://elementor-ui-edit-server.onrender.com/edits" />
						<span class="description"><?php esc_html_e( 'External LLM edit service endpoint (e.g. /edits).', 'ai-elementor-sync' ); ?></span>
					</p>
					<p>
						<label for="settings-llm-register-url"><?php esc_html_e( 'LLM app register URL', 'ai-elementor-sync' ); ?></label>
						<input type="url" id="settings-llm-register-url" name="llm_register_url" class="large-text" placeholder="https://your-llm-app.com/register-site" />
						<span class="description"><?php esc_html_e( 'Optional: used to pre-fill the Register field in Application password tab.', 'ai-elementor-sync' ); ?></span>
					</p>
					<p>
						<label for="settings-sideload-images">
							<input type="checkbox" id="settings-sideload-images" name="sideload_images" value="1" />
							<?php esc_html_e( 'Sideload images from URL', 'ai-elementor-sync' ); ?>
						</label>
						<span class="description"><?php esc_html_e( 'When applying image edits with only a URL (no attachment ID), download the image into the media library and set the attachment ID.', 'ai-elementor-sync' ); ?></span>
					</p>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'ai-elementor-sync' ); ?></button>
						<span id="settings-save-result" class="ai-elementor-sync-inline-result" aria-live="polite"></span>
					</p>
				</form>
			</div>

			<div id="tab-log" class="ai-elementor-sync-panel hidden" role="tabpanel">
				<h2><?php esc_html_e( 'Log', 'ai-elementor-sync' ); ?></h2>
				<p><?php esc_html_e( 'Recent requests and errors (LLM calls, save failures). Last 100 entries. Refresh to load.', 'ai-elementor-sync' ); ?></p>
				<p>
					<button type="button" id="btn-refresh-log" class="button"><?php esc_html_e( 'Refresh', 'ai-elementor-sync' ); ?></button>
					<button type="button" id="btn-clear-log" class="button"><?php esc_html_e( 'Clear log', 'ai-elementor-sync' ); ?></button>
				</p>
				<div id="log-entries" class="ai-elementor-sync-log-entries" aria-live="polite"></div>
			</div>
		</div>
		<?php
	}
}
