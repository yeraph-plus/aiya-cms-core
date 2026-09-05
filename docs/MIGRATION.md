# Migration map

This file plans migration ownership; it does not require preserving legacy classes or data shapes.

## Target tree

```text
aiya-core/
├─ aiya-core.php
├─ src/
│  ├─ Contracts/
│  ├─ Runtime/
│  ├─ Settings/
│  │  ├─ Schema/
│  │  └─ Storage/
│  ├─ Admin/
│  ├─ Metadata/
│  ├─ Infrastructure/
│  │  ├─ Cache/
│  │  ├─ Database/
│  │  ├─ Http/
│  │  ├─ Jobs/
│  │  ├─ Logging/
│  │  ├─ Media/
│  │  └─ Security/
│  ├─ Domain/
│  │  ├─ Content/
│  │  ├─ Identity/
│  │  ├─ Engagement/
│  │  ├─ Issue/
│  │  ├─ Tweet/
│  │  ├─ Sponsorship/
│  │  ├─ Notification/
│  │  ├─ ExternalFiles/
│  │  ├─ Embeds/
│  │  └─ SiteComposition/
│  └─ Http/
│     └─ Rest/
├─ assets/
│  ├─ css/
│  └─ js/
├─ languages/
├─ tests/
│  ├─ Unit/
│  └─ Integration/
└─ docs/
```

Directories are created when their first working tracer slice is implemented; empty speculative folders are not committed.

## Legacy disposition

| Legacy area | Destination |
|---|---|
| `framework-setup.php` | Replace with plugin runtime and explicit modules |
| option page and fields | Migrate through Settings schema |
| post/term meta | Rebuild under Metadata using storage adapters |
| user meta | Add under Metadata |
| `plugin/register-theme-post-type.php` | Rebuild as code-first post-type registrar with headless defaults (`show_in_rest`); the sticky-in-archive `the_posts` hack retires with the front end |
| `plugin/register-theme-taxonomy.php` | Rebuild as code-first taxonomy registrar (`show_in_rest` was already on) |
| TinyMCE and shortcode manager | Optional ClassicEditor module |
| REST helper | Do not migrate; design new versioned API |
| AJAX helper | Replace per admin use case |
| image manager | ✅ 0.9.0: `packages/image-processor` package + `Modules/MediaModule` adapter; cover pipeline and the `_aya_thumb` protocol writer in `Domain/Media/CoverService` |
| internal-pic-bed | ✅ 0.9.0: `Admin/PicBedPage` — path-addressed upload-pics pool, no attachment IDs, processing pipeline injected as a closure |
| visitor counter | Domain/Engagement |
| theme registration and templates | Remain in legacy theme, then retire |
| widget framework | Retire with legacy front end |

Field-group consumers in the legacy theme (for parity tracking): `oplist_client` post box (OpenList client fields, protocol key `aya_box_oplist_client`), `post_seo` post box (basic-optimize, protocol key `aya_box_post_seo`), `post_automatic` post box (action checkboxes consumed by basic-automatic), `tips` term fields (per-field term meta keys).

## Delivery sequence

1. Exercise the settings foundation with a small real settings page.
2. Add metadata registries for post, term and user editing screens.
3. Complete field validation, conditional visibility and nested repeater behavior.
4. Add activation, schema-version and migration runners.
5. ✅ Media processing moved behind the `aiya/image-processor` package seam (0.9.0).
6. Move one content domain at a time, starting with Issue or Tweet.
7. Design the new REST representation after domain use cases stabilize.
8. Add compatibility readers only where legacy data must remain readable.

