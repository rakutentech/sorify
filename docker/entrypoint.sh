#!/usr/bin/env bash
set -euo pipefail

cd /app

mkdir -p \
    storage/app/public \
    storage/app/tmp \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache || true

if [ ! -L public/storage ]; then
    php artisan storage:link || true
fi

SKIP_BOOTSTRAP=0
case "$*" in
    *"artisan key:generate"*) SKIP_BOOTSTRAP=1 ;;
esac

if [ "$SKIP_BOOTSTRAP" -eq 0 ]; then
    if [ -z "${APP_KEY:-}" ]; then
        echo "ERROR: APP_KEY is not set. Run:" >&2
        echo "  docker compose run --rm --no-deps app php artisan key:generate --show" >&2
        echo "and paste the value into your .env as APP_KEY=..." >&2
        exit 1
    fi

    php artisan config:clear >/dev/null 2>&1 || true
    php artisan config:cache
    php artisan route:cache || true
    php artisan view:cache || true
fi

exec "$@"
