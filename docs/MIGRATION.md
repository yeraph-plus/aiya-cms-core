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
| `inc/settings/opt-basic.php` (site preferences) | ✅ 0.29.0: rebuilt as `Domain/Content/FrontendModule` + `GET /site` — logo (media field replaces the customizer logo, which the headless strip made unreachable), default color mode, default cover, ICP / public-security filing + footer note feed `defaults`/`footer`; logo caption toggle, list layout and cookie consent are front-end-owned; the global comment kill switch is abolished (comments are permanent, `/wp/v2/comments` retired unconditionally) |
| post/term meta | Rebuild under Metadata using storage adapters |
| user meta | Add under Metadata |
| `plugin/register-theme-post-type.php` | Rebuild as code-first post-type registrar with headless defaults (`show_in_rest`); the sticky-in-archive `the_posts` hack retires with the front end |
| `plugin/register-theme-taxonomy.php` | Rebuild as code-first taxonomy registrar (`show_in_rest` was already on) |
| TinyMCE and shortcode manager | Shortcode inserter redesigned as "template parts". ✅ 0.34.0 editor-side framework: `Domain/Parts` PartType/PartRegistry/PartModule — wpdialogs dialog (core link-dialog stack), classic toolbar button position kept, insertion via send_to_editor; the 12 legacy shortcode components do NOT carry over (owner decision 2026-09-11: server-rendered Tailwind HTML conflicts with the structured-part semantics; the catalog starts empty and fills via `aiya_core_register_parts`); the structured-parts parser batch was cancelled (2026-09-11): parts WITH renderers register as real shortcodes and render into custom HTML tags server-side; the front end parses those tags into islands TinyMCE itself retires with the classic editor |
| REST helper | Do not migrate; design new versioned API — auth/user routes rebuilt at ✅ 0.12.0: `aiya/core/v1` AuthController (register with server-side UUID login name, email-only login, password reset via front-end-supplied origin) + UserController (me / profile / avatar / password) with bearer tokens |
| AJAX helper | Replace per admin use case |
| image manager | ✅ 0.9.0: `packages/image-processor` package + `Modules/MediaModule` adapter; cover pipeline and the `_thumb` protocol writer in `Domain/Media/CoverService` (renamed from the legacy `_aya_thumb` key, 0.31.0 — auto-generated value, no compat reads) |
| internal-pic-bed | ✅ 0.9.0: `Admin/PicBedPage` — path-addressed upload-pics pool, no attachment IDs, processing pipeline injected as a closure |
| visitor counter | Domain/Engagement |
| `inc/func-tweet-post.php` (Tweet domain) | Cancelled (2026-09-08): no migration, no compatibility reader; existing tweet rows (and their `gallery_images` values) stay as dead data |
| `inc/func-issue.php` (Issue domain) | Rebuilt as Domain/Discussion from the design prototype only (2026-09-08, B2 restart); legacy `wp_aya_issues` / `wp_aya_issue_comments` rows are dead data — no migration, no compatibility reader |
| `inc/func-openlist.php` (OpenList embed) | ✅ 0.27.0: Domain/ExternalFiles — `oplist_client` box (protocol group key renamed to `aiya_core_oplist_client` in 0.31.0, verbatim nine fields) moved to the resource screen; domain settings page; read-only OpenListClient with token caching; `GET /resources/{id}/attachments` serves public listing metadata with download links trimmed per viewer (2026-09-09 gate redesign — no trigger counting). The `[oplist_cli]` shortcode meta-persist layer is not migrated (template-parts plan). Parked since 0.29.1 (2026-09-10 business routing): module registration disabled via the Plugin domain flags — code, tables and protocol keys untouched; flip the flag to restore |
| `inc/func-notify.php` (site notice dispatcher) | ✅ 0.23.0: Domain/Notification — custom table `wp_aiya_notifications` (type / user_id nullable for broadcast / title / body / role level / created_at) replacing the settings-form hidden-input list; admin screen (create + list + delete + retention); daily WP-Cron cleanup (default 30 days, configurable); read state stays client-side (Astro local store of last-seen time); role ladder aligned with UserPresenter semantics; future interaction notifications reuse the table (actor/object columns arrive by migration). The consent-popup option list is frontend-owned |
| `inc/func-payment.php` (Afdian integration + redemption codes) | ✅ 0.24.0 core + 0.25.0 gateway slices: Domain/Sponsorship — order store + stacking fold + `sponsor_expiration` writer (OrderService), atomic redemption with rollback (RedeemCodeService), domain settings page, plans/redeem/membership REST; Afdian webhook rebuilt with signature verification + trade-status check (`aiya/sponsorship/v1/afdian/callback`), order-number redemption online-verified in the redeem endpoint. Parked since 0.29.1 (2026-09-10 business routing): module registration disabled via the Plugin domain flags — code, tables and protocol keys untouched; flip the flag to restore |
| `plugins/sponsor-order-compat` (Epay gateway) | ✅ 0.25.0: `GET aiya/sponsorship/v1/epay/callback` with legacy-algorithm signature verification; cashier submit built by `POST /sponsorship/orders` returning a signed gateway URL; days come from the plan key carried in signed params (amount matching abolished); legacy template pages retire with the front end |
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

