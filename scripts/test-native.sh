#!/usr/bin/env bash
#
# The trustworthy local unit-suite entrypoint: copies the plugin tree to
# the container's native disk and runs phpunit there. The Windows bind
# mount truncates directory enumeration, and php-file-iterator silently
# drops the unresolvable files, so a mounted run prints "OK" while
# executing a small fraction of the suite (~90 of ~625 tests). The guard
# below fails any run whose test count drops under three digits.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
# docker compose (a Windows binary) needs a drive-letter path, not the
# MSYS /d/... form pwd prints.
if command -v cygpath >/dev/null 2>&1; then
    ROOT="$(cygpath -m "$ROOT")"
fi

OUTPUT="$(mktemp)"
trap 'rm -f "$OUTPUT"' EXIT

# The wpcli service's entrypoint is wp itself; swap it for a shell.
# MSYS_NO_PATHCONV keeps Git Bash from rewriting /bin/sh into a Windows path.
MSYS_NO_PATHCONV=1 docker compose -f "$ROOT/docker-compose.yml" run --rm --entrypoint /bin/sh wpcli -c '
    rm -rf /tmp/aiya-core-suite
    cp -r /var/www/html/wp-content/plugins/aiya-core /tmp/aiya-core-suite
    cd /tmp/aiya-core-suite
    vendor/bin/phpunit
' | tee "$OUTPUT"

if ! grep -Eq 'OK \([0-9]{3,} tests' "$OUTPUT"; then
    echo "FAIL: test count is suspiciously low — the suite likely ran on a" >&2
    echo "truncated enumeration. Re-run on a native filesystem (this script)." >&2
    exit 1
fi
