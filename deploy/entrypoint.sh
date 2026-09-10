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

# storage:link намеренно не вызывается: публичный диск в проекте не
# используется (поиск по disk('public'), asset('storage/…') и Storage::url
# ничего не находит), документы отдаются через PHP из диска local.
# Контейнер работает от www-data и не может создать ссылку в public/,
# из-за чего каждый запуск писал в журнал ошибку symlink(): Permission denied.

exec "$@"
