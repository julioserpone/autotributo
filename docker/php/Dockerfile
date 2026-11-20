# syntax=docker/dockerfile:1.7

# =============================================================================
# Imagen PHP para desarrollo local y para staging en AWS (ADR-0008).
# Laravel Cloud NO usa este Dockerfile: allí el runtime es gestionado y el
# despliegue se define con build/deploy commands (ver docs/06).
#
# Stage `toolchain` = PHP + extensiones + Composer + Node + git + rsync.
# Instalar todo eso es lo caro (~5-10 min), así que se construye en CI y se
# publica en GHCR como `autotributo-php-base:8.5`. Las imágenes de staging
# parten de ahí, así que la instancia nunca compila extensiones ni instala
# paquetes; actualizar el toolchain es reconstruir esta imagen (ADR-0008).
# =============================================================================

ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-fpm-alpine AS toolchain

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
    && curl -fsSL https://github.com/phpredis/phpredis/archive/refs/tags/6.3.0.tar.gz -o /tmp/redis.tar.gz \
    && mkdir -p /tmp/redis-src && tar -xzf /tmp/redis.tar.gz -C /tmp/redis-src --strip-components=1 \
    && cd /tmp/redis-src && phpize && ./configure && make && make install \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/redis.tar.gz /tmp/redis-src \
    && apk del $PHPIZE_DEPS

# Herramientas de despliegue: git alinea el código con el commit de la imagen y
# rsync lo sincroniza sobre APP_DIR sin tocar storage/. El índice de apk queda
# cacheado en la imagen (imagen-refresh.sh puede hacer `apk upgrade` allí).
RUN apk add --no-cache \
        bash \
        curl \
        git \
        rsync \
        tzdata \
        unzip \
    && mkdir -p /var/cache/apk

# Node: solo el binario (npm viene incluido). Sirve para `npm ci && npm run build`
# en caliente sin instalar nada. Alpine usa musl, así que no vale el paquete
# glibc de nodejs.org: los builds musl los publica unofficial-builds (mismo
# proyecto de Node, CI oficial). Fijado a una versión que cumple el `engines` de
# la cadena Vite/Nuxt UI/Rolldown (`>=20.19.0`, que nodejs.org no cubre en musl).
ENV NODE_VERSION=24.22.3

RUN case "$(uname -m)" in \
        x86_64) node_arch=x64-musl ;; \
        aarch64) node_arch=arm64-musl ;; \
        *) echo "arquitectura no soportada: $(uname -m)" >&2; exit 1 ;; \
    esac \
    && file="node-v${NODE_VERSION}-linux-${node_arch}.tar.xz" \
    && curl -fsSL "https://unofficial-builds.nodejs.org/download/release/v${NODE_VERSION}/${file}" \
        -o /tmp/node.tar.xz \
        || { echo "Node ${NODE_VERSION} (${node_arch}) no disponible para alpine" >&2; exit 1; } \
    && tar -xJf /tmp/node.tar.xz -C /usr/local --strip-components=1 \
        --exclude CHANGELOG.md --exclude LICENSE --exclude README.md \
    && rm -f /tmp/node.tar.xz \
    && node --version \
    && npm --version

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
FROM toolchain AS dev

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
FROM toolchain AS prod

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
# Base de staging (target por defecto): toolchain + cliente de PostgreSQL para
# los respaldos. Es lo que publica `build-php-base.yml` en GHCR y el FROM de las
# imágenes de abajo: la capa cara (extensiones, Node, Composer, git) se
# reconstruye solo por un cambio de este Dockerfile, no por cada commit. La
# publica `build-php-base.yml` y la usan las imágenes de staging.
# -----------------------------------------------------------------------------
FROM toolchain AS base

# Versión mayor alineada con Neon para `pg_dump` en el respaldo de staging.
RUN apk add --no-cache postgresql18-client

USER app
EXPOSE 9000

# -----------------------------------------------------------------------------
# Runtime de la instancia (target `app`): el compose de staging la construye en
# el host. Solo configuración y el árbol de código del repo; NO ejecuta
# Composer, npm ni `artisan` en el build. Si falta `vendor/` o `public/build`,
# `scripts/staging/build-app.sh` los genera en caliente dentro del contenedor y
# `deploy.sh` los reutiliza mientras no cambien
# `composer.lock`/`package-lock.json`.
# -----------------------------------------------------------------------------
FROM base AS app

# `base` termina como `app` y root no puede escribir en /usr/local/bin con ese
# usuario: el COPY fija permiso y propietario de una vez.
USER root

COPY docker/php/php.staging.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/fpm-pool.staging.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=755 docker/php/entrypoint.staging.sh /usr/local/bin/entrypoint.staging

COPY --chown=app:app . .

USER app
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint.staging"]
CMD ["php-fpm"]

# -----------------------------------------------------------------------------
# Assets: compila los assets de Vite (CSS/JS) en una imagen Node. Solo lo usa la
# imagen completa de GHCR.
# -----------------------------------------------------------------------------
FROM node:24-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY resources ./resources
COPY vite.config.ts tsconfig.json ./
COPY bootstrap ./bootstrap
COPY public ./public

RUN npm run build

# -----------------------------------------------------------------------------
# Imagen completa publicada en GHCR por `build-staging-image.yml`.
#
# Es un snapshot reproducible: código + `vendor/` + `public/build`. `create` la
# descarga y `deploy.sh` la usa para sembrar la instancia nueva. Para los cambios
# posteriores la instancia reutiliza este mismo código y ejecuta Composer y Vite
# en caliente con el toolchain ya instalado (sin compilar PHP ni instalar Node).
# -----------------------------------------------------------------------------
FROM app AS staging

COPY --chown=app:app composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
# El contexto puede traer bootstrap/cache de desarrollo (ver .dockerignore).
RUN rm -f bootstrap/cache/*.php \
    && composer dump-autoload --optimize --no-dev --no-scripts --no-interaction \
    && php artisan package:discover --ansi \
    && chown app:app bootstrap/cache/*.php

# Los assets se copian después del código para que no queden ocultos por un
# `public/build` stale del contexto.
COPY --from=assets --chown=app:app /app/public/build ./public/build
