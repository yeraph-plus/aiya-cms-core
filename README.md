# AIYA Core

Headless-first WordPress plugin foundation for AIYA CMS.

The initial slice provides an explicit plugin runtime, settings schema, field normalization, option and metadata storage adapters, a traditional WordPress administration page, and Backbone-powered media/repeater fields. It uses WordPress-provided admin styles, jQuery UI, Media and Code Editor assets; it does not use Gutenberg or React.

After activation, open **AIYA Core** in the WordPress administration menu. The bundled sample page exercises scalar fields, validation, write-only secrets, media selection, WordPress Code Editor, TinyMCE and a sortable repeater. Its values are isolated in the `aiya_core_sample` option and can be reset from the page.

See `docs/ARCHITECTURE.md` for registration and `docs/MIGRATION.md` for planned ownership.
