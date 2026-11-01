#!/bin/sh
# Put the recorded previous tag back. Simulation only touches FIBES_DEPLOY_STATE_DIR.
set -eu

state="${FIBES_DEPLOY_STATE_DIR:-/var/lib/fibes-deploy}"
previous="$(cat "$state/previous-tag" 2>/dev/null || true)"

if [ -z "$previous" ]; then
    echo "No previous tag is recorded." >&2
    exit 1
fi

if [ "${FIBES_DEPLOY_SIMULATE:-}" = "1" ]; then
    printf '%s\n' "$previous" > "$state/current-tag"
    echo "Simulated rollback to $previous"
    exit 0
fi

echo "Refusing to roll back a live stack from here. Nothing was contacted." >&2
echo "On the FIBES server, read docs/DEPLOY.md." >&2
exit 1
