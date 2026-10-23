# syntax=docker/dockerfile:1
#
# Build order matters: everything below is ordered from "changes almost never"
# to "changes on every commit" so BuildKit can reuse the expensive layers
# (PHP extensions, composer install, frontend build) between deploys.

# Pinned to PHP 8.4.27 (Laravel 13 needs the Pdo\Mysql class, PHP 8.4+).
ARG PHP_VERSION=8.4.27
ARG FRANKENPHP_VERSION=1.13.2
ARG COMPOSER_VERSION=2.9
ARG BUN_VERSION=1.4.2
ARG SUPERCRONIC_VERSION=0.2.49

###########################################
# Build frontend assets with Bun
###########################################

# Assets are platform-independent, so build them on the native builder
# platform. Under --platform=linux/amd64 on Apple Silicon this avoids running
# Bun/Vite through QEMU (where it can spin at 100% CPU for minutes).
FROM --platform=${BUILDPLATFORM} oven/bun:${BUN_VERSION}-alpine AS build

ENV ROOT=/var/www/html \
    BUN_INSTALL_CACHE_DIR=/tmp/bun-cache

WORKDIR ${ROOT}

COPY --link package.json bun.lock ./

# --frozen-lockfile makes the build fail when package.json and bun.lock drift
# apart (instead of silently resolving different versions). The cache mount
# keeps the tarball cache out of the final layer.
RUN --mount=type=cache,target=/tmp/bun-cache \
    bun install --frozen-lockfile

COPY --link . .

RUN bun run build

###########################################
# Rebuild FrankenPHP with brotli support and a smaller binary
###########################################

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-builder-php${PHP_VERSION}-alpine AS upstream

COPY --from=caddy:builder /usr/bin/xcaddy /usr/bin/xcaddy

# Persistent Go caches: a version bump recompiles FrankenPHP in ~1min instead
# of re-downloading the whole module graph and rebuilding Caddy from scratch.
RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    CGO_ENABLED=1 \
    XCADDY_SETCAP=1 \
    XCADDY_GO_BUILD_FLAGS="-ldflags='-w -s' -tags=nobadger,nomysql,nopgx" \
    CGO_CFLAGS=$(php-config --includes) \
    CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs)" \
    xcaddy build \
    --output /usr/local/bin/frankenphp \
    --with github.com/dunglas/frankenphp=./ \
    --with github.com/dunglas/frankenphp/caddy=./caddy/ \
    --with github.com/dunglas/caddy-cbrotli

###########################################

FROM composer:${COMPOSER_VERSION} AS vendor

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-alpine

# Swap in the stripped/cbrotli-enabled binary built above.
COPY --from=upstream /usr/local/bin/frankenphp /usr/local/bin/frankenphp

LABEL maintainer="SMortexa <seyed.me720@gmail.com>"
LABEL org.opencontainers.image.title="Laravel Octane Dockerfile"
LABEL org.opencontainers.image.description="Production-ready Dockerfile for Laravel Octane"
LABEL org.opencontainers.image.source=https://github.com/exaco/laravel-octane-dockerfile
LABEL org.opencontainers.image.licenses=MIT

ARG WWWUSER=1000
ARG WWWGROUP=1000
ARG TZ=UTC
# Re-declared here: a global ARG is only visible in FROM lines otherwise.
ARG SUPERCRONIC_VERSION
ARG APP_ENV=production
ARG ENV_FILE=.env
ARG APP_DIR=/var/www/html
ARG GIT_COMMIT_HASH=unknown
ARG GIT_COMMIT_DATE=unknown

ENV TERM=xterm-color \
    WITH_HORIZON=false \
    WITH_SCHEDULER=false \
    WITH_REVERB=false \
    OCTANE_SERVER=frankenphp \
    USER=octane \
    ROOT=${APP_DIR} \
    COMPOSER_FUND=0 \
    COMPOSER_MAX_PARALLEL_HTTP=24 \
    LANG=C.UTF-8 \
    XDG_CONFIG_HOME=${APP_DIR}/.config \
    XDG_DATA_HOME=${APP_DIR}/.data

# Set late on purpose: APP_ENV differs between builds, and setting it earlier
# would give every layer above it a different cache key.
ENV APP_ENV=${APP_ENV}

WORKDIR ${ROOT}

SHELL ["/bin/sh", "-eou", "pipefail", "-c"]

RUN ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime \
    && echo ${TZ} > /etc/timezone

# Base tooling gets its own layer: bumping an extension below no longer
# re-runs the whole apk transaction, and vice versa.
# `apk upgrade` is intentionally NOT used: it changes this layer whenever the
# Alpine repositories move, which invalidates every layer below it (most
# importantly the ~5 minute install-php-extensions layer). Security updates
# come from bumping the pinned PHP_VERSION / FRANKENPHP_VERSION base images.
# The cache mount keeps the package index + .apk files between builds.
RUN --mount=type=cache,target=/var/cache/apk \
    apk update \
    && apk add \
    bash \
    ca-certificates \
    curl \
    ghostscript \
    git \
    nano \
    ncdu \
    procps \
    supervisor \
    libsodium-dev \
    tzdata \
    unzip \
    wget \
    && rm -rf /tmp/* /var/tmp/*

# Install PHP extensions (dunglas/frankenphp already ships the toolchain).
# MAKEFLAGS lets every extension build use all CPUs available to the builder.
RUN MAKEFLAGS="-j$(nproc)" install-php-extensions \
    bz2 \
    pcntl \
    mbstring \
    bcmath \
    sockets \
    pgsql \
    pdo_pgsql \
    opcache \
    exif \
    pdo_mysql \
    zip \
    intl \
    gd \
    redis \
    memcached \
    igbinary \
    sqlite3 \
    pdo_sqlite \
    && rm -rf /var/cache/apk/* /tmp/* /var/tmp/*

RUN arch="$(apk --print-arch)" \
    && case "$arch" in \
    armhf) _cronic_fname='supercronic-linux-arm' ;; \
    aarch64) _cronic_fname='supercronic-linux-arm64' ;; \
    x86_64) _cronic_fname='supercronic-linux-amd64' ;; \
    x86) _cronic_fname='supercronic-linux-386' ;; \
    *) echo >&2 "error: unsupported architecture: $arch"; exit 1 ;; \
    esac \
    && curl -fsSL "https://github.com/aptible/supercronic/releases/download/v${SUPERCRONIC_VERSION}/${_cronic_fname}" \
    -o /usr/bin/supercronic \
    && chmod +x /usr/bin/supercronic \
    && mkdir -p /etc/supercronic \
    && echo "*/1 * * * * php ${ROOT}/artisan schedule:run --no-interaction" > /etc/supercronic/laravel

RUN addgroup -g ${WWWGROUP} ${USER} \
    && adduser -D -h ${ROOT} -G ${USER} -u ${WWWUSER} -s /bin/sh ${USER}

# Plain `supervisorctl` looks for /etc/supervisord.conf; point it at the
# config copied below so operators do not need -c/-s every time.
# Hardening: drop SUID/SGID bits from the base image here so the scan is cached
# with this layer instead of running again on every application build.
RUN mkdir -p ${ROOT} /var/log/supervisor /var/run/supervisor \
    && chown -R ${USER}:${USER} ${ROOT} /var/log /var/run \
    && ln -sf /etc/supervisor/supervisord.conf /etc/supervisord.conf \
    && { find / -xdev \( -perm -4000 -o -perm -2000 \) -type f -exec chmod a-s {} + 2>/dev/null || true; }

RUN cp ${PHP_INI_DIR}/php.ini-production ${PHP_INI_DIR}/php.ini

USER ${USER}

# Composer tarballs live in a BuildKit cache (not in the image), so changing
# the lockfile re-downloads only what actually changed. uid/gid match
# WWWUSER/WWWGROUP (1000) because the install runs as the app user.
COPY --link --chown=${WWWUSER}:${WWWGROUP} --from=vendor /usr/bin/composer /usr/bin/composer
COPY --link --chown=${WWWUSER}:${WWWGROUP} composer.json composer.lock ./

RUN --mount=type=cache,target=/tmp/composer-cache,uid=1000,gid=1000 \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
    --no-dev \
    --no-interaction \
    --no-autoloader \
    --no-scripts \
    --no-ansi \
    --prefer-dist \
    --no-progress

COPY --link --chown=${WWWUSER}:${WWWGROUP} . .
COPY --link --chown=${WWWUSER}:${WWWGROUP} --from=build ${ROOT}/public public

# Laravel prefers .env.{APP_ENV} over .env. Stale env files copied from the
# build context used to shadow the selected ENV_FILE, which made it look like
# the build arg was ignored. Keep .env as the single baked environment file.
RUN rm -f .env .env.local .env.production .env.staging .env.preview .env.testing
COPY --link --chown=${WWWUSER}:${WWWGROUP} ${ENV_FILE:-.env} .env

RUN mkdir -p \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    storage/framework/testing \
    storage/logs \
    bootstrap/cache \
    /tmp/opcache-file-cache \
    && chmod -R u+rwX storage \
    && rm -f bootstrap/cache/*.php

COPY --link --chown=${WWWUSER}:${WWWGROUP} deployment/supervisord.conf /etc/supervisor/
COPY --link --chown=${WWWUSER}:${WWWGROUP} deployment/octane/FrankenPHP/supervisord.frankenphp.conf /etc/supervisor/conf.d/
COPY --link --chown=${WWWUSER}:${WWWGROUP} deployment/supervisord.*.conf /etc/supervisor/conf.d/
COPY --link --chown=${WWWUSER}:${WWWGROUP} deployment/start-container /usr/local/bin/start-container
COPY --link --chown=${WWWUSER}:${WWWGROUP} deployment/healthcheck /usr/local/bin/healthcheck
COPY --link --chown=${WWWUSER}:${WWWGROUP} deployment/php.ini ${PHP_INI_DIR}/conf.d/99-octane.ini

# FrankenPHP embedded PHP configuration
COPY --link --chown=${WWWUSER}:${WWWGROUP} deployment/php.ini /lib/php.ini

# One autoload dump instead of a second full composer install. The dump runs
# the post-autoload-dump hooks (package discovery, Filament upgrade).
RUN composer dump-autoload \
    --classmap-authoritative \
    --no-dev \
    --no-interaction \
    --no-ansi \
    && composer clear-cache

# Compile views and Filament components at build time as a smoke test (broken
# Blade/Filament templates fail the image build). start-container clears and
# rebuilds them at runtime so injected env vars are always respected.
RUN php artisan view:cache \
    && php artisan filament:optimize

RUN chmod +x /usr/local/bin/start-container /usr/local/bin/healthcheck \
    && chown ${WWWUSER}:${WWWGROUP} ${ROOT} \
    && chown -R ${WWWUSER}:${WWWGROUP} storage bootstrap/cache public

RUN cat deployment/utilities.sh >> ${ROOT}/.bashrc

ENV GIT_COMMIT_HASH=${GIT_COMMIT_HASH} \
    GIT_COMMIT_DATE=${GIT_COMMIT_DATE}

EXPOSE 8000
EXPOSE 443
EXPOSE 443/udp
EXPOSE 2019

ENTRYPOINT ["start-container"]

# `php artisan octane:status` boots a full Laravel CLI for every probe, so the
# old 2s interval hammered the CPU. The script now hits the /up route through an
# existing worker and runs at a calm 30s cadence (30s startup grace included).
HEALTHCHECK --start-period=30s --interval=30s --timeout=5s --retries=3 CMD healthcheck || exit 1
