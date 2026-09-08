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
│  │  ├─ Discussion/
│  │  ├─ Sponsorship/
│  │  ├─ Notification/
│  │  ├─ ExternalFiles/
│  │  ├─ Embeds/
│  │  └─ SiteComposition/
│  ├─ Api/
│  │  ├─ Contract/
│  │  ├─ Presenter/
│  │  └─ Rest/
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
| TinyMCE and shortcode manager | Shortcode inserter planned for migration (2026-09-08): redesigned as "template parts" — the admin keeps an inserter UI storing normalized part data, the API exposes a normalized part structure, Astro owns parsing/rendering; TinyMCE itself retires with the classic editor |
| REST helper | Do not migrate; design new versioned API — auth/user routes rebuilt at ✅ 0.12.0: `aiya/core/v1` AuthController (register with server-side UUID login name, email-only login, password reset via front-end-supplied origin) + UserController (me / profile / avatar / password) with bearer tokens |
| AJAX helper | Replace per admin use case |
| image manager | ✅ 0.9.0: `packages/image-processor` package + `Modules/MediaModule` adapter; cover pipeline and the `_aya_thumb` protocol writer in `Domain/Media/CoverService` |
| internal-pic-bed | ✅ 0.9.0: `Admin/PicBedPage` — path-addressed upload-pics pool, no attachment IDs, processing pipeline injected as a closure |
| visitor counter | Domain/Engagement |
| `inc/func-tweet-post.php` (Tweet domain) | Cancelled (2026-09-08): no migration, no compatibility reader; existing tweet rows (and their `gallery_images` values) stay as dead data |
| `inc/func-issue.php` (Issue domain) | Rebuilt as Domain/Discussion from the design prototype only (2026-09-08, B2 restart); legacy `wp_aya_issues` / `wp_aya_issue_comments` rows are dead data — no migration, no compatibility reader |
| `inc/func-openlist.php` (OpenList embed) | Migrate per legacy logic (2026-09-08): the embed config stays in postmeta group key `aya_box_oplist_client` — no association table; box scope moves from post to the resource CPT (lands with the resource metabox redesign); file listings stay a WP-side proxy (OpenList token never leaves the server); the legacy `[oplist_cli]` shortcode meta-persist layer does not migrate (shortcodes follow the template-parts plan). Revisit meta→table only if a concrete cross-post query need appears |
| `inc/func-notify.php` (site notice dispatcher) | Rebuilt as Domain/Notification (2026-09-08 plan): custom table (type / user_id nullable for broadcast / title / body / role level / created_at) replacing the settings-form hidden-input list; simple admin screen (create + list + delete); daily WP-Cron cleanup with configurable retention (default 30 days); read state stays client-side (Astro local store of last-seen time); designed as the future host for interaction notifications (comment replies, follows) — actor/object columns to be added by migration when those land. The separate consent-popup option list is frontend-owned, no backend needed |
| `inc/func-payment.php` (Afdian integration + redemption codes) | Domain/Sponsorship, rebuild keeping behavior (2026-09-08): Afdian webhook (`afd_` orders, `custom_order_id` user binding, month×31 days, order-id dedup, always-200), order-number-as-code activation, convert-code table with atomic redeem + rollback. `wp_aya_sponsor_orders` stays the canonical order store — schema may only gain columns, semantics (stacking expiration fold + `sponsor_expiration` protocol meta recompute) unchanged; the legacy access settings page is not migrated (the domain gets its own settings page) |
| `plugins/sponsor-order-compat` (Epay gateway) | Domain/Sponsorship, same rebuild: cashier flow + signed GET callback + anti-cross-order check (param user vs order-number-embedded user id) preserved; days resolution by price matching is replaced by plan identity carried in signed params |
| `inc/lib/Afdian_API.php`, `inc/lib/Epay_Core.php` | Third-party API clients rebuilt as WP-free clients (package candidates) — public behavior preserved, code not ported as-is |
| `inc/func-media.php` | Empty placeholder file (5 lines, no code) — dropped (audit 2026-09-08) |
| `inc/func-plyr-player.php` (Plyr player shortcodes) | Player shortcodes ride the template-parts plan (same batch as the shortcode inserter): the API exposes structured part data, Astro renders and loads the player; the legacy front-end player retires |
| `plugins/patch-flow-hub-post` | Retires with the legacy front end; its own-table columns (`like_count` / `diss_count` / `comments_num`) stay in protocol scope as stored data — `diss_count` (downvote) has no counterpart in the new system and is dead data |
| `plugins/multi-domain` | Dropped (2026-09-08): meaningless with a separate front-end app |
| `plugins/classic-editor-modify` | Dropped (2026-09-08) |
| theme registration and templates | Remain in legacy theme, then retire |
| widget framework | Retire with legacy front end |

Field-group consumers in the legacy theme (for parity tracking): `oplist_client` post box (OpenList client fields, protocol key `aya_box_oplist_client`), `post_seo` post box (basic-optimize, protocol key `aya_box_post_seo`), `post_automatic` post box (action checkboxes consumed by basic-automatic), `tips` term fields (per-field term meta keys).

## Delivery sequence

1. Exercise the settings foundation with a small real settings page.
2. Add metadata registries for post, term and user editing screens.
3. Complete field validation, conditional visibility and nested repeater behavior.
4. Add activation, schema-version and migration runners.
5. ✅ Media processing moved behind the `aiya/image-processor` package seam (0.9.0).
6. Move one content domain at a time. The Discussion domain is rebuilt from the legacy Issue prototype — thread-shaped custom tables (type/status workflow, flat replies, `post_id` reverse binding as per-post tickets), renamed to the new contract (2026-09-08 decision, B2 restart; do not port the old implementation). The resource domain maps onto the existing resource CPT (its metabox redesign comes first). The Tweet domain is cancelled (see disposition).
7. Design the new REST representation after domain use cases stabilize.
8. Add compatibility readers only where legacy data must remain readable.

