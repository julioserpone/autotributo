# syntax=docker/dockerfile:1.7

# =============================================================================
# Imagen PHP para desarrollo local y (opcional) paridad de build.
# Laravel Cloud NO usa este Dockerfile: allí el runtime es gestionado y el
# despliegue se define con build/deploy commands (ver docs/06).
# =============================================================================
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-fpm-alpine AS base

RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
        libpng-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        linux-headers \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        sockets \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Usuario no-root alineado con el UID/GID del host (evita archivos root en bind mounts).
ARG UID=1000
ARG GID=1000
RUN addgroup -g "${GID}" app 2>/dev/null || addgroup -S app \
    && adduser -D -u "${UID}" -G app -h /home/app -s /sbin/nologin app

WORKDIR /var/www/html

# -----------------------------------------------------------------------------
# Dev: código montado como volumen, OPcache desactivado, Xdebug opcional.
# -----------------------------------------------------------------------------
FROM base AS dev

ARG INSTALL_XDEBUG=false
RUN if [ "$INSTALL_XDEBUG" = "true" ]; then \
        apk add --no-cache --virtual .xdebug-build $PHPIZE_DEPS linux-headers \
        && pecl install xdebug \
        && docker-php-ext-enable xdebug \
        && apk del .xdebug-build; \
    fi

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf

USER app

EXPOSE 9000
CMD ["php-fpm"]

# -----------------------------------------------------------------------------
# Prod: imagen inmutable (solo para smoke tests locales / self-hosting futuro).
# En Laravel Cloud el build se hace con los comandos documentados.
# -----------------------------------------------------------------------------
FROM base AS prod

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf

COPY --chown=app:app composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY --chown=app:app . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache

USER app
EXPOSE 9000
CMD ["php-fpm"]
