# System Artifact
> High-level system reference document. Its purpose is to provide **compressed, high-signal context** for humans and LLMs. It does NOT replace code or detailed documentation.

---

## 1. Overview

### 1.1 Purpose
This system enables **text replacement** and **natural-language edits via an external AI service** in Elementor page content (Text Editor and Heading widgets) via a REST API keyed by page URL. Find/replace uses **substring (containment)** match: "normal text" matches "This is a normal text"; replace is applied only when **exactly one** widget contains the find. Widget-elements can be identified by Elementor's stable **`id`** (in addition to index path). The plugin calls a **single external AI edit service URL** (your proxy); no API key or model is stored in WordPress. It is for **editors and AI/tooling** that need to update page copy programmatically.

### 1.2 Non-Goals
* Does not handle authentication beyond WordPress (no custom auth)
* Does not store or send LLM API keys; key and LLM control (e.g. LangSmith) live in the external service
* Does not support bulk or multi-page replacement in one request
* Does not replace text in widgets other than `text-editor` and `heading` (unless extended via `widget_types`)
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
    AdminPage.php              # Settings submenu, admin UI page, enqueue assets (admin.css, admin.js)
  Rest/
    Routes.php                 # Route registration, permission callbacks (incl. create-application-password)
    Controllers/
      ReplaceTextController.php # replace_text, inspect
      LlmEditController.php     # llm_edit, apply_edits
      ApplicationPasswordController.php # create_application_password
  Services/
    UrlResolver.php            # URL → post_id
    ElementorDataStore.php     # get/save _elementor_data
    ElementorTraverser.php     # find/replace (containment), buildPageDictionary, replaceByPath, replaceById, collectAllTextFields
    Normalizer.php             # normalize text for matching, preview_snippet
    LlmClient.php              # requestEdits (dictionary array, instruction); config: AI service URL only
    CacheRegenerator.php       # Elementor cache/CSS regeneration
  Support/
    Errors.php                 # REST error response helpers
    Logger.php                 # WP_DEBUG logging
```

Brief description of each:
* **Plugin:** Bootstrap and REST wiring; fixes request body encoding for replace-text, llm-edit, and apply-edits routes; inits AdminPage.
* **Admin:** Settings submenu “AI Elementor Sync”; single page with tabs for Inspect, Replace text, LLM edit, Apply edits, and Application password. Uses cookie + nonce to call REST endpoints; “Create application password” calls create-application-password; “Register with LLM app” POSTs site_url, username, application_password to optional LLM register URL (option `ai_elementor_sync_llm_register_url` pre-fills the field).
* **Rest:** Delivery layer; routes, permission checks, controllers that orchestrate services.
* **Services:** Application logic (traverser, normalizer, LLM client) and infrastructure (data store, URL resolution, cache).
* **Support:** Cross-cutting helpers (errors, logging).

---

## 4. Core Domain Model

### 4.1 Main Entities / Concepts
* **Page:** Identified by URL; resolved to a WordPress post (ID). Must be editable by the current user.
* **Elementor document:** Tree of elements stored in post meta `_elementor_data`; either `{ content: [ elements ] }` or raw elements array.
* **Widget (text-editor / heading):** Element with `elType === 'widget'` and `widgetType` in allowed list; text lives in `settings.editor` or `settings.title`.
* **Match:** A widget **contains** the find string (normalized); replacement is applied only when there is **exactly one** such widget. If raw value contains find literally, replace that substring; else replace entire widget value.
* **Page dictionary:** Ephemeral per request; list of `{ id, path, widget_type, text }` for target widgets only. Built from current `_elementor_data`; used by external AI edit service. Not persisted.
* **Path:** String of element indices (e.g. `"0/1/2"`). Index-based; valid only for the current document version.
* **Id:** Elementor's unique element `id` (string) in the JSON; stable across reorders; preferred over path for apply-edits.

### 4.2 Key Attributes
* **Replace request:** `url` (required), `find` (required), `replace` (required), `widget_types` (optional, default `['text-editor','heading']`).
* **Replace result:** `status` ∈ { `updated` | `not_found` | `ambiguous` }, `post_id`, `matches_found`, `matches_replaced`; `candidates` only when `ambiguous`.
* **Inspect result:** `post_id`, `data_structure`, `elements_count`, `text_fields` (id, widget_type, field, preview, path).
* **LlmEdit request:** `url` (required), `instruction` (required), `widget_types` (optional).
* **LlmEdit result:** `status` (`ok` | `error`), `post_id`, `applied_count`, `failed` ([{ id?, path?, error }]); on service failure, 502 with `message`.
* **ApplyEdits request:** `url` (required), `edits` (required; array of `{ id?, path?, new_text }`), `widget_types` (optional).
* **ApplyEdits result:** Same as LlmEdit result.

---

## 5. Use Cases

### ReplaceText (POST /ai-elementor/v1/replace-text)
* **Input:** `url`, `find`, `replace`, optional `widget_types`.
* **Output:** REST response with `status` (`updated` | `not_found` | `ambiguous`), `post_id`, `matches_found`, `matches_replaced`, and `candidates` when ambiguous.
* **Rules:** Resolve URL → post; load Elementor data; traverse and count widgets whose **normalized text contains** find; if exactly one, replace (substring if raw contains find, else whole widget), save, regenerate cache; otherwise return not_found or ambiguous with candidates.

### Inspect (GET /ai-elementor/v1/inspect?url=...)
* **Input:** `url` (query).
* **Output:** Post ID, data structure type, element count, and list of text fields (id, widget_type, field, preview, path) for debugging.
* **Rules:** Same permission as replace (edit_post); read-only; no persistence changes.

### LlmEdit (POST /ai-elementor/v1/llm-edit)
* **Input:** `url`, `instruction` (natural language), optional `widget_types`.
* **Output:** `status` (`ok` | `error`), `post_id`, `applied_count`, `failed` ([{ id?, path?, error }]). On AI service failure, 502 with `message`.
* **Rules:** Resolve URL → post; load Elementor data; build dictionary (id, path, widget_type, text) via `buildPageDictionary`; call `LlmClient::requestEdits(dictionary, instruction)`; for each edit use `replaceById` if id present else `replaceByPath`; save once if any applied; regenerate cache. Empty edits = success with `applied_count: 0`.

### ApplyEdits (POST /ai-elementor/v1/apply-edits)
* **Input:** `url`, `edits` (array of `{ id?, path?, new_text }`), optional `widget_types`.
* **Output:** Same as LlmEdit (status, post_id, applied_count, failed).
* **Rules:** No AI service call; same permission and apply logic (prefer id over path). For clients that call their own AI service and only need to apply edits.

### CreateApplicationPassword (POST /ai-elementor/v1/create-application-password)
* **Input:** None (POST body optional).
* **Output:** `{ password, username }` (plain password shown once) or error (e.g. app_passwords_unavailable, app_password_exists).
* **Rules:** Requires `manage_options`. Uses `WP_Application_Passwords::create_new_application_password` for current user with name “AI Elementor Sync”. If a password with that name already exists, returns 400; revoke from profile first. Used by the admin UI so external tools (e.g. LLM app) can call this site’s REST API with Basic auth.

### Admin UI and register with LLM app
* **Admin UI:** Under Settings → AI Elementor Sync, editors can run Inspect, Replace text, LLM edit, Apply edits via forms (cookie + nonce). No Application Password needed for in-admin use.
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
  findAndMaybeReplace(&data, find, replace, widget_types): { matches_found, matches_replaced, candidates, data }  # match = contains (normalized)
  buildPageDictionary(data, widget_types, max_text_len?): [{ id, path, widget_type, text }, ...]
  replaceByPath(&data, path, new_text, widget_types): bool
  replaceById(&data, id, new_text, widget_types): bool
  collectAllTextFields(data, widget_types): { data_structure, elements_count, text_fields }  # text_fields include id

LlmClient
  requestEdits(dictionary: array, instruction): { edits: [{ id?, path?, new_text }], error: string|null }  # config: AI service URL only

Normalizer
  normalize(text: ?string): string
  preview_snippet(normalized_text: string, max_length?): string

CacheRegenerator
  regenerate(post_id: int): void   # no-op if Elementor absent
```

### Support
```
Errors
  error_response(message, post_id?, http_code): WP_REST_Response
  forbidden(message?, post_id?): WP_REST_Response
  unauthorized(): WP_REST_Response

Logger
  log(message, context?: array): void   # only when WP_DEBUG
```

Interfaces are implicit (PHP classes); infrastructure (WP meta, Elementor API) is behind Services.

---

## 7. Data & State

* **Persistence:** WordPress post meta `_elementor_data` (JSON string or array; Elementor document or raw elements). Read/write via `ElementorDataStore`; `wp_slash` used on save to match WordPress behavior.
* **Relevant state:** Elementor element tree (nested `elements`, `settings`); match count and candidates during replace; page dictionary is ephemeral (built per request, not stored).
* **Paths:** Index-based (e.g. "0/1/2"); valid only for the current document. If Elementor data is edited elsewhere, indices can change. **Id** is stable across reorders.
* **Invariants:** Replace (replace-text) is written only when exactly one widget contains the find (normalized); replacement is substring or whole-widget. Apply (llm-edit / apply-edits) writes only when id or path resolves to a target widget.
* **Why editing the element directly didn't work:** Elementor data is stored in post meta; WordPress applies **wp_slash()** when saving meta (escaping backslashes and quotes). Editing the JSON directly (e.g. in the DB) without the same slashing can cause double-escaped or corrupted data on the next read. Elementor also caches generated CSS and files; even if `_elementor_data` is updated correctly, the frontend may show old content until caches are cleared. The plugin avoids this by: read via `get_post_meta`, modify in memory, save with `update_post_meta` and `wp_slash($json)`, then **CacheRegenerator** so Elementor refreshes its caches.

---

## 8. Error Handling Strategy

* **Permission/validation (Routes):** Return `WP_Error` with 401 (not logged in), 400 (missing url), 403 (url unresolved or no edit_post), 404 (post not found).
* **Controller:** Use `Errors::error_response()` or `Errors::forbidden()` for business failures (e.g. no Elementor data, save failure) with appropriate HTTP codes (400, 403, 404, 500). For llm-edit, when `LlmClient::requestEdits` returns `error` non-null, return 502 with that message; do not apply any edit.
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
* **No API key in WordPress:** Plugin only configures the **AI edit service URL** (option or constant/filter); the external service holds the API key and can wrap LangSmith.
* **Batch in one request:** Multiple edits from one service call are applied in sequence on the same in-memory tree, then one save; no PHP-level parallelism.
* **Direct apply-edits endpoint:** Enables clients to use their own AI service and only use this plugin to apply; useful for testing and when the WordPress server cannot reach the service.
* **URL-based entry:** Aligns with “page by URL” usage; resolution uses `url_to_postid` and `get_page_by_path` fallback.
* **Request encoding fix (Plugin):** Latin-1 / Windows-1252 request bodies are converted to UTF-8 for replace-text, llm-edit, and apply-edits to support tools that send non-UTF-8 JSON.
* **No abstract repository interface:** Data store is concrete; acceptable for single persistence mechanism (WP meta).
* **CacheRegenerator best-effort:** Elementor version differences handled by defensive checks; failures are silent to avoid breaking the main flow.
* **Versioning:** On every release or functional change, update Version in plugin header and AI_ELEMENTOR_SYNC_VERSION in ai-elementor-sync.php.

---

## 11. Evolution Notes

* **Potential extensions:** More widget types, optional case-insensitive match, batch by URL list; dry-run for AI edits (return proposed edits without applying); rate limiting or optimistic locking; optional `match=exact` for replace-text to force exact equality.
* **Limitations:** Only two widget types by default; no undo; no conflict detection—concurrent edits from multiple users are last-write-wins; path is valid only for current document version (use id for stability).
* **Risks:** Elementor schema or API changes may require updates to traverser or cache regeneration; large pages may hit token limits (dictionary truncation via `max_text_len`); external service cost and latency.

---

## 12. Instructions for LLMs (Critical)

> This section is **explicitly for AI systems**.

* Use this document as the **single source of truth** for high-level behavior and structure; do not assume from partial code reads.
* **Respect boundaries:** Rest layer handles HTTP and permissions; controller orchestrates; services contain logic and infrastructure. Do not put WordPress or Elementor API calls in the controller beyond what exists (e.g. `get_post`, `current_user_can`). AI edit calls must go through `LlmClient`; apply updates must use `ElementorTraverser::replaceById` or `replaceByPath`. Do not bypass these services.
* **Replace (replace-text):** Do not change the rule “replace only when exactly one widget contains the find” without explicit product requirement.
* **Ask before:** Adding new widget types, changing normalizer semantics, or introducing new persistence or external services beyond the configured AI service URL.
* Do not read the entire codebase when this artifact is sufficient for the requested change.

---

## 13. Last Updated

* **Date:** 2025-01-30  
* **Author:** (fill as needed)  
* **Change context:** Admin UI (Settings → AI Elementor Sync) with tabs for Inspect, Replace text, LLM edit, Apply edits, Application password; create-application-password REST endpoint; Register with LLM app flow (optional llm_register_url option); unified UI lives in LLM app; version bump to 1.2.0.
