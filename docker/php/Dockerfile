# syntax=docker/dockerfile:1.7

# =============================================================================
# Imagen PHP para desarrollo local y para staging en AWS (ADR-0008).
# Laravel Cloud NO usa este Dockerfile: allí el runtime es gestionado y el
# despliegue se define con build/deploy commands (ver docs/06).
# =============================================================================
ARG PHP_VERSION=8.5
ARG BASE_IMAGE=base
FROM php:${PHP_VERSION}-fpm-alpine AS base

RUN apk add --no-cache \
        icu-dev \
        libxml2-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
        libpng-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        linux-headers \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        bcmath \
        gd \
        intl \
        pcntl \
        pdo_pgsql \
        pgsql \
        soap \
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

# -----------------------------------------------------------------------------
# Assets: compila los assets de Vite (CSS/JS) en una imagen Node.
# Se copian al stage `staging` para que la imagen final sea autocontenida y no
# dependa de `npm` en la instancia (ADR-0008, opcion E).
# -----------------------------------------------------------------------------
FROM node:24-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY resources ./resources
COPY vite.config.ts tsconfig.json auto-imports.d.ts components.d.ts ./
COPY public ./public

RUN npm run build

# -----------------------------------------------------------------------------
# Staging: imagen inmutable para la instancia EC2 (ADR-0008).
#
# Diferencias con `prod`:
# - La configuración NO se cachea durante el build: las variables llegan al
#   contenedor en tiempo de ejecución y el entrypoint ejecuta `artisan optimize`.
# - El entrypoint espera a Neon y prepara el cache antes de lanzar el proceso.
# - PHP-FPM tiene un pool reducido (t3.micro con 1 GB de RAM).
#
# La base (PHP + extensiones) puede venir de una imagen pre-construida en GHCR
# (`PHP_BASE_IMAGE=ghcr.io/.../autotributo-php-base:8.5`) para no compilar en
# la instancia; por defecto usa la etapa `base` local (útil en desarrollo).
# -----------------------------------------------------------------------------
FROM ${BASE_IMAGE} AS staging

COPY docker/php/php.staging.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/fpm-pool.staging.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/entrypoint.staging.sh /usr/local/bin/entrypoint.staging
RUN chmod 755 /usr/local/bin/entrypoint.staging

COPY --chown=app:app composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY --chown=app:app . .
# Assets compilados en el stage `assets` (Vite). Se copian despues del codigo
# para que no queden ocultos por un `public/build` stale del contexto.
COPY --from=assets --chown=app:app /app/public/build ./public/build
# El contexto puede traer bootstrap/cache de desarrollo (ver .dockerignore).
# Se regenera el manifiesto de paquetes dentro de la imagen sin caché de config.
RUN rm -f bootstrap/cache/*.php \
    && composer dump-autoload --optimize --no-dev --no-scripts --no-interaction \
    && php artisan package:discover --ansi \
    && chown app:app bootstrap/cache/*.php

USER app
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint.staging"]
CMD ["php-fpm"]
