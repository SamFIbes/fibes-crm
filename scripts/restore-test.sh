#!/bin/sh
# Monthly restore test. Refuses to load a dump into the live database.
set -eu

target="${FIBES_BACKUP_SCRATCH:-}"
live="${DB_DATABASE:-}"

if [ -z "$target" ] || [ "$target" = "$live" ]; then
    echo "Refusing to restore into the live database." >&2
    exit 1
fi

if [ "${APP_ENV:-}" = "production" ]; then
    echo "Refusing a restore test in production." >&2
    exit 1
fi

php artisan backups:restore-test
