#!/bin/sh
# Render start: map DATABASE_URL if given, check secrets, wait for Postgres,
# run the normal production boot steps, then hand over to supervisord.
set -eu
cd "${FIBES_APP_DIR:-/var/www/html}"
export PORT="${PORT:-10000}"
# Render sets RENDER_EXTERNAL_URL (https://<name>.onrender.com).
# Railway sets RAILWAY_PUBLIC_DOMAIN (the hostname only). Use either when APP_URL is not set.
if [ -z "${APP_URL:-}" ] && [ -n "${RENDER_EXTERNAL_URL:-}" ]; then export APP_URL="$RENDER_EXTERNAL_URL"; fi
if [ -z "${APP_URL:-}" ] && [ -n "${RAILWAY_PUBLIC_DOMAIN:-}" ]; then export APP_URL="https://${RAILWAY_PUBLIC_DOMAIN}"; fi

# Railway may inject the private Postgres URL under this name.
if [ -z "${DATABASE_URL:-}" ] && [ -n "${DATABASE_PRIVATE_URL:-}" ]; then export DATABASE_URL="$DATABASE_PRIVATE_URL"; fi

# Render and Railway give one connection string. Split it into DB_* if DB_HOST is not set.
if [ -n "${DATABASE_URL:-}" ] && [ -z "${DB_HOST:-}" ]; then
    eval "$(php -r '
        $u = parse_url(getenv("DATABASE_URL"));
        $v = ["DB_HOST" => $u["host"] ?? "", "DB_PORT" => (string) ($u["port"] ?? 5432),
              "DB_DATABASE" => ltrim($u["path"] ?? "", "/"), "DB_USERNAME" => urldecode($u["user"] ?? ""),
              "DB_PASSWORD" => urldecode($u["pass"] ?? "")];
        foreach ($v as $k => $x) { echo "export $k=" . escapeshellarg($x) . "\n"; }
    ')"
fi
export DB_PORT="${DB_PORT:-5432}"

if [ -z "${APP_KEY:-}" ] || [ -z "${DB_HOST:-}" ] || [ -z "${DB_PASSWORD:-}" ] || [ -z "${ADMIN_PASSWORD:-}" ]; then
    echo "APP_KEY, ADMIN_PASSWORD, and the database (DATABASE_URL or DB_HOST/DB_PASSWORD...) must be set." >&2
    exit 1
fi
case "$DB_PASSWORD" in
    change-me|secret|password) echo "Refusing a placeholder DB_PASSWORD." >&2; exit 1 ;;
esac

echo "Waiting for PostgreSQL..."
i=0
until php -r 'try { new PDO("pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
    i=$((i + 1))
    if [ "$i" -gt 60 ]; then echo "PostgreSQL did not become ready." >&2; exit 1; fi
    sleep 2
done

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache
php artisan package:discover --ansi
php artisan storage:link --force || true
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache

envsubst '${PORT}' < /etc/nginx/fibes.conf.template > /etc/nginx/conf.d/fibes.conf
echo "Starting FIBES on port $PORT"
exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
