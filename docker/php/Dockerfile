FROM php:8.5-fpm-alpine

# Runtime libraries. icu-data-full: Alpine ships English-only ICU data, the app needs DE/FR/IT.
RUN apk add --no-cache git unzip icu-libs icu-data-full libpq libzip

# Extensions are compiled against temporary build deps, removed afterwards.
# OPcache is built into PHP 8.5, so it is not installed here.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpq-dev libzip-dev linux-headers \
    && docker-php-ext-install intl pdo_pgsql zip pcntl \
    && pecl install xdebug-3.5.3 \
    && docker-php-ext-enable xdebug \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
