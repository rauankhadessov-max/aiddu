#!/bin/sh
set -e

# Ждём готовности PostgreSQL
until php -r 'new PDO(
    "pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"),
    getenv("DB_USERNAME"), getenv("DB_PASSWORD")
);' 2>/dev/null; do
    echo "Ожидание PostgreSQL..."
    sleep 2
done

php artisan migrate --force --no-interaction

# Кэши собираем после миграций, чтобы конфиг подхватил актуальный .env
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link || true

exec "$@"
