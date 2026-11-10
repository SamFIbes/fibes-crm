#!/bin/sh
# Boots php:8.3-apache with docker/railway/apache.conf and public/.htaccess and
# prints "<case>|<status>|<location>" lines for SmokeCheck-style redirects.
# Needs docker. Used by RailwayApacheRedirectTest (skipped without docker).
set -eu
repo=$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d)
name="fibes-apache-test-$$"
trap 'docker rm -f "$name" >/dev/null 2>&1 || true; rm -rf "$work"' EXIT

mkdir -p "$work/public/build"
cp "$repo/public/.htaccess" "$work/public/.htaccess"
printf '<?php echo "app";\n' > "$work/public/index.php"
printf 'ok\n' > "$work/public/build/manifest.json"

docker run -d --name "$name" -p 127.0.0.1::8080 -e PORT=8080 \
    -v "$repo/docker/railway/apache.conf:/etc/apache2/sites-available/000-default.conf:ro" \
    -v "$work/public:/var/www/html/public:ro" \
    php:8.3-apache-bookworm \
    sh -c 'a2enmod rewrite headers remoteip >/dev/null && sed -i "s/^Listen 80$//" /etc/apache2/ports.conf && exec apache2-foreground' >/dev/null

port=$(docker port "$name" 8080/tcp | head -n1 | sed 's/.*://')
i=0
until curl -s -o /dev/null "http://127.0.0.1:$port/"; do
    i=$((i + 1)); [ "$i" -gt 60 ] && { docker logs "$name" >&2; exit 1; }
    sleep 1
done

probe() {
    label="$1"; path="$2"; shift 2
    out=$(curl -s -o /dev/null -w '%{http_code}|%{redirect_url}' -H 'Host: www.fibes.io' "$@" "http://127.0.0.1:$port$path")
    printf '%s|%s\n' "$label" "$out"
}

probe dir-https /build -H 'X-Forwarded-Proto: https'
probe slash-https /storage/ -H 'X-Forwarded-Proto: https'
probe dir-http /build
probe host-port-https /build -H 'Host: www.fibes.io:8080' -H 'X-Forwarded-Proto: https'
probe app-https /login -H 'X-Forwarded-Proto: https'
