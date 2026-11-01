#!/bin/sh
set -eu

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

if [ -z "${APP_KEY:-}" ]; then
    APP_KEY="$(php artisan key:generate --show)"
    export APP_KEY
    if [ -f .env ]; then
        sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env
    fi
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
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction
chown -R www-data:www-data storage bootstrap/cache || true

exec "$@"
