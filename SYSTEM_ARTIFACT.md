# System Artifact
> High-level system reference document. Its purpose is to provide **compressed, high-signal context** for humans and LLMs. It does NOT replace code or detailed documentation.

---

## 1. Overview

### 1.1 Purpose
This system enables **unambiguous text replacement** in Elementor page content (Text Editor and Heading widgets) via a REST API keyed by page URL. It is for **editors and AI/tooling** that need to update page copy programmatically while avoiding accidental multi-match overwrites.

### 1.2 Non-Goals
* Does not handle authentication beyond WordPress (no custom auth)
* Does not support bulk or multi-page replacement in one request
* Does not replace text in widgets other than `text-editor` and `heading` (unless extended via `widget_types`)
* Does not optimize for high concurrency
* Does not provide a UI; API-only

---

## 2. Architectural Style

### 2.1 Architecture Pattern
Layered WordPress plugin: **API layer → Controller → Services (application + infrastructure)**. No formal Clean Architecture; domain rules are embedded in the traverser and controller.

### 2.2 Core Principles
* **Unambiguous replacement only:** Replace is performed only when exactly one widget field matches the find string (normalized).
* **URL as entry key:** Operations are keyed by page URL; resolution to `post_id` is centralized in `UrlResolver`.
* **Permission at API boundary:** Auth and `edit_post` are enforced in routes/controller before touching Elementor data.
* **Infrastructure behind services:** Elementor data and WordPress meta are accessed only through `ElementorDataStore` and `UrlResolver`; cache regeneration is encapsulated in `CacheRegenerator`.

---

## 3. High-Level Structure

```
ai-elementor-sync.php          # Bootstrap, autoload, Plugin::init
includes/
  Plugin.php                   # REST registration, request encoding fix
  Rest/
    Routes.php                 # Route registration, permission callbacks
    Controllers/
      ReplaceTextController.php # replace_text, inspect
  Services/
    UrlResolver.php            # URL → post_id
    ElementorDataStore.php     # get/save _elementor_data
    ElementorTraverser.php     # find/replace in element tree, collect text fields
    Normalizer.php             # normalize text for matching, preview_snippet
    CacheRegenerator.php       # Elementor cache/CSS regeneration
  Support/
    Errors.php                 # REST error response helpers
    Logger.php                 # WP_DEBUG logging
```

Brief description of each:
* **Plugin:** Bootstrap and REST wiring; fixes request body encoding for the replace-text route.
* **Rest:** Delivery layer; routes, permission checks, controller that orchestrates services.
* **Services:** Application logic (traverser, normalizer) and infrastructure (data store, URL resolution, cache).
* **Support:** Cross-cutting helpers (errors, logging).

---

## 4. Core Domain Model

### 4.1 Main Entities / Concepts
* **Page:** Identified by URL; resolved to a WordPress post (ID). Must be editable by the current user.
* **Elementor document:** Tree of elements stored in post meta `_elementor_data`; either `{ content: [ elements ] }` or raw elements array.
* **Widget (text-editor / heading):** Element with `elType === 'widget'` and `widgetType` in allowed list; text lives in `settings.editor` or `settings.title`.
* **Match:** A widget field whose normalized visible text equals the normalized find string. Replacement is only applied when there is **exactly one** match.

### 4.2 Key Attributes
* **Replace request:** `url` (required), `find` (required), `replace` (required), `widget_types` (optional, default `['text-editor','heading']`).
* **Replace result:** `status` ∈ { `updated` | `not_found` | `ambiguous` }, `post_id`, `matches_found`, `matches_replaced`; `candidates` only when `ambiguous`.
* **Inspect result:** `post_id`, `data_structure`, `elements_count`, `text_fields` (widget_type, field, preview, path).

---

## 5. Use Cases

### ReplaceText (POST /ai-elementor/v1/replace-text)
* **Input:** `url`, `find`, `replace`, optional `widget_types`.
* **Output:** REST response with `status` (`updated` | `not_found` | `ambiguous`), `post_id`, `matches_found`, `matches_replaced`, and `candidates` when ambiguous.
* **Rules:** Resolve URL → post; load Elementor data; traverse and count matches (normalized); if exactly one match, replace in tree, save, regenerate cache; otherwise do not modify and return not_found or ambiguous with candidates.

### Inspect (GET /ai-elementor/v1/inspect?url=...)
* **Input:** `url` (query).
* **Output:** Post ID, data structure type, element count, and list of text fields (widget_type, field, preview, path) for debugging.
* **Rules:** Same permission as replace (edit_post); read-only; no persistence changes.

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
  findAndMaybeReplace(&data, find, replace, widget_types): { matches_found, matches_replaced, candidates, data }
  collectAllTextFields(data, widget_types): { data_structure, elements_count, text_fields }

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
* **Relevant state:** Elementor element tree (nested `elements`, `settings`); match count and candidates during replace.
* **Invariants:** Replace is written only when `matches_found === 1`; for `text-editor`, raw HTML is updated only if the exact `find` string appears in the field (normalized match used for counting only).

---

## 8. Error Handling Strategy

* **Permission/validation (Routes):** Return `WP_Error` with 401 (not logged in), 400 (missing url), 403 (url unresolved or no edit_post), 404 (post not found).
* **Controller:** Use `Errors::error_response()` or `Errors::forbidden()` for business failures (e.g. no Elementor data, save failure) with appropriate HTTP codes (400, 403, 404, 500).
* **Services:** Return null/false or structured arrays; no framework-bound exceptions. `CacheRegenerator` catches Throwable and no-ops so cache never causes fatals.
* **Rule:** No domain-specific exception types; all failures surface as REST responses or WP_Error.

---

## 9. Testing Strategy

* **Current:** No test suite present in the repository.
* **Recommended:** Unit tests for `Normalizer`, `ElementorTraverser` (with fixture data), and `UrlResolver` (or mocked WP); integration tests for REST replace-text and inspect with a test post/meta.
* **Intentionally not tested (by default):** Live Elementor plugin API; production WordPress DB.

---

## 10. Key Decisions & Trade-offs

* **Unambiguous-only replace:** Avoids accidental multi-match overwrites; clients must narrow find string or use inspect to disambiguate.
* **URL-based entry:** Aligns with “page by URL” usage; resolution uses `url_to_postid` and `get_page_by_path` fallback.
* **Request encoding fix (Plugin):** Latin-1 / Windows-1252 request bodies are converted to UTF-8 for the replace-text route to support tools that send non-UTF-8 JSON.
* **No abstract repository interface:** Data store is concrete; acceptable for single persistence mechanism (WP meta).
* **CacheRegenerator best-effort:** Elementor version differences handled by defensive checks; failures are silent to avoid breaking the main flow.

---

## 11. Evolution Notes

* **Potential extensions:** More widget types, optional case-insensitive match, batch by URL list, or “replace by path” when ambiguous.
* **Limitations:** Only two widget types by default; no undo; no conflict detection with concurrent editors.
* **Risks:** Elementor schema or API changes may require updates to traverser or cache regeneration.

---

## 12. Instructions for LLMs (Critical)

> This section is **explicitly for AI systems**.

* Use this document as the **single source of truth** for high-level behavior and structure; do not assume from partial code reads.
* **Respect boundaries:** Rest layer handles HTTP and permissions; controller orchestrates; services contain logic and infrastructure. Do not put WordPress or Elementor API calls in the controller beyond what exists (e.g. `get_post`, `current_user_can`).
* **Unambiguous replacement:** Do not change the rule “replace only when exactly one match” without explicit product requirement.
* **Ask before:** Adding new widget types, changing normalizer semantics, or introducing new persistence or external services.
* Do not read the entire codebase when this artifact is sufficient for the requested change.

---

## 13. Last Updated

* **Date:** 2025-01-30  
* **Author:** (fill as needed)  
* **Change context:** Initial System Artifact for ai-elementor-sync project.
