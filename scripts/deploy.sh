#!/bin/sh
# Deploy on the FIBES server. This copy refuses to run unless you are on that
# server and you set FIBES_DEPLOY_EXECUTE=1. Tests set FIBES_DEPLOY_SIMULATE=1
# and never contact a host.
set -eu

tag="${1:-}"
state="${FIBES_DEPLOY_STATE_DIR:-/var/lib/fibes-deploy}"

if [ -z "$tag" ]; then
    echo "Usage: scripts/deploy.sh <image-tag>" >&2
    exit 1
fi

rollback() {
    previous="$(cat "$state/previous-tag" 2>/dev/null || true)"
    echo "Health check failed. Rolling back to ${previous:-none}." >&2
    if [ -n "$previous" ]; then
        printf '%s\n' "$previous" > "$state/current-tag"
    else
        rm -f "$state/current-tag"
    fi
    exit 1
}

if [ "${FIBES_DEPLOY_SIMULATE:-}" = "1" ]; then
    mkdir -p "$state"
    previous="$(cat "$state/current-tag" 2>/dev/null || true)"
    printf '%s\n' "$previous" > "$state/previous-tag"
    printf '%s\n' "$tag" > "$state/current-tag"
    url="${FIBES_DEPLOY_HEALTH_URL:-}"
    if [ -n "$url" ]; then
        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$url" || echo 000)"
        if [ "$code" != "200" ]; then
            rollback
        fi
    fi
    echo "Simulated deploy of $tag"
    exit 0
fi

if [ "${FIBES_DEPLOY_EXECUTE:-}" != "1" ]; then
    echo "Refusing to deploy. Nothing was contacted." >&2
    echo "On the FIBES server, read docs/DEPLOY.md and set FIBES_DEPLOY_EXECUTE=1." >&2
    exit 1
fi

mkdir -p "$state"
previous="$(cat "$state/current-tag" 2>/dev/null || true)"
printf '%s\n' "$previous" > "$state/previous-tag"
printf '%s\n' "$tag" > "$state/current-tag"

root="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
cd "$root"
docker compose -p fibes-prod -f docker-compose.prod.yml up -d --build

url="${FIBES_DEPLOY_HEALTH_URL:-http://127.0.0.1/health}"
code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$url" || echo 000)"
if [ "$code" != "200" ]; then
    if [ -n "$previous" ]; then
        printf '%s\n' "$previous" > "$state/current-tag"
    fi
    echo "Health check returned $code. Previous tag restored in $state/current-tag. Run scripts/rollback.sh on this server." >&2
    exit 1
fi

echo "Deployed $tag"
