# AIYA Core

Headless-first WordPress plugin for AIYA CMS (WP 6.4+, developed and
running against WP 7.1 / PHP 8.2+, runtime on PHP 8.4): the
admin half of a decoupled site — settings, content domains, media
pipeline, community, notifications and a versioned REST contract
(`aiya/core/v1`) consumed by the Astro front end. No Gutenberg, no
React in the admin; native admin styles only.

## Layout

- `src/` — plugin code (runtime, Settings framework, Admin surfaces,
  Domain modules, Api contract/presenter/rest layers); autoload
  `Aiya\Core\` plus `Aiya\Infra\` from `packages/`
- `packages/` — WordPress-free infrastructure packages
  (`aiya/image-processor`, `aiya/slug-toolkit`, `aiya/typesetting`)
- `assets/` — admin CSS/JS (Backbone + jQuery UI + code editor)
- `languages/` — zh_CN translations (`.mo` built, see docs)
- `tests/Unit/` — PHPUnit suite (WP shim bootstrap, no install needed)
- `docs/` — ROADMAP (milestone log), ARCHITECTURE (module wiring),
  MIGRATION (legacy disposition)

## Development

```bash
composer install            # deps + phpunit/phpcs/phpstan tooling
composer test:unit          # PHPUnit (Unit suite)
composer php:cs             # WordPressCodingStandard pass
composer php:stan           # PHPStan (level 8, WP stubs)
npm-less i18n               # see .agents/skills/wp-i18n-zh-cn
```

After activation the plugin seeds its schema (migration runner records
from 0.0.0), defaults permalinks to `/%postname%/` when empty and
schedules its crons. See `docs/ARCHITECTURE.md` for module wiring and
`AGENTS.md` (workspace root) for the iteration log.
