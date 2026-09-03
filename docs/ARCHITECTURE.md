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

## Direction of dependencies

- Admin code may depend on Settings schema.
- WordPress storage adapters may depend on WordPress functions.
- Schema and normalization must not depend on admin HTML.
- Content domains must not depend on Admin or future HTTP transports.
- Astro and other front ends consume a future versioned API; they never load this framework directly.

## Administrative UI

The UI uses WordPress admin markup and registered WordPress dependencies. Backbone owns dynamic field views. jQuery UI supplies sortable behavior. WordPress Media and Code Editor APIs replace private upload and CDN CodeMirror implementations. No Gutenberg or React package is required.
