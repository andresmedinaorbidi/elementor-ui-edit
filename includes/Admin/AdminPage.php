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
				<button type="button" class="nav-tab" role="tab" data-tab="app-password"><?php esc_html_e( 'Application password', 'ai-elementor-sync' ); ?></button>
				<button type="button" class="nav-tab" role="tab" data-tab="settings"><?php esc_html_e( 'Settings', 'ai-elementor-sync' ); ?></button>
				<button type="button" class="nav-tab" role="tab" data-tab="log"><?php esc_html_e( 'Log', 'ai-elementor-sync' ); ?></button>
			</nav>

			<div id="tab-inspect" class="ai-elementor-sync-panel" role="tabpanel">
				<h2><?php esc_html_e( 'Inspect', 'ai-elementor-sync' ); ?></h2>
				<p><?php esc_html_e( 'Get post ID, structure, and text/link fields for a page URL. Supports heading, text-editor, button, image-box, icon-box, accordion, tabs, and more.', 'ai-elementor-sync' ); ?></p>
				<form id="form-inspect" class="ai-elementor-sync-form">
					<p>
						<label for="inspect-url"><?php esc_html_e( 'Page URL', 'ai-elementor-sync' ); ?></label>
						<input type="url" id="inspect-url" name="url" class="large-text" placeholder="https://example.com/page/" required />
					</p>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Inspect', 'ai-elementor-sync' ); ?></button>
					</p>
					<div id="result-inspect" class="ai-elementor-sync-result" aria-live="polite"></div>
				</form>
			</div>

			<div id="tab-replace-text" class="ai-elementor-sync-panel hidden" role="tabpanel">
				<h2><?php esc_html_e( 'Replace text', 'ai-elementor-sync' ); ?></h2>
				<p><?php esc_html_e( 'Find and replace text in exactly one matching widget (containment match).', 'ai-elementor-sync' ); ?></p>
				<form id="form-replace-text" class="ai-elementor-sync-form">
					<p>
						<label for="replace-url"><?php esc_html_e( 'Page URL', 'ai-elementor-sync' ); ?></label>
						<input type="url" id="replace-url" name="url" class="large-text" placeholder="https://example.com/page/" required />
					</p>
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
				<p><?php esc_html_e( 'Send page text to the AI service and apply returned edits. Requires AI service URL to be configured.', 'ai-elementor-sync' ); ?></p>
				<form id="form-llm-edit" class="ai-elementor-sync-form">
					<p>
						<label for="llm-url"><?php esc_html_e( 'Page URL', 'ai-elementor-sync' ); ?></label>
						<input type="url" id="llm-url" name="url" class="large-text" placeholder="https://example.com/page/" required />
					</p>
					<p>
						<label for="llm-instruction"><?php esc_html_e( 'Instruction', 'ai-elementor-sync' ); ?></label>
						<textarea id="llm-instruction" name="instruction" class="large-text" rows="4" required></textarea>
					</p>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'LLM edit', 'ai-elementor-sync' ); ?></button>
					</p>
					<div id="result-llm-edit" class="ai-elementor-sync-result" aria-live="polite"></div>
				</form>
			</div>

			<div id="tab-apply-edits" class="ai-elementor-sync-panel hidden" role="tabpanel">
				<h2><?php esc_html_e( 'Apply edits', 'ai-elementor-sync' ); ?></h2>
				<p><?php esc_html_e( 'Apply edits directly (no AI). Each edit: id or path; new_text or new_url/new_link; optional field and item_index for specific slots.', 'ai-elementor-sync' ); ?></p>
				<form id="form-apply-edits" class="ai-elementor-sync-form">
					<p>
						<label for="apply-url"><?php esc_html_e( 'Page URL', 'ai-elementor-sync' ); ?></label>
						<input type="url" id="apply-url" name="url" class="large-text" placeholder="https://example.com/page/" required />
					</p>
					<p>
						<label for="apply-edits"><?php esc_html_e( 'Edits (JSON array)', 'ai-elementor-sync' ); ?></label>
						<textarea id="apply-edits" name="edits" class="large-text code" rows="8" placeholder='[{"id":"abc123","new_text":"Hello"},{"path":"0/1/2","field":"description_text","new_text":"World"},{"id":"xyz","new_url":"https://example.com"}]' required></textarea>
					</p>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply edits', 'ai-elementor-sync' ); ?></button>
					</p>
					<div id="result-apply-edits" class="ai-elementor-sync-result" aria-live="polite"></div>
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
