# syntax=docker/dockerfile:1

# ---------- 1. Сборка фронтенда ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
RUN npm run build

# ---------- 2. PHP-зависимости ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
      --no-dev --no-scripts --no-autoloader \
      --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---------- 3. Рабочий образ PHP-FPM ----------
FROM php:8.4-fpm-alpine AS runtime

RUN apk add --no-cache \
        icu-libs libzip libpng libjpeg-turbo freetype oniguruma libpq \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev \
        freetype-dev oniguruma-dev postgresql-dev sqlite-dev linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pgsql pdo_sqlite mbstring zip gd intl bcmath exif pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY deploy/php/php.ini  /usr/local/etc/php/conf.d/99-app.ini
COPY deploy/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html

COPY --from=vendor /app              /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY deploy/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions \
                storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------- 4. Nginx со статикой приложения ----------
FROM nginx:1.27-alpine AS web
COPY deploy/nginx/default.conf       /etc/nginx/conf.d/default.conf
COPY --from=vendor /app/public       /var/www/html/public
COPY --from=assets /app/public/build /var/www/html/public/build
