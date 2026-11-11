# syntax=docker/dockerfile:1

# ── Shared PHP base ───────────────────────────────────────────────────────────
FROM php:8.4-cli-bookworm AS base

# poppler-utils → pdftotext, hardcoded at /usr/bin/pdftotext by the accounting
# module's local PDF text extraction (invoice ZIP import, Sparbanken fee PDFs,
# receipt scanning fallback). The lib*-dev packages are build deps for the PHP
# extensions installed below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        poppler-utils \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libicu-dev \
        libonig-dev \
        unzip \
        git \
    && rm -rf /var/lib/apt/lists/*

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions \
        pdo_mysql \
        pdo_sqlite \
        mbstring \
        bcmath \
        curl \
        fileinfo \
        gd \
        intl \
        zip \
        opcache \
        pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php.ini /usr/local/etc/php/conf.d/99-membrum2.ini

WORKDIR /var/www/html

# ── PHP dependencies ──────────────────────────────────────────────────────────
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --no-progress

# ── Frontend build ────────────────────────────────────────────────────────────
# Vite 7 needs Node ^20.19 or ^22.12+; the dev-setup docs' "Node 18+" is too old.
# resources/css/app.css @source's vendor/laravel/framework and vendor/livewire/flux
# for their Blade views, so Tailwind needs vendor/ present at build time.
FROM node:22-alpine AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY --from=vendor /var/www/html/vendor ./vendor
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

# ── Dev Container image ───────────────────────────────────────────────────────
# Used only by .devcontainer/ (target: dev). The checkout is bind-mounted over
# /var/www/html instead of copied in, so code edits and git work on the real
# repo; entrypoint.sh installs vendor/, node_modules/ and public/build into the
# checkout on first start. Runs as a non-root user (UID remapped to the host
# user's by Dev Containers) so files it creates aren't root-owned on the host.
# Must stay above the app stage: the last stage is the default build target.
FROM base AS dev

RUN apt-get update && apt-get install -y --no-install-recommends openssh-client \
    && rm -rf /var/lib/apt/lists/*

COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

ARG UID=1000
ARG GID=1000
RUN groupadd -g "$GID" dev && useradd -m -u "$UID" -g "$GID" -s /bin/bash dev

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER dev

EXPOSE 8000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]

# ── Application image ─────────────────────────────────────────────────────────
FROM base AS app

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache \
    && [ -f .env ] || cp .env.example .env \
    && composer dump-autoload --optimize \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["entrypoint.sh"]
# --no-reload: without it, `artisan serve` strips every env var except a small
# hardcoded whitelist (APP_ENV, PATH, ...) from the spawned PHP dev-server
# subprocess — it assumes config comes from the .env file, not real container
# env vars, and re-reads .env on each request instead. That drops APP_KEY,
# DB_HOST etc. here, since we configure via docker-compose `environment:`.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
