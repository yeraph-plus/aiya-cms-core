# AIYA Headless Shell Theme

The companion classic shell theme of AIYA CMS. WordPress runs as the
headless data backend and the public frontend is the Astro application
(`front-station`); this theme exists only so direct hits on the WordPress
host render a harmless, readable fallback document: post lists, archives,
search, 404, singular posts/pages/attachments, draft/pending/scheduled
previews and existing comments.

The theme is final — no further iteration is planned.

## Design rules

- Classic theme, deliberately minimal: declares no theme supports, boots
  no framework, ships no translation domain of its own — all copy uses
  core's default text domain (stock WordPress wording, translated by the
  site's core language pack).
- Reads none of the plugin's own data (custom fields, custom tables).
  Its one guarded plugin-awareness rides core APIs only, keyed on the
  `AIYA_CORE_VERSION` constant: the `resource` CPT joins the post-only
  listing queries (home, date/author archives) and the singular meta
  line lists the taxonomies the plugin registers (the resource family
  plus `page_category`). Search and term archives stay on native
  behavior — search runs post_type "any" and term archives scope
  themselves to the post types carrying the taxonomy. With the plugin
  inactive the theme renders identically.
- UI borrows the admin stylesheets (`common`, `forms`, `buttons`);
  `style.css` carries incremental overrides only. Singular routes
  collapse the shell onto a centered reading column — the theme has no
  widget support and reserves no side area.
- List page titles: the home page carries the site title as the brand
  row's `h1`; archive titles strip the core title wrapper span and
  render as plain text.
- Comments are display-only: existing comments render for reading, no
  submission form and no reply links (`comment_reply_link` filtered to
  nothing) — the public pages give bots nothing to post through.
- Logged-in users get the admin bar (core's own behavior; the theme
  neither enables nor disables it).
- robots double-guard: `robots_txt` full-site Disallow plus `wp_robots`
  noindex/nofollow, unconditionally ignoring `blog_public` — the WP
  host is a backend, not a search-index surface.

## Files

| File | Role |
|---|---|
| `style.css` | Theme header + all overrides (admin min-width release, shell layout, singular reading column, pagination/comments/content typography) |
| `functions.php` | robots guards, admin stylesheet enqueue, resource query injection (`pre_get_posts`, `AIYA_CORE_VERSION` guard), list page title helper |
| `header.php` / `footer.php` | Document shell: head (manual title), brand row (site icon + site name), footer |
| `index.php` | All-purpose list template: home/archives/search/404/empty state (one card per entry + pagination) |
| `singular.php` | Generic singular template: post/page/attachment/preview (the_content + wp_link_pages + custom-taxonomy meta + comments) |
| `comments.php` | Comment list + pagination (display-only, no form) |

## Versioning

Versioned with the aiya-core repository: `themes/aiya-headless/` is the
sync source, `wp-content/themes/aiya-headless/` is the runtime copy —
the two must stay byte-identical. A change may land on either side and
must be synced back to the other before finishing.
