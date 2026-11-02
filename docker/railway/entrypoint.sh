#!/bin/sh
set -eu
cd /var/www/html
export PORT="${PORT:-8080}"
sed -i 's/^Listen 80$//' /etc/apache2/ports.conf
# A mounted volume starts empty and root-owned: recreate the storage tree.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public storage/app/private bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

if [ "${1:-}" != "apache2-foreground" ]; then
    exec su -s /bin/sh www-data -c "exec $*"
fi

if [ -z "${APP_KEY:-}" ] || [ -z "${DB_PASSWORD:-}" ]; then
    echo "APP_KEY and DB_PASSWORD must be set." >&2
    exit 1
fi

if [ "${FIBES_RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "Waiting for PostgreSQL..."
    i=0
    until php -r 'try { new PDO("pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
        i=$((i + 1)); [ "$i" -gt 40 ] && { echo "PostgreSQL did not become ready." >&2; exit 1; }
        sleep 2
    done
    # Additive only. Seeders are idempotent; the admin is created once from ADMIN_EMAIL / ADMIN_PASSWORD.
    su -s /bin/sh www-data -c "php artisan migrate --force --no-interaction && php artisan db:seed --force --no-interaction"
fi

su -s /bin/sh www-data -c "php artisan config:cache && php artisan route:cache && php artisan view:cache" || true
echo "Apache MPM modules enabled: $(ls /etc/apache2/mods-enabled | grep -i mpm | tr '\n' ' ')"
exec "$@"
