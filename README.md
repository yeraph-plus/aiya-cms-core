# AIYA Core

Headless-first WordPress plugin for AIYA CMS (WP 6.4+, developed and
running against WP 7.1, runtime PHP 8.5, `Requires PHP: 8.5`): the
admin half of a decoupled site — settings, content domains, media
pipeline, community, notifications and a versioned REST contract
(`aiya/core/v1`) consumed by the Astro front end (`front-station/`).
No Gutenberg, no React in the admin; native admin styles only.

## Layout

- `src/` — plugin code (runtime, Settings framework, Admin surfaces,
  Domain modules, Api contract/presenter/rest layers); autoload
  `Aiya\Core\` plus `Aiya\Infra\` from `packages/`
- `packages/` — WordPress-free infrastructure packages, eight of them:
  `aiya/image-processor`, `aiya/slug-toolkit`, `aiya/typesetting`,
  `aiya/opencc-convert` (unwired by decision), `aiya/openlist`,
  `aiya/gofile-api`, `aiya/payment-epay`, `aiya/payment-afdian` —
  `packages/README.md` is the authoritative table. They are shipped
  in-tree and NOT composer-installed: the plugin
  autoloader reads each package's own composer.json for its PSR-4
  prefix, and a package's third-party dependencies are declared in the
  root composer.json
- `themes/aiya-headless/` — the companion shell theme; sync source for
  its runtime location `wp-content/themes/aiya-headless/` (see its README)
- `assets/` — admin CSS/JS (Backbone + jQuery UI + code editor)
- `languages/` — zh_CN translations (`.mo` built, see the i18n skill)
- `tests/Unit/` — PHPUnit suite (WP shim bootstrap, no install needed)
- `docs/` — ROADMAP (milestone log), ARCHITECTURE (module wiring and
  conventions), MIGRATION (legacy disposition)

## Development

```bash
composer install            # deps + phpunit/phpcs/phpstan tooling
composer php:unit           # PHPUnit (Unit suite)
composer php:cs             # WordPressCodingStandard pass
composer php:stan           # PHPStan (level 8, WP stubs)
i18n                        # see .agents/skills/wp-i18n-zh-cn (host python)
```

After activation the plugin runs its five clean-install migrations
(0.80.0 pure CREATE TABLE statements — no upgrade steps, no data
conversions), defaults permalinks to `/%postname%/` when empty and
schedules its crons. See `docs/ARCHITECTURE.md` for module wiring and
`AGENTS.md` (workspace root) for the iteration log.

## Deployment

The site is two hosts: this WordPress backend (content, media, users,
versioned REST) and the Astro SSR application (`front-station/`) that
renders the public site. The `wp-content/themes/aiya-headless/` shell
theme only catches direct hits on the WP host.

### 1. Backend (WordPress + aiya-core)

```bash
docker compose up -d        # wordpress:php8.5-apache + mariadb + phpmyadmin + wpcli
docker compose run --rm wpcli plugin activate aiya-core
```

- `wp-content/` is bind-mounted; deploy by syncing the plugin folder
  (and `themes/aiya-headless/`) into it — no image rebuild needed.
- Schema: five `0.80.0` CREATE migrations run once through the schema
  version runner; a failed migration holds the stored version back and
  retries on the next request. There are no upgrade paths to maintain.
- Production checklist:
  - `WP_DEBUG` / `WP_DEBUG_LOG` **off** (dev-only);
  - behind a CDN/reverse proxy, bridge the real client IP through the
    `aiya_core_client_ip` filter (rate limiting and guest dedup key on
    it — `REMOTE_ADDR` would collapse everyone onto the proxy IP);
  - leave `WP_DEBUG` undefined (webhook logs stay off);
  - `/wp/v2` is gated to logged-in editors automatically; first-party
    namespaces self-announce via `aiya_core_firstparty_rest_namespaces`.

### 2. Frontend (front-station)

```bash
cd front-station
npm install
cp .env.example .env        # AIYA_SITE_URL / AIYA_WP_API_URL / AIYA_API_TIMEOUT_MS /
                            # AIYA_ALLOW_LOCAL_HTTP / AIYA_PROXY_SECRET / AIYA_CLIENT_IP_HEADER
npm run verify              # astro check + vitest + build
npm start                   # node dist/server/entry.mjs (env-file aware)
```

- The `.env` is server-side only and never reaches the browser bundle;
  production takes real environment variables.
- When the backend is unreachable the site serves a 503 gate page with
  `Retry-After` and auto-recovers — no crash loops.

### 3. Contract sync loop

The frontend's zod schemas are enforced against a backend-generated
snapshot:

```bash
docker compose run --rm wpcli aiya contracts snapshot   # from D:\WordPress_Dev
# → copy the output to front-station/src/lib/core/contracts.snapshot.json
cd front-station && npm test                # shape drift turns the suite red
```

The v1 lock (`contracts.snapshot.v1.json`, additive-only) is amended
only by owner decision, recorded in the ROADMAP.

### 4. Shell theme sync

Edit either copy of `aiya-headless` (repo `themes/` or runtime
`wp-content/themes/`) and mirror to the other — the three runtime files
(`style.css` / `functions.php` / `index.php`) must stay byte-identical
(the README exists only in the repo copy).
