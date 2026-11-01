#!/bin/sh
# Nightly backup. PostgreSQL uses pg_dump. Tests and SQLite use the encrypted artisan backup.
# The dump is encrypted. Copy it off the server with FIBES_BACKUP_DESTINATION.
set -eu

if [ "${APP_ENV:-}" = "production" ] && [ "${FIBES_BACKUP_SCRATCH:-}" = "${DB_DATABASE:-}" ]; then
    echo "Refusing to use the live database as the restore target." >&2
    exit 1
fi

php artisan backups:run
