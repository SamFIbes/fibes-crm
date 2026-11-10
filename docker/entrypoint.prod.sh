#!/bin/sh
set -eu

cd /var/www/html

if [ "${APP_ENV:-production}" = "production" ] || [ "${APP_ENV:-}" = "staging" ]; then
    if [ -z "${APP_KEY:-}" ] || [ -z "${DB_PASSWORD:-}" ] || [ -z "${ADMIN_PASSWORD:-}" ]; then
        echo "APP_KEY, DB_PASSWORD, and ADMIN_PASSWORD must be set." >&2
        exit 1
    fi

    case "$DB_PASSWORD" in
        change-me|secret|password)
            echo "Refusing a placeholder DB_PASSWORD outside local." >&2
            exit 1
            ;;
    esac
fi

if [ "${FIBES_RUN_MIGRATIONS:-true}" != "true" ]; then
    echo "Skipping migrations in this container."
    exec "$@"
fi

echo "Waiting for PostgreSQL..."
i=0
until php -r 'try { new PDO("pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
    i=$((i + 1))
    if [ "$i" -gt 30 ]; then
        echo "PostgreSQL did not become ready." >&2
        exit 1
    fi
    sleep 1
done

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache
php artisan package:discover --ansi
php artisan storage:link --force || true
php artisan migrate --force --no-interaction
# Seeding re-syncs built-in role permissions, re-promotes the configured admin
# and overwrites reference rows, so it only runs when asked for explicitly.
if [ "${FIBES_RUN_SEED:-}" = "true" ]; then
    php artisan db:seed --force --no-interaction
else
    echo "Database seeding skipped (FIBES_RUN_SEED is not true)."
fi
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
