# AI Elementor Sync

WordPress plugin that provides a REST API to perform text replacement and natural-language edits (via an external AI service) in Elementor page content, keyed by page URL. Supports **heading**, **text-editor**, **button**, **icon**, **image-box**, **icon-box**, **testimonial**, **counter**, **animated-headline**, **flip-box**, **icon-list**, **accordion**, **tabs**, **price-list**, **price-table**, URL/link editing on widgets that have a link control, and **image/background image** editing (Image widget and container/section background image).

## Features

- **REST API:** Inspect page text, link fields, and image slots (with optional widget_types), replace text (find/replace with containment match across all supported text slots), LLM edit (send instruction to external AI service), and apply edits directly (text, URL, or image/background image per slot).
- **Admin UI:** Under **Settings → AI Elementor Sync** you can call all endpoints from the browser: Inspect, Replace text, LLM edit, Apply edits.
- **Application password:** Create an application password from the admin UI so external tools (e.g. your LLM app) can call this site's REST API. The password is shown only once.
- **Register with LLM app:** After creating an application password, you can register this site with your external LLM app (enter the app's "register site" URL and click Register). The unified UI to access and edit multiple sites lives in **your LLM app**, not in WordPress; this plugin only provides the WordPress-side UI and the "register this site" flow so the LLM app can store credentials and call back to each site.

## Requirements

- WordPress 5.6+
- PHP 7.4+
- Elementor (for pages built with Elementor)

## REST Endpoints

- `GET /wp-json/ai-elementor/v1/inspect?url=...` — Inspect page text/link fields and **image_slots** (Image widget + container/section background image). Optional: `widget_types` (array or comma-separated; default: text-editor, heading).
- `POST /wp-json/ai-elementor/v1/replace-text` — Body: `{ "url", "find", "replace", "widget_types"? }`.
- `POST /wp-json/ai-elementor/v1/llm-edit` — Body: `{ "url", "instruction", "widget_types"? }` (requires AI service URL).
- `POST /wp-json/ai-elementor/v1/apply-edits` — Body: `{ "url", "edits": [{ "id" or "path", "new_text"? or "new_url"? or "new_link"? or "new_image_url"? or "new_attachment_id"? or "new_image": { "url"?,"id"? } }], "widget_types"? }`. Each edit can include optional `field` and `item_index` (0-based) for text/URL slots. Image edits target Image widget or container/section background by id/path. `new_link` can be `{ "url", "is_external"?,"nofollow"? }`.
- `POST /wp-json/ai-elementor/v1/create-application-password` — Create an application password (requires `manage_options`). Returns `{ "password", "username" }` once.

Authentication: cookie + nonce for in-admin requests, or Application Password (Basic auth) for external clients.

## Configuration

- **AI service URL:** Set the option `ai_elementor_sync_ai_service_url` (or constant `AI_ELEMENTOR_SYNC_AI_SERVICE_URL`) to your external AI edit service URL. See `AI_SERVICE.md` for the service contract.
- **LLM register URL:** Optional option `ai_elementor_sync_llm_register_url` is used to pre-fill the Register field in the admin UI.
- **Sideload images:** Option `ai_elementor_sync_sideload_images` (Settings tab: "Sideload images from URL"). When enabled, image edits that provide only a URL (no attachment ID) are downloaded into the media library and the new attachment ID is set.

## AI service contract (LLM edit)

When you call **llm-edit**, the plugin sends to your AI service:

- **dictionary** — Text/link slots: `{ id, path, widget_type, field, text, link_url? }`. Entries may include **link_url** so the AI can suggest link changes.
- **image_slots** — Image and background_image slots: `{ id, path, slot_type, el_type, image_url, image_id? }`. The AI may return image edits keyed by id/path.
- **instruction** — User instruction (natural language).
- **edit_capabilities** — `["text", "url", "image"]` so the service knows which edit types are supported.

The AI may return **edits** with:

- **Text:** `new_text` (and optional `field`, `item_index`).
- **Links:** `new_url` or `new_link` (with optional `is_external`, `nofollow`) for slots that have `link_url` in the dictionary.
- **Images:** `new_image_url` and/or `new_attachment_id` (or `new_image: { url?, id? }`) for slots from **image_slots**.

Each edit must have `id` or `path` to identify the slot.

**Kit (theme) edits:** When the user selects target **Kit (Theme)**, the plugin sends a different body to the same AI service URL: `context_type: 'kit'`, `kit_settings: { colors, typography }`, and `instruction`. The service must return **`kit_patch`** (object with optional `colors` and `typography` arrays) instead of `edits`. See **`docs/LLM-SERVICE-KIT-EDITS.md`** for the full request/response contract so your LLM app can support theme (global colors and fonts) edits.

## Documentation

- `SYSTEM_ARTIFACT.md` — High-level system reference for humans and LLMs.
- `AI_SERVICE.md` — How to build the external AI edit service and its API contract (page/template edits).
- `docs/LLM-SERVICE-KIT-EDITS.md` — **Kit (theme) edits:** request/response format for global colors and typography so your LLM service can support the "Kit (Theme)" target and avoid 502/unknown request.
