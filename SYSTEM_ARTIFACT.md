# System Artifact
> High-level system reference document. Its purpose is to provide **compressed, high-signal context** for humans and LLMs. It does NOT replace code or detailed documentation.

---

## 1. Overview

### 1.1 Purpose
This system enables **text replacement** and **natural-language edits via an external AI service** in Elementor page content via a REST API keyed by page URL. Supported widgets include **heading**, **text-editor**, **button**, **icon**, **image-box**, **icon-box**, **testimonial**, **counter**, **animated-headline**, **flip-box**, **icon-list**, **accordion**, **tabs**, **price-list**, **price-table**. Each widget can expose multiple text fields and optional link/URL; repeater widgets expose per-item text and link. **Image widget** and **container/section background image** are exposed as **image slots** (Inspect returns `image_slots`; apply-edits accept **new_image_url** and/or **new_attachment_id**). Find/replace uses **substring (containment)** match and is applied only when **exactly one** slot contains the find. Apply-edits accept **new_text**, **new_url**/new_link, or **new_image_url**/new_attachment_id (or **new_image**: { url?, id? }), with optional **field** and **item_index** for text/URL slots. Widget-elements are identified by Elementor's stable **`id`** or index **path**. The plugin calls a **single external AI edit service URL** (your proxy); no API key or model is stored in WordPress. **Theme (Kit) edits:** When the user selects target **Kit (Theme)** in LLM edit, the plugin sends **kit_settings** (colors, typography) and **instruction** to the same AI service URL; the service returns **kit_patch** (colors/typography to merge). The plugin **merges by _id** (updates existing color/typography items; does not append duplicates) and copies **value** to **color** for Elementor. **Kit settings** are read/written via GET/POST **kit-settings** (active Elementor Kit page_settings: system_colors, system_typography). Admin includes a **Theme** tab: Load kit settings, Test kit connection, tables for colors/typography (from raw_settings; display uses **value** or **color**), and Save patch (JSON).

### 1.2 Non-Goals
* Does not handle authentication beyond WordPress (no custom auth)
* Does not store or send LLM API keys; key and LLM control (e.g. LangSmith) live in the external service
* Does not support bulk or multi-page replacement in one request
* Does not provide built-in rate limiting or optimistic locking for concurrent edits (last-write-wins)
* Does not provide the **unified UI** for multiple sites; that lives in the external LLM app. This plugin provides an in-WordPress admin UI to call endpoints and register this site with the LLM app.

---

## 2. Architectural Style

### 2.1 Architecture Pattern
Layered WordPress plugin: **API layer → Controller → Services (application + infrastructure)**. No formal Clean Architecture; domain rules are embedded in the traverser and controller.

### 2.2 Core Principles
* **Containment replace (replace-text):** Replace is performed only when exactly one widget **contains** the find string (normalized); if raw contains find literally, replace that substring; else replace entire widget value.
* **Apply by id or path (llm-edit / apply-edits):** Edits are applied by Elementor **`id`** (preferred, stable across reorders) or by index **path** (e.g. "0/1/2"); multiple edits in one request, one save.
* **No API key in WordPress:** Plugin only configures the external AI edit service URL; the external service holds keys and can wrap LangSmith.
* **URL as entry key:** Operations are keyed by page URL; resolution to `post_id` is centralized in `UrlResolver`.
* **Permission at API boundary:** Auth and `edit_post` are enforced in routes/controller before touching Elementor data.
* **Infrastructure behind services:** Elementor data and WordPress meta are accessed only through `ElementorDataStore` and `UrlResolver`; AI edit calls go through `LlmClient` (service URL only); cache regeneration is encapsulated in `CacheRegenerator`.

---

## 3. High-Level Structure

```
ai-elementor-sync.php          # Bootstrap, autoload, Plugin::init
includes/
  Plugin.php                   # REST registration, request encoding fix, AdminPage init
  Admin/
    AdminPage.php              # Settings submenu, admin UI page, enqueue assets (admin.css, admin.js); tabs: Inspect, Replace, LLM edit, Apply edits, Theme, Application password, Settings, Log
  Rest/
    Routes.php                 # Route registration, permission callbacks (incl. kit-settings, create-application-password, settings, log)
    Controllers/
      ReplaceTextController.php # replace_text, inspect
      LlmEditController.php     # llm_edit (page/template or target=kit), apply_edits
      KitSettingsController.php # get_settings, update_settings (kit-settings GET/POST)
      ApplicationPasswordController.php # create_application_password
      SettingsController.php   # get_settings, update_settings, get_log, clear_log
  Services/
    UrlResolver.php            # URL → post_id
    ElementorDataStore.php     # get/save _elementor_data (save: always delete+add to force write; update fallback if add fails)
    ElementorTraverser.php     # find/replace (containment), buildPageDictionary, replaceByPath, replaceById, collectAllTextFields, image slots
    KitStore.php               # getActiveKitId, getPageSettings, updatePageSettings (merge by _id for system_colors/system_typography), extractColors, extractTypography
    Normalizer.php             # normalize text for matching, preview_snippet
    LlmClient.php              # requestEdits (dictionary, instruction, image_slots); requestKitEdits (kit_settings, instruction → kit_patch); config: AI service URL only; logs to UI log
    CacheRegenerator.php       # Elementor cache/CSS regeneration; deletes _elementor_css meta, then clear_cache
  Support/
    Errors.php                 # REST error response helpers
    Logger.php                 # log (always UI log + error_log when WP_DEBUG), log_ui, get_ui_log, clear_ui_log
docs/
  LLM-SERVICE-KIT-EDITS.md     # Contract for LLM app: kit edit request/response (context_type: kit, kit_patch; merge by _id; value/color)
```

Brief description of each:
* **Plugin:** Bootstrap and REST wiring; fixes request body encoding for replace-text, llm-edit, and apply-edits routes; inits AdminPage.
* **Admin:** Settings submenu “AI Elementor Sync”; single page with tabs: **Inspect**, **Replace text**, **LLM edit**, **Apply edits**, **Theme**, **Application password**, **Settings**, **Log**. **Theme** tab: Load kit settings, **Test kit connection** (GET kit-settings; result in result-kit-test), Save patch (JSON); colors/typography as tables from raw_settings (system_colors, system_typography, custom_colors); table shows **value** or **color** for hex. Admin page capability: `edit_posts`. **Settings** tab: AI service URL (default Render.com), LLM register URL. **Log** tab: view/clear UI log (newest-first display). Uses cookie + nonce to call REST endpoints; “Create application password” calls create-application-password; “Register with LLM app” POSTs site_url, username, application_password to optional LLM register URL (option `ai_elementor_sync_llm_register_url` pre-fills the field).
* **Rest:** Delivery layer; routes, permission checks, controllers. **kit-settings** GET/POST require `manage_options`; llm-edit with **target=kit** requires `manage_options`. Settings (GET/POST) and log (GET/clear-log) require `edit_posts`.
* **Services:** Application logic (traverser, normalizer, LLM client, **KitStore**) and infrastructure (data store, URL resolution, cache). **KitStore:** reads/writes active Kit page_settings (`_elementor_page_settings` on post from option `elementor_active_kit`); for **system_colors** and **system_typography**, **merge by _id** (update existing item with same _id/id; append only if new _id); when applying color patch, copies **value** to **color** for Elementor. ElementorDataStore::save and CacheRegenerator as before.
* **Support:** Cross-cutting helpers (errors, logging). Logger always appends to UI log (option `ai_elementor_sync_ui_log`, max 100 entries); when WP_DEBUG, also writes to error_log.

---

## 4. Core Domain Model

### 4.1 Main Entities / Concepts
* **Page:** Identified by URL; resolved to a WordPress post (ID). Must be editable by the current user.
* **Elementor document:** Tree of elements stored in post meta `_elementor_data`; either `{ content: [ elements ] }` or raw elements array.
* **Widget:** Element with `elType === 'widget'` and `widgetType` in allowed list. **Simple widgets** have one or more text fields and optional link (e.g. image-box: title_text, description_text, link). **Repeater widgets** have a repeater key and per-item text/link (e.g. accordion: tabs with tab_title, tab_content). **Price-table** has title and features (array of strings). Control IDs match Elementor core; verify in elementor/includes/widgets/ if needed.
* **Slot:** A single editable target: (widget, field) or (widget, item_index, field). Dictionary and apply-edits are slot-based.
* **Match:** A **slot** contains the find string (normalized); replacement is applied only when there is **exactly one** such slot. If raw value contains find literally, replace that substring; else replace entire slot value.
* **Page dictionary:** Ephemeral per request; list of entries `{ id, path, widget_type, field, text }` with optional `item_index`, `link_url`. One entry per text slot; link-only slots (e.g. icon) get one entry with field `link`. Built from current `_elementor_data`; used by external AI edit service. Not persisted.
* **Path:** String of element indices (e.g. `"0/1/2"`). Index-based; valid only for the current document version.
* **Id:** Elementor's unique element `id` (string) in the JSON; stable across reorders; preferred over path for apply-edits.
* **Kit:** Active Elementor Kit (post ID from option `elementor_active_kit`). Kit **page_settings** stored in post meta `_elementor_page_settings`; keys include **system_colors**, **system_typography**, **custom_colors**. Each color/typography item has **`_id`** (or **id**) for matching; color hex may be in **value** or **color** (plugin copies value → color when applying patch). Plugin **merges by _id**: updates existing item with same _id; appends only if _id is new (no duplicate rows).

### 4.2 Key Attributes
* **Replace request:** `url` (required), `find` (required), `replace` (required), `widget_types` (optional, default `['text-editor','heading']`). Replace-text searches all supported text slots for the given widget_types.
* **Replace result:** `status` ∈ { `updated` | `not_found` | `ambiguous` }, `post_id`, `matches_found`, `matches_replaced`; `candidates` only when `ambiguous` (each candidate includes field, item_index when applicable).
* **Inspect result:** `post_id`, `data_structure`, `elements_count`, `text_fields` (id, widget_type, field, preview, path; item_index when applicable), **image_slots** (id, path, slot_type: 'image'|'background_image', el_type, widget_type?, image_url, image_id?). Optional query param `widget_types` (default text-editor, heading).
* **LlmEdit request:** `url` or `template_id` or **target=kit**; `instruction` (required); `widget_types` (optional). When **target=kit**, no url/template; plugin sends kit_settings (colors, typography) to AI and expects **kit_patch** (colors/typography to merge by _id).
* **LlmEdit result:** For page/template: `status` (`ok` | `error`), `post_id`, `applied_count`, `failed` ([{ id?, path?, error }]); on service failure, 502 with `message`. For **target=kit**: `status`, `kit_id`, `applied` (bool), `colors`, `typography`; on AI failure or non-JSON (e.g. 502 HTML), 502 with message (e.g. "AI service returned an error (502 Bad Gateway)...").
* **ApplyEdits request:** `url` (required), `edits` (required; array of edit items), `widget_types` (optional). Each edit item: at least one of `id` or `path`; at least one of `new_text`, `new_url`/`new_link`, or **new_image_url**/new_attachment_id (or **new_image**: { url?, id? }); optional `field`, `item_index` (0-based). `new_link` can be `{ url, is_external?, nofollow? }`. Image edits target Image widget or container/section background_image by id/path. Backward compatible: `{ id, new_text }` applies to primary text field.
* **ApplyEdits result:** Same as LlmEdit result; includes **applied_image_edits** when image edits were applied.

---

## 5. Use Cases

### ReplaceText (POST /ai-elementor/v1/replace-text)
* **Input:** `url`, `find`, `replace`, optional `widget_types`.
* **Output:** REST response with `status` (`updated` | `not_found` | `ambiguous`), `post_id`, `matches_found`, `matches_replaced`, and `candidates` when ambiguous.
* **Rules:** Resolve URL → post; load Elementor data; traverse and count widgets whose **normalized text contains** find; if exactly one, replace (substring if raw contains find, else whole widget), save, regenerate cache; otherwise return not_found or ambiguous with candidates.

### Inspect (GET /ai-elementor/v1/inspect?url=...)
* **Input:** `url` (query).
* **Output:** Post ID, data structure type, element count, list of text fields (id, widget_type, field, preview, path), and **image_slots** (id, path, slot_type, el_type, image_url, image_id?) for Image widget and container/section background image.
* **Rules:** Same permission as replace (edit_post); read-only; no persistence changes.

### LlmEdit (POST /ai-elementor/v1/llm-edit)
* **Input:** `url` or `template_id` or **target=kit**; `instruction` (natural language); optional `widget_types`.
* **Output:** For page/template: `status` (`ok` | `error`), `post_id`, `applied_count`, `failed` ([{ id?, path?, error }]). For **target=kit**: `status`, `kit_id`, `applied`, `colors`, `typography`. On AI service failure or HTML response (e.g. 502), 502 with message (e.g. "AI service returned an error (502 Bad Gateway). The external LLM service may be down...").
* **Rules:** If **target=kit**: require `manage_options`; load Kit page_settings via KitStore; call `LlmClient::requestKitEdits(kit_settings, instruction)`; expect **kit_patch** (colors/typography); merge by _id via KitStore::updatePageSettings (value → color for colors); return kit_id and updated colors/typography. Else: resolve URL/template → post; build dictionary and image_slots; call `LlmClient::requestEdits(dictionary, instruction, image_slots)`; apply text/link/image edits; save once if any applied; regenerate cache. Empty edits = success with `applied_count: 0`.

### ApplyEdits (POST /ai-elementor/v1/apply-edits)
* **Input:** `url`, `edits` (array of edit items), optional `widget_types`. Each edit: at least one of `id` or `path`; at least one of `new_text` or `new_url`/`new_link`; optional `field`, `item_index`. Backward compatible: `{ id, new_text }` applies to primary text field.
* **Output:** Same as LlmEdit (status, post_id, applied_count, failed).
* **Rules:** No AI service call; same permission and apply logic (prefer id over path). Text edits use replaceById/replaceByPath with optional field/item_index; URL edits use replaceUrlById/replaceUrlByPath with optional item_index; image edits use replaceImageById/replaceImageByPath with slot_type from getImageSlotTypeById/getImageSlotTypeByPath (new_image_url and/or new_attachment_id; URL resolved from attachment when only id provided). For clients that call their own AI service and only need to apply edits.

### CreateApplicationPassword (POST /ai-elementor/v1/create-application-password)
* **Input:** None (POST body optional).
* **Output:** `{ password, username }` (plain password shown once) or error (e.g. app_passwords_unavailable, app_password_exists).
* **Rules:** Requires `manage_options`. Uses `WP_Application_Passwords::create_new_application_password` for current user with name “AI Elementor Sync”. If a password with that name already exists, returns 400; revoke from profile first. Used by the admin UI so external tools (e.g. LLM app) can call this site’s REST API with Basic auth.

### KitSettings (GET /ai-elementor/v1/kit-settings, POST /ai-elementor/v1/kit-settings)
* **GET:** Returns active Kit page_settings: `kit_id`, `colors` (system_colors), `typography` (system_typography), `raw_settings`. Requires `manage_options`. Used by Theme tab "Load kit settings" and "Test kit connection".
* **POST:** Body: `{ colors?, typography?, settings? }`. Merges into current page_settings: **system_colors** and **system_typography** are **merged by _id** (update existing item with same _id/id; append only if new _id); when applying color item, **value** is copied to **color** for Elementor. Requires `manage_options`. Used by Theme tab "Save patch" and by llm-edit (target=kit) after receiving kit_patch from AI.

### Settings (GET /ai-elementor/v1/settings, POST /ai-elementor/v1/settings)
* **GET:** Returns `ai_service_url` (default `https://elementor-ui-edit-server.onrender.com/edits`), `llm_register_url`, `sideload_images`. Requires `manage_options`.
* **POST:** Update `ai_service_url` and/or `llm_register_url` and/or `sideload_images`. Requires `manage_options`.

### Log (GET /ai-elementor/v1/log, POST /ai-elementor/v1/clear-log)
* **GET log:** Returns UI log entries (time, level, message, context). Requires `edit_posts`.
* **POST clear-log:** Clears the UI log. Requires `edit_posts`. Used by the Log tab “Clear log” button.

### Admin UI and register with LLM app
* **Admin UI:** Under Settings → AI Elementor Sync (capability `edit_posts`), editors can run Inspect, Replace text, LLM edit (target: URL, Template, Auto, or **Kit (Theme)**), Apply edits, and **Theme** tab (Load kit settings, **Test kit connection**, Save patch) via forms (cookie + nonce). Theme tab displays colors/typography as tables from raw_settings (value or color for hex). Settings tab configures AI service URL, LLM register URL, Sideload images (manage_options required for saving). Log tab shows UI log entries (newest first) and Clear log. No Application Password needed for in-admin use.
* **Application password:** “Create application password” shows the password once with Copy; use with username for Basic auth. If Application Passwords are disabled (e.g. `allow_application_passwords` filter), the UI shows a message and hides the button.
* **Register with LLM app:** After creating an application password, the “Register this site with LLM app” section appears. User enters the LLM app’s “register site” endpoint URL (optional option `ai_elementor_sync_llm_register_url` pre-fills it). Clicking Register POSTs `{ site_url, username, application_password }` to that URL. The **unified UI** to access and edit multiple sites is implemented in the LLM app, not in this plugin; the plugin only provides the register flow so the LLM app can store credentials and call back to each site.

---

## 6. Interfaces & Contracts

### Services (used by controller)
```
UrlResolver
  resolve(url: string): int   # 0 if not found

ElementorDataStore
  get(post_id: int): ?array
  save(post_id: int, data: array): bool

ElementorTraverser
  findAndMaybeReplace(&data, find, replace, widget_types): { matches_found, matches_replaced, candidates, data }  # match = contains (normalized); searches all text slots
  buildPageDictionary(data, widget_types?, max_text_len?): [{ id, path, widget_type, field, text, item_index?, link_url? }, ...]
  buildImageSlots(data): [{ id, path, slot_type, el_type, widget_type?, image_url, image_id? }, ...]  # Image widget + container/section background_image
  getImageSlotTypeByPath(data, path): ?string  # 'image' | 'background_image' | null
  getImageSlotTypeById(&data, id): ?string
  replaceImageByPath(&data, path, new_url, new_attachment_id?, slot_type): bool  # slot_type: 'image' | 'background_image'
  replaceImageById(&data, id, new_url, new_attachment_id?, slot_type): bool
  replaceByPath(&data, path, new_text, widget_types, field?, item_index?): bool
  replaceById(&data, id, new_text, widget_types, field?, item_index?): bool
  replaceUrlByPath(&data, path, new_url_or_link, widget_types, item_index?): bool
  replaceUrlById(&data, id, new_url_or_link, widget_types, item_index?): bool
  collectAllTextFields(data, widget_types?): { data_structure, elements_count, text_fields }  # text_fields: id, widget_type, field, preview, path, item_index?
  getTextAtSlot(node, widget_type, field?, item_index?): ?string   # for verification
  getLinkUrlAtSlot(node, widget_type, item_index?): ?string
  DEFAULT_WIDGET_TYPES, SUPPORTED_WIDGET_TYPES  # constants

LlmClient
  requestEdits(dictionary: array, instruction, image_slots?: array): { edits: [...], error: string|null }  # Sends dictionary, image_slots, instruction, edit_capabilities to AI. edits: id?, path?, field?, item_index?, new_text?, new_url?, new_link?, new_image_url?, new_attachment_id?, new_image?; config: AI service URL only
  requestKitEdits(kit_settings: { colors, typography }, instruction): { kit_patch: {...}, error: string|null }  # Sends context_type: 'kit', kit_settings, instruction; expects kit_patch (colors?, typography?, settings?); plugin merges by _id

KitStore
  getActiveKitId(): ?int   # option elementor_active_kit
  getPageSettings(): ?array   # post meta _elementor_page_settings on kit post
  updatePageSettings(patch: array): bool   # merge by _id for system_colors/system_typography; value → color for colors
  extractColors(settings): array   # system_colors
  extractTypography(settings): array   # system_typography

Normalizer
  normalize(text: ?string): string
  preview_snippet(normalized_text: string, max_length?): string

CacheRegenerator
  regenerate(post_id: int): void   # deletes _elementor_css meta; files_manager clear_cache; elementor/css_file/post/parse for doc; elementor/core/files/clear_cache; no-op if Elementor absent
```

### Support
```
Errors
  error_response(message, post_id?, http_code): WP_REST_Response
  forbidden(message?, post_id?): WP_REST_Response
  unauthorized(): WP_REST_Response

Logger
  log(message, context?: array): void       # always appends to UI log; also error_log when WP_DEBUG
  log_ui(level, message, context?: array): void
  get_ui_log(): array
  clear_ui_log(): void
```

Interfaces are implicit (PHP classes); infrastructure (WP meta, Elementor API) is behind Services.

---

## 7. Data & State

* **Persistence:** WordPress post meta `_elementor_data` (JSON string or array; Elementor document or raw elements). Read/write via `ElementorDataStore`; `wp_slash` used on save to match WordPress behavior. **Kit:** Option `elementor_active_kit` = kit post ID; post meta `_elementor_page_settings` on that post holds system_colors, system_typography, custom_colors. KitStore merges by _id (update existing; append only if new _id); when applying color patch, copies value → color for Elementor. Option `ai_elementor_sync_ui_log` stores UI log entries (array, max 100). Options `ai_elementor_sync_ai_service_url`, `ai_elementor_sync_llm_register_url`, `ai_elementor_sync_sideload_images` for Settings.
* **Relevant state:** Elementor element tree (nested `elements`, `settings`); match count and candidates during replace; page dictionary is ephemeral (built per request, not stored). CacheRegenerator deletes `_elementor_css` post meta, calls files_manager clear_cache, triggers post-specific parse and clear_cache action so frontend picks up changes.
* **Paths:** Index-based (e.g. "0/1/2"); valid only for the current document. If Elementor data is edited elsewhere, indices can change. **Id** is stable across reorders.
* **Invariants:** Replace (replace-text) is written only when exactly one widget contains the find (normalized); replacement is substring or whole-widget. Apply (llm-edit / apply-edits) writes only when id or path resolves to a target widget.
* **Why editing the element directly didn't work:** Elementor data is stored in post meta; WordPress applies **wp_slash()** when saving meta (escaping backslashes and quotes). Editing the JSON directly (e.g. in the DB) without the same slashing can cause double-escaped or corrupted data on the next read. Elementor also caches generated CSS and files; even if `_elementor_data` is updated correctly, the frontend may show old content until caches are cleared. The plugin avoids this by: read via `get_post_meta`, modify in memory, save with **delete+add then update fallback** and `wp_slash($json)` so the DB is always physically updated (fixes cache/no-op issues), then **CacheRegenerator** (deletes `_elementor_css`, clear_cache, post parse, clear_cache action) so Elementor refreshes its caches.

---

## 8. Error Handling Strategy

* **Permission/validation (Routes):** Return `WP_Error` with 401 (not logged in), 400 (missing url), 403 (url unresolved or no edit_post), 404 (post not found).
* **Controller:** Use `Errors::error_response()` or `Errors::forbidden()` for business failures (e.g. no Elementor data, save failure) with appropriate HTTP codes (400, 403, 404, 500). For llm-edit (page/template), when `LlmClient::requestEdits` returns `error` non-null, return 502 with that message; do not apply any edit. For llm-edit (target=kit), when `LlmClient::requestKitEdits` returns `error` non-null, return 502. **Admin JS:** When REST response is HTML (e.g. 502), `responseToJson` throws; if status is 502, message is "AI service returned an error (502 Bad Gateway). The external LLM service may be down, overloaded, or the AI service URL may be wrong. Check Settings → AI service URL and try again."
* **Invalid edits:** Apply records failed edits in `failed` ([{ id?, path?, error }]); invalid id/path or non-target widgets are skipped, not fatal.
* **Services:** Return null/false or structured arrays; no framework-bound exceptions. `CacheRegenerator` catches Throwable and no-ops so cache never causes fatals. `LlmClient` returns `{ edits, error }`; no API key in plugin.
* **Rule:** No domain-specific exception types; all failures surface as REST responses or WP_Error.

---

## 9. Testing Strategy

* **Current:** No test suite present in the repository.
* **Recommended:** Unit tests for `Normalizer`, `ElementorTraverser` (with fixture data), and `UrlResolver` (or mocked WP); integration tests for REST replace-text and inspect with a test post/meta.
* **Intentionally not tested (by default):** Live Elementor plugin API; production WordPress DB.

---

## 10. Key Decisions & Trade-offs

* **Containment replace:** Find matches when normalized widget text **contains** the find string; replace only when exactly one widget matches; allows substring search (e.g. "normal text" matches "This is a normal text").
* **Apply by id or path:** External service (and apply-edits) may return edits keyed by Elementor **id** (preferred, stable) or **path**; plugin prefers id when present.
* **No API key in WordPress:** Plugin only configures the **AI edit service URL** (option or constant/filter; default Render.com URL); the external service holds the API key and can wrap LangSmith.
* **UI log:** Logger always appends to an in-admin UI log (option `ai_elementor_sync_ui_log`, max 100 entries) so editors can debug LLM/edit/save issues without WP_DEBUG; log view/clear require `edit_posts`.
* **Batch in one request:** Multiple edits from one service call are applied in sequence on the same in-memory tree, then one save; no PHP-level parallelism.
* **Direct apply-edits endpoint:** Enables clients to use their own AI service and only use this plugin to apply; useful for testing and when the WordPress server cannot reach the service.
* **URL-based entry:** Aligns with “page by URL” usage; resolution uses `url_to_postid` and `get_page_by_path` fallback.
* **Request encoding fix (Plugin):** Latin-1 / Windows-1252 request bodies are converted to UTF-8 for replace-text, llm-edit, and apply-edits (mb_convert_encoding; iconv fallback) to support tools that send non-UTF-8 JSON.
* **No abstract repository interface:** Data store is concrete; acceptable for single persistence mechanism (WP meta).
* **ElementorDataStore save strategy:** Always delete+add then update fallback (no "unchanged = success") so the DB is physically written and cache/no-op issues (e.g. verified_after_save) are avoided.
* **CacheRegenerator best-effort:** Deletes `_elementor_css`, files_manager clear_cache, post-specific parse, clear_cache action; Elementor version differences handled by defensive checks; failures are silent to avoid breaking the main flow.
* **Versioning:** On every release or functional change, update Version in plugin header and AI_ELEMENTOR_SYNC_VERSION in ai-elementor-sync.php.

---

## 11. Evolution Notes

* **Plan 1 (implemented):** More widgets (button, icon, image-box, icon-box, testimonial, counter, animated-headline, flip-box, icon-list, accordion, tabs, price-list, price-table), multi-field and repeater support, URL/link editing (new_url/new_link in apply-edits), extended dictionary (field, item_index, link_url), backward-compatible apply-edits (primary field when field omitted).
* **Plan 2 (implemented):** Image widget and container/section background image: buildImageSlots, replaceImageByPath, replaceImageById, getImageSlotTypeByPath/getImageSlotTypeById; Inspect returns image_slots; apply-edits extended with new_image_url, new_attachment_id (or new_image: { url?, id? }); image edits resolve slot_type by id/path and apply via traverser; applied_image_edits in response; Admin Inspect shows image_slots table, Apply-edits placeholder includes image example. **LLM support:** llm-edit sends dictionary (with link_url), image_slots, instruction, and edit_capabilities to the AI; the AI may return text (new_text), link (new_url/new_link), and image (new_image_url/new_attachment_id/new_image) edits; plugin applies all three. Link edits were already supported; image_slots and image edits added. Optional sideload: when "Sideload images from URL" is enabled, image edits with only a URL are downloaded into the media library.
* **Plan 4 (implemented): Theme (Kit) edits.** **KitStore:** getActiveKitId, getPageSettings, updatePageSettings (merge by _id for system_colors/system_typography; value → color when applying color patch), extractColors, extractTypography. **KitSettingsController:** GET/POST kit-settings (manage_options). **LlmEditController:** When target=kit, load kit_settings, call LlmClient::requestKitEdits(kit_settings, instruction); expect kit_patch (colors, typography, settings); merge by _id via KitStore::updatePageSettings. **LlmClient:** requestKitEdits sends context_type: 'kit', kit_settings, instruction; expects kit_patch (or kit_edits/patch). **Admin:** Theme tab with Load kit settings, **Test kit connection** (GET kit-settings; result-kit-test), Save patch (JSON); colors/typography displayed as **tables** from raw_settings (system_colors, system_typography, custom_colors); table shows **value** or **color** for hex (Elementor may use color). **Admin JS:** testKitConnection (REST URL check, fallback result area, console log); renderKitSettingsResult builds tables from raw_settings; colorValue uses item.value or item.color. **502/HTML:** responseToJson throws with friendlier message when status 502 ("AI service returned an error (502 Bad Gateway)..."). **Docs:** docs/LLM-SERVICE-KIT-EDITS.md — contract for LLM app: context_type: kit, kit_patch response, merge by _id (update existing; do not duplicate), value/color, one item per slot to change. README points to LLM-SERVICE-KIT-EDITS.md for kit edits.
* **Potential extensions:** Optional case-insensitive match, batch by URL list; dry-run for AI edits (return proposed edits without applying); rate limiting or optimistic locking; optional `match=exact` for replace-text to force exact equality.
* **Limitations:** Default widget_types remain text-editor and heading for backward compatibility; clients can pass full SUPPORTED_WIDGET_TYPES for full coverage; no undo; no conflict detection—concurrent edits are last-write-wins; path is valid only for current document version (use id for stability).
* **Risks:** Elementor schema or API changes may require updates to traverser or cache regeneration; large pages may hit token limits (dictionary truncation via `max_text_len`); external service cost and latency.

---

## 12. Instructions for LLMs (Critical)

> This section is **explicitly for AI systems**.

* Use this document as the **single source of truth** for high-level behavior and structure; do not assume from partial code reads.
* **Respect boundaries:** Rest layer handles HTTP and permissions; controller orchestrates; services contain logic and infrastructure. Do not put WordPress or Elementor API calls in the controller beyond what exists (e.g. `get_post`, `current_user_can`). AI edit calls must go through `LlmClient`; apply updates must use `ElementorTraverser::replaceById` or `replaceByPath`. Do not bypass these services.
* **Replace (replace-text):** Do not change the rule “replace only when exactly one widget contains the find” without explicit product requirement.
* **Kit (Theme) edits:** LlmEdit with target=kit sends kit_settings (colors, typography) and instruction to the same AI URL; the service must return **kit_patch** (colors/typography). Plugin **merges by _id** (updates existing item; does not append duplicate). LLM app contract: see **docs/LLM-SERVICE-KIT-EDITS.md** (context_type: kit, merge by _id, value/color, one item per slot).
* **Ask before:** Adding new widget types, changing normalizer semantics, or introducing new persistence or external services beyond the configured AI service URL.
* Do not read the entire codebase when this artifact is sufficient for the requested change.

---

## 13. Last Updated

* **Date:** 2026-02-02  
* **Change context:** **Plan 4 (Theme/Kit) and fixes.** KitStore (merge by _id for system_colors/system_typography; value → color for Elementor); KitSettingsController GET/POST kit-settings; LlmEditController target=kit (requestKitEdits → kit_patch → merge by _id); LlmClient requestKitEdits (context_type: kit, kit_patch); Admin Theme tab (Load kit settings, Test kit connection, tables from raw_settings with value/color, Save patch); testKitConnection (REST URL check, fallback result area); 502/HTML friendlier message in responseToJson; docs/LLM-SERVICE-KIT-EDITS.md (contract for LLM app: merge by _id, value/color, one item per slot). README and SYSTEM_ARTIFACT updated with kit-settings, Theme tab, merge-by-_id, and doc reference.
