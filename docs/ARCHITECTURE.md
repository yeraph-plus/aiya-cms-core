# AIYA Core architecture

AIYA Core is a conventional WordPress plugin. It does not depend on an active theme and does not provide front-end templates.

## Current foundation

```text
Plugin runtime
  -> module registry
  -> settings registry
       -> page schema
       -> field schema
       -> value normalizer
       -> WordPress admin adapter
       -> option storage adapter
  -> metadata storage adapters
```

The public registration seam is the `aiya_core_register` action, fired on `init` priority `0`, or `aiya_core()->settings()` before `admin_menu`.

```php
add_action('aiya_core_register', static function (Aiya\Core\Settings\Registry $settings): void {
    $settings->addPage([
        'slug' => 'site',
        'title' => 'Site settings',
        'fields' => [
            ['id' => 'site_name', 'type' => 'text', 'label' => 'Site name'],
            ['id' => 'logo', 'type' => 'media', 'label' => 'Logo'],
        ],
    ]);
});
```

Repeater children are intentionally limited to scalar and choice fields in the first release. Nested repeaters and editor/media fields inside repeaters will be added only after their Backbone lifecycle is modelled explicitly.

## Lifecycle

The plugin owns a full lifecycle and can be activated normally from `wp-content/plugins/`:

- `register()` registers modules and hooks; idempotent. The settings registry is populated only when `init` fires, so on the activation request it is still empty.
- `boot()` runs on every request and delegates to `register()`.
- `activate()` records the `aiya_core_schema_version` option. Field defaults are applied lazily at read time, so no eager seeding is needed.
- `deactivate()` clears scheduled events; modules declare their cron hooks through the `aiya_core_scheduled_events` filter.
- `uninstall.php` deletes every option under the `aiya_core_` prefix (all sites in multisite). There is no "keep settings" switch; the headless rebuild treats uninstall as a full reset.

Schema upgrades run through a future `Runtime/SchemaVersion` migration runner when stored shapes change.

## Data and API layer (contract first)

The Astro front end consumes a versioned HTTP API and never sees WordPress internals:

```text
Api/Rest/       controllers for the aiya/core/v1 namespace;
                validate parameters, call read services and presenters,
                never query WordPress directly
Api/Presenter/  the ONLY layer allowed to touch WP_Post / WP_Term /
                WP_Query results; maps them to DTOs; runs the_content
                filters here (content HTML is contract data)
Api/Contract/   pure value objects (PostSummary, PostDetail, TermDto,
                AuthorDto, ThumbnailDto, MenuTree, MenuItem, Pagination,
                Breadcrumb) plus a contract version constant;
                zero WordPress dependency; TS types for the front end
                are generated from these shapes
Domain/Content/ read services (ContentQuery, MenuService,
                BreadcrumbService, PaginationService) wrapping WordPress
                queries; presenters and controllers call them
```

## Infrastructure packages (`packages/`)

Unit features that used to live in the legacy theme's `plugins/` directory become independent composer packages: `aiya/<slug>`, `type: library`, PSR-4 `Aiya\Infra\<Name>\`. Packages MUST NOT depend on aiya-core, call WordPress functions, or register hooks; a core-side adapter module under `src/Modules/` instantiates the package service, registers its settings into the shared add-ons page, and wires it into the module system. The dependency arrow is one-directional: core -> package.

## Error handling conventions

- **REST layer** (`Api/Rest/`): every failure is a `WP_Error` with an
  `aiya_*` code and an explicit `['status' => …]`; the envelope turns it
  into the JSON error shape. Status mapping: 400 client input, 401
  unauthenticated, 403 forbidden, 404 missing, 409 conflict, 500 plugin/db
  failure, 502 upstream unavailable. An uncaught `RuntimeException` from
  the domain would escape as a bare 500, so REST callbacks catch
  exceptions at the boundary and convert them (see
  `AuthController::sessionResponse`).
- **Domain layer**: returns `WP_Error` for caller-actionable failures;
  throws `RuntimeException` only when continuing is meaningless (e.g. a
  token write failed). Metrics and denormalized counters may degrade
  silently; anything that breaks a business or security invariant must be
  reportable (return values, logs) — e.g. `TokenStore::revokeAll()`
  returns `bool`, gateway callbacks answer "fail" so the platform retries.
- **Admin pages**: capability check + `wp_die`, nonce via
  `check_admin_referer`, outcome via flash redirect; a domain `WP_Error`
  is logged (`error_log` with the `aiya-core` prefix) before collapsing
  into the generic flash so operators can diagnose.
- **Tolerated silence** (declared, not accidental): logout token
  revocation, webhook debug log writes, best-effort avatar file cleanup.

## Direction of dependencies

- Admin code may depend on Settings schema.
- WordPress storage adapters may depend on WordPress functions.
- Schema and normalization must not depend on admin HTML.
- Content domains must not depend on Admin, Api, or HTTP transports.
- `Api/Contract/` depends on nothing; `Api/Presenter/` is the only WordPress-data touch point; `Api/Rest/` only orchestrates.
- `packages/` packages never depend back on core; integration is adapter-only.
- Astro and other front ends consume the versioned API contract; they never load this framework directly.

## Administrative UI

The UI uses WordPress admin markup and registered WordPress dependencies. Backbone owns dynamic field views. jQuery UI supplies sortable behavior. WordPress Media and Code Editor APIs replace private upload and CDN CodeMirror implementations. No Gutenberg or React package is required.
