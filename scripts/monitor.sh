#!/bin/sh
# Local checks only. It does not open a session on another machine.
# Set FIBES_HEALTH_URL to curl a health page. Set FIBES_MONITOR_DISK to a path.
set -eu

fail=0

disk="${FIBES_MONITOR_DISK:-/}"
if [ -d "$disk" ]; then
    used="$(df -P "$disk" | awk 'NR==2 {print $5}' | tr -d '%')"
    echo "disk $disk ${used}%"
    if [ "${used:-0}" -ge 90 ]; then
        echo "ALERT disk at ${used}%" >&2
        fail=1
    fi
else
    echo "disk skipped"
fi

url="${FIBES_HEALTH_URL:-}"
if [ -n "$url" ]; then
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$url" || echo 000)"
    echo "health $code"
    if [ "$code" != "200" ]; then
        echo "ALERT health $code" >&2
        fail=1
    fi
else
    echo "health skipped"
fi

echo "postgres skipped unless psql and DB_HOST are both available on this machine"
echo "queue skipped unless redis-cli and REDIS_HOST are both available on this machine"
echo "certificate skipped unless FIBES_TLS_HOST is set and you run this on the server"

if [ -n "${FIBES_TLS_HOST:-}" ]; then
    echo "certificate check is left for the server that holds the name ${FIBES_TLS_HOST}"
fi

exit "$fail"
