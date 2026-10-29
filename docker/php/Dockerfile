# syntax=docker/dockerfile:1.7
# Laravel API image. One image runs three roles: php-fpm (app), queue worker and scheduler.

FROM php:8.4-fpm-alpine AS base
WORKDIR /var/www/html

RUN apk add --no-cache icu-libs libzip libpng libjpeg-turbo freetype fcgi tzdata \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev linux-headers \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip gd bcmath opcache pcntl \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del .build-deps \
 && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# --- Development: code is bind-mounted, opcache revalidates on every request.
FROM base AS dev
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1

# --- Production: code and vendor baked in, caches built at start.
FROM base AS prod
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
COPY docker/php/www.prod.conf /usr/local/etc/php-fpm.d/zzz-prod.conf

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY backend/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
 && php artisan package:discover --ansi \
 && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

USER www-data
