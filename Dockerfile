# syntax=docker/dockerfile:1.7

########################################
# Stage 1: build frontend assets (Vite)
########################################
FROM node:20-bookworm-slim AS assets
WORKDIR /app

# VITE_APP_NAME and ASSET_URL are inlined into the JS/CSS bundle at build time by
# Vite — they must be supplied here as build ARGs, since the runtime .env doesn't
# exist yet at this point. ASSET_URL is required when the app is served from a
# subpath (e.g. https://host/sorify), otherwise laravel-vite-plugin builds asset
# paths relative to the domain root and they 404 behind the subpath.
ARG VITE_APP_NAME=Sorify
ARG ASSET_URL=""
ENV VITE_APP_NAME=${VITE_APP_NAME}
ENV ASSET_URL=${ASSET_URL}

COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

########################################
# Stage 2: runtime image (FrankenPHP / Octane)
########################################
FROM dunglas/frankenphp:1.12.6-bookworm AS runtime

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright

# System deps + Node 20 (base image ships PHP/FrankenPHP tooling only, no Node)
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg git unzip \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions Laravel/Sorify need that the base image doesn't already ship
# (verified: pdo_mysql, intl, pcntl are missing; pdo_sqlite/opcache already present)
RUN install-php-extensions pdo_mysql intl pcntl opcache

# Pin composer to the same version used in CI
COPY --from=composer:2.8.9 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker/php.ini /usr/local/etc/php/conf.d/99-sorify.ini

# Composer deps in their own cacheable layer (before app code changes invalidate it)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist \
        --no-scripts --no-autoloader

# Full application source + pre-built assets from stage 1
COPY . .
COPY --from=assets /app/public/build ./public/build

# Now that artisan/bootstrap exist, finish the autoloader and let packages discover
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi

# Node runtime deps (the `playwright` npm package) + headless Chromium + its OS deps.
# .npmrc's ignore-scripts=true means `npm ci` alone never downloads browsers —
# this explicit install step is required.
RUN npm ci --omit=dev \
    && npx playwright install --with-deps chromium

# Seed baseline runtime directories (overlaid by the storage volume at runtime,
# this just ensures the volume isn't empty/unwritable on first boot)
RUN mkdir -p \
        storage/app/public \
        storage/app/tmp \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "artisan", "octane:start", "--host=0.0.0.0", "--port=8000", "--workers=auto", "--max-requests=500"]
