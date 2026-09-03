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
| TinyMCE and shortcode manager | Optional ClassicEditor module |
| REST helper | Do not migrate; design new versioned API |
| AJAX helper | Replace per admin use case |
| image manager | Infrastructure/Media plus Content cover ownership |
| visitor counter | Domain/Engagement |
| theme registration and templates | Remain in legacy theme, then retire |
| widget framework | Retire with legacy front end |

## Delivery sequence

1. Exercise the settings foundation with a small real settings page.
2. Add metadata registries for post, term and user editing screens.
3. Complete field validation, conditional visibility and nested repeater behavior.
4. Add activation, schema-version and migration runners.
5. Move media processing behind an Infrastructure/Media seam.
6. Move one content domain at a time, starting with Issue or Tweet.
7. Design the new REST representation after domain use cases stabilize.
8. Add compatibility readers only where legacy data must remain readable.

