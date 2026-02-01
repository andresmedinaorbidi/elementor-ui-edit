# AI Elementor Sync

WordPress plugin that provides a REST API to perform text replacement and natural-language edits (via an external AI service) in Elementor page content, keyed by page URL. Supports **heading**, **text-editor**, **button**, **icon**, **image-box**, **icon-box**, **testimonial**, **counter**, **animated-headline**, **flip-box**, **icon-list**, **accordion**, **tabs**, **price-list**, **price-table**, and URL/link editing on widgets that have a link control.

## Features

- **REST API:** Inspect page text and link fields (with optional widget_types), replace text (find/replace with containment match across all supported text slots), LLM edit (send instruction to external AI service), and apply edits directly (text and/or URL per slot).
- **Admin UI:** Under **Settings → AI Elementor Sync** you can call all endpoints from the browser: Inspect, Replace text, LLM edit, Apply edits.
- **Application password:** Create an application password from the admin UI so external tools (e.g. your LLM app) can call this site's REST API. The password is shown only once.
- **Register with LLM app:** After creating an application password, you can register this site with your external LLM app (enter the app's "register site" URL and click Register). The unified UI to access and edit multiple sites lives in **your LLM app**, not in WordPress; this plugin only provides the WordPress-side UI and the "register this site" flow so the LLM app can store credentials and call back to each site.

## Requirements

- WordPress 5.6+
- PHP 7.4+
- Elementor (for pages built with Elementor)

## REST Endpoints

- `GET /wp-json/ai-elementor/v1/inspect?url=...` — Inspect page text/link fields. Optional: `widget_types` (array or comma-separated; default: text-editor, heading).
- `POST /wp-json/ai-elementor/v1/replace-text` — Body: `{ "url", "find", "replace", "widget_types"? }`.
- `POST /wp-json/ai-elementor/v1/llm-edit` — Body: `{ "url", "instruction", "widget_types"? }` (requires AI service URL).
- `POST /wp-json/ai-elementor/v1/apply-edits` — Body: `{ "url", "edits": [{ "id" or "path", "new_text"? or "new_url"? or "new_link"? }], "widget_types"? }`. Each edit can include optional `field` and `item_index` (0-based) for specific slots. `new_link` can be `{ "url", "is_external"?,"nofollow"? }`.
- `POST /wp-json/ai-elementor/v1/create-application-password` — Create an application password (requires `manage_options`). Returns `{ "password", "username" }` once.

Authentication: cookie + nonce for in-admin requests, or Application Password (Basic auth) for external clients.

## Configuration

- **AI service URL:** Set the option `ai_elementor_sync_ai_service_url` (or constant `AI_ELEMENTOR_SYNC_AI_SERVICE_URL`) to your external AI edit service URL. See `AI_SERVICE.md` for the service contract.
- **LLM register URL:** Optional option `ai_elementor_sync_llm_register_url` is used to pre-fill the ?LLM app register URL? field in the admin UI.

## Documentation

- `SYSTEM_ARTIFACT.md` ? High-level system reference for humans and LLMs.
- `AI_SERVICE.md` ? How to build the external AI edit service and its API contract.
