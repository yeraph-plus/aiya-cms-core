# Local runtime integration

Run from the aiya-core repository with Node >=24, Docker Compose and the
workspace's local WordPress already running. This suite uses real WordPress
routing and MariaDB, separately from PHPUnit's WP shims:

```powershell
node tests/Integration/http.mjs
```

`AIYA_TEST_WP_URL` defaults to `http://localhost:8000`. Only loopback sites
are accepted. Each run creates an administrator, a subscriber and two
restricted posts with approved and held comments under a random
`aiya_it_<12 hex digits>` prefix. `finally` deletes these fixtures, tokens
and activity rows even after an assertion fails. No site settings change.
Fixture creation suppresses mail/external HTTP in its WP-CLI process and
avoids publication hooks. Run on the disposable local test site.

Coverage: pretty/plain REST routes, GET/POST `rest_route` overrides,
privileged bearer isolation, real SQL DELETE/UPDATE/INSERT failures,
password preservation, warm-token-cache revocation, restricted comment
pagination/counts/moderation and guest/non-member denial. SQL failures are
injected by a process-local query filter scoped to the fixture operation.

To include the sibling front-station's real login, SSR, comment proxy and
logout checks, first start that frontend with its normal local environment
(WP API origin must match WP siteurl), then run:

```powershell
$env:AIYA_TEST_FRONT_URL='http://localhost:4399'
node tests/Integration/http.mjs
```

This optional orchestration passes disposable credentials and public
fixture metadata to `front-station/tests/integration/comments.mjs` on stdin.
Bearer tokens are neither printed nor passed to that script. These are
HTTP integration tests; browser hydration and UI interaction require
separate browser acceptance.

For manual browser acceptance, run `node tests/Integration/http.mjs --hold`
in an interactive terminal. Its `AIYA_BROWSER_FIXTURE` line contains only
disposable credentials and fixture metadata. Log in through the frontend,
open each fixture post, and click to another comment page; check that
comments load and the held marker is absent. Guests must not see the
restricted comments. Press Enter (or Ctrl+C) in the runner to clean up.
Non-interactive `--hold` is rejected before fixtures are created.

After an externally killed process, clean only its printed prefix:

```powershell
docker compose run --rm -T wpcli eval-file /var/www/html/wp-content/plugins/aiya-core/tests/Integration/runtime.php cleanup aiya_it_<12 hex digits>
```
