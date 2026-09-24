# Migration map

This file plans migration ownership; it does not require preserving legacy classes or data shapes.

## Target tree

```text
aiya-core/
├─ aiya-core.php
├─ src/
│  ├─ Contracts/
│  ├─ Runtime/
│  ├─ Command/
│  ├─ Settings/
│  │  ├─ Schema/
│  │  └─ Storage/
│  ├─ Admin/
│  ├─ Metadata/
│  ├─ Modules/
│  ├─ Infrastructure/
│  │  ├─ Headless/
│  │  ├─ Http/
│  │  ├─ Security/
│  │  └─ Uninstall/
│  ├─ Domain/
│  │  ├─ Content/
│  │  ├─ Credit/
│  │  ├─ DevTools/
│  │  ├─ Discussion/
│  │  ├─ Identity/
│  │  ├─ Engagement/
│  │  ├─ FileServe/
│  │  ├─ Media/
│  │  ├─ Notification/
│  │  ├─ Operations/
│  │  ├─ Parts/
│  │  ├─ Smilies/
│  │  ├─ Sponsorship/
│  │  └─ ThemeSupport/
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
| `framework-setup.php` | ✅ 0.2.0: replaced with the plugin runtime and explicit modules |
| option page and fields | ✅ migrated through the Settings schema (pages / fields / normalizer — see ARCHITECTURE.md) |
| `inc/settings/opt-basic.php` (site preferences) | ✅ 0.29.0: rebuilt as `Domain/Content/FrontendModule` + `GET /site` — logo (media field replaces the customizer logo, which the headless strip made unreachable), default color mode, default cover, ICP / public-security filing + footer note feed `defaults`/`footer`; logo caption toggle, list layout and cookie consent are front-end-owned; the global comment kill switch is abolished (comments are permanent, `/wp/v2/comments` retired unconditionally) |
| post/term meta | ✅ 0.7.0: rebuilt under Metadata using storage adapters (`Metadata/Storage/*` + `MetaboxAdmin`) |
| user meta | ✅ 0.7.0: added under Metadata (user fields screens) |
| `plugin/register-theme-post-type.php` | ✅ 0.7.0: rebuilt as the code-first `Domain/Content/ContentTypeModule` (`show_in_rest` default on); the sticky-in-archive `the_posts` hack retires with the front end |
| `plugin/register-theme-taxonomy.php` | ✅ 0.7.0 / 0.15.0: code-first taxonomy registrar (`ContentTypeModule`; resource vocabularies 0.15.0, `show_in_rest` on) |
| TinyMCE and shortcode manager | Shortcode inserter redesigned as "template parts". ✅ 0.34.0 editor-side framework: `Domain/Parts` PartType/PartRegistry/PartModule — wpdialogs dialog (core link-dialog stack), classic toolbar button position kept, insertion via send_to_editor; the 12 legacy shortcode components do NOT carry over (owner decision 2026-09-11: server-rendered Tailwind HTML conflicts with the structured-part semantics; the catalog starts empty and fills via `aiya_core_register_parts`); the structured-parts parser batch was cancelled (2026-09-11): parts WITH renderers register as real shortcodes and render into custom HTML tags server-side; the front end parses those tags into islands TinyMCE itself retires with the classic editor |
| REST helper | Do not migrate; design new versioned API — auth/user routes rebuilt at ✅ 0.12.0: `aiya/core/v1` AuthController (register with server-side UUID login name, email-only login, password reset via front-end-supplied origin) + UserController (me / profile / avatar / password) with bearer tokens |
| AJAX helper | Replace per admin use case |
| image manager | ✅ 0.9.0: `packages/image-processor` package + `Modules/MediaModule` adapter; cover pipeline and the `_thumb` protocol writer in `Domain/Media/CoverService` (renamed from the legacy `_aya_thumb` key, 0.31.0 — auto-generated value, no compat reads) |
| internal-pic-bed | ✅ 0.9.0: `Admin/PicBedPage` — path-addressed upload-pics pool, no attachment IDs, processing pipeline injected as a closure |
| visitor counter | ✅ 0.16.0–0.18.0: Domain/Engagement (like/view/rating with visitor dedup, feature matrix per post type) |
| `inc/func-tweet-post.php` (Tweet domain) | Cancelled (2026-09-08): no migration, no compatibility reader; existing tweet rows (and their `gallery_images` values) stay as dead data |
| `inc/func-issue.php` (Issue domain) | Rebuilt as Domain/Discussion from the design prototype only (2026-09-08, B2 restart); legacy `wp_aya_issues` / `wp_aya_issue_comments` rows are dead data — no migration, no compatibility reader |
| `inc/func-openlist.php` (OpenList embed) | ✅ 0.27.0 → re-enabled 0.55.0 → **rebuilt as Domain/FileServe 0.90.0** (the ExternalFiles domain and the `aiya/file-source` package were deleted outright; see the appendix below for what they did). OpenList is now the `aiya/openlist` **request exit** package (`Client` + `Gateway`, WordPress-free, rows as plain arrays) behind `Modules/OpenListModule`; the domain owns the vocabulary (`Entry`/`Failure`/`Adapter`), the adapter registry, one JSON post meta (`aiya_core_fileserve`), the credit pricing and `GET|POST /content/{id}/downloads` for every public content type. The `[oplist_cli]` shortcode meta-persist layer is not migrated (template-parts plan) |
| `inc/func-notify.php` (site notice dispatcher) | ✅ 0.23.0: Domain/Notification — custom table `wp_aiya_notifications`; admin screen (create + list + delete + retention); daily WP-Cron cleanup; read state stays client-side. 0.46.0 added the interaction action system (ten kinds, actor/object columns) on the same table |
| `inc/func-payment.php` (Afdian integration + redemption codes) | ✅ 0.24.0/0.25.0 as the 0.50.0 tier-model rewrite (no legacy inheritance): `wp_aiya_payment_orders` is a pure payment log; membership rights live in the `wp_aiya_memberships` entitlement queue with per-cycle credit grants; redeem codes moved to `wp_aiya_redeem_codes` (0.54.0) and can activate tiers by Afdian order number (0.61.0); the retired `sponsor_expiration`-family meta keys are gone. Live since the 0.55.0 re-enable |
| `plugins/sponsor-order-compat` (Epay gateway) | ✅ 0.88.0 package split: the provider protocol (signing, submit params, callback shapes) now lives in the WordPress-free packages `packages/payment-epay` and `packages/payment-afdian`, with the core adapters owning settings, notify URLs, WP_Error and copy; the checkout also writes a `pending` order row the push settles. Original port: ✅ 0.25.0: `GET aiya/sponsorship/v1/epay/callback` with legacy-algorithm signature verification; cashier submit built by `POST /sponsorship/orders` returning a signed gateway URL; days come from the plan key carried in signed params (amount matching abolished); legacy template pages retire with the front end |
| `inc/lib/Afdian_API.php`, `inc/lib/Epay_Core.php` | ✅ 0.88.0: rebuilt as the WordPress-free packages `packages/payment-afdian` / `packages/payment-epay` — public behavior preserved, code not ported as-is (see the row above) |
| `inc/func-media.php` | Empty placeholder file (5 lines, no code) — dropped (audit 2026-09-08) |
| `inc/func-plyr-player.php` (Plyr player shortcodes) | Player shortcodes ride the template-parts plan (same batch as the shortcode inserter): the API exposes structured part data, Astro renders and loads the player; the legacy front-end player retires |
| `plugins/patch-flow-hub-post` | Retired with the legacy front end; its own-table columns (`like_count` / `diss_count` / `comments_num`) stay in protocol scope as stored data — `diss_count` (downvote) has no counterpart in the new system and is dead data. The payment table was renamed to `wp_aiya_payment_orders` (0.56.0) |
| `plugins/multi-domain` | Dropped (2026-09-08): meaningless with a separate front-end app |
| `plugins/classic-editor-modify` | Dropped (2026-09-08) |
| theme registration and templates | Remain in legacy theme, then retire |
| widget framework | Retire with legacy front end |

Field-group consumers in the legacy theme (for parity tracking; group keys renamed `aya_box_{id}` → `aiya_core_{id}` in 0.31.0): `oplist_client` post box (OpenList client fields; its key `aiya_core_oplist_client` and the `pan_links` repeater key `aiya_core_pan_links` are dead data since 0.90.0 — see the appendix), `post_seo` post box (✅ retired 0.72.0 — article-level SEO keywords are dead data by decision), `post_automatic` post box (action checkboxes consumed by basic-automatic; ✅ 0.37.0 rebuilt as `Domain/Content/TypographyModule` + `packages/typesetting` — refresh date, tag matching, HTML cleanup, Chinese typesetting pass on the post screen; the pinyin/XDE slug half shipped with SlugModule 0.6.0), `tips` term fields (per-field term meta keys).

## Delivery sequence

1. Exercise the settings foundation with a small real settings page.
2. Add metadata registries for post, term and user editing screens.
3. Complete field validation, conditional visibility and nested repeater behavior.
4. Add activation, schema-version and migration runners.
5. ✅ Media processing moved behind the `aiya/image-processor` package seam (0.9.0).
6. Move one content domain at a time. The Discussion domain is rebuilt from the legacy Issue prototype — thread-shaped custom tables (type/status workflow, flat replies, `post_id` reverse binding as per-post tickets), renamed to the new contract (2026-09-08 decision, B2 restart; do not port the old implementation). The resource domain maps onto the existing resource CPT (its metabox redesign comes first). The Tweet domain is cancelled (see disposition).
7. Design the new REST representation after domain use cases stabilize.
8. Clean release (0.80.0): the install path is five pure CREATE migrations — no upgrade steps, no data conversions, no legacy compatibility readers.

## Appendix: the retired external-files implementation (2026-09-21, 0.90.0)

`Domain/ExternalFiles` + `Modules/OpenListModule` + the `aiya/file-source` package + the `Attachment`
contract/presenter/controller were **deleted** when `Domain/FileServe` replaced them. What they did,
kept here as the reference the rewrite was measured against:

- **Wiring**: a `Plugin::EXTERNAL_FILES_ENABLED` flag gated one domain module and one adapter module;
  the listing was `GET /resources/{id}/attachments` over `AttachmentService` + a source registry that
  paired a post box id with a `FileSource\Source` implementation (`openlist`, `share`).
- **Config**: two per-post boxes — `aiya_core_oplist_client` (mode/path/parent/keywords/per_page/
  password/refresh/desc) and `aiya_core_pan_links` (a name/url/code repeater) — plus one settings page
  (`aiya_core_oplist`) holding the OpenList connection and the list knobs. All of it is dead data.
- **Five defects the 0.89.0 rewrite fixed, all found by probing the live OpenList** (and all still
  fixed in FileServe): the `modified` stamp was cast from an ISO string to int and rendered as 1970;
  `get` mode built a link with the file name twice; `dirs` mode always returned an empty list (the
  endpoint answers a bare array while the code read a `content` key); an empty path produced `//name`
  links; and `r` (raw_url) mode resolved nothing in list/search because only `get` carries `raw_url`
  (that mode is gone in FileServe — links are built locally).
- **What replaced each piece**: the port vocabulary became `Domain/FileServe/{Entry,Failure,Adapter}`;
  the two boxes became one JSON meta (`aiya_core_fileserve`, short-id keys, one `adapter` per group);
  the settings page became `aiya_core_fileserve` (page slug `fileserve`); the listing became
  `GET /content/{id}/downloads` (grouped lists, no links) with `POST` on the same route claiming a row
  through the credit ledger. GoFile joined the same domain in 0.91.0 as an additional read-only backend
  (`aiya/gofile-api` + `Adapters/GofileAdapter`), which is new work rather than a legacy migration.
