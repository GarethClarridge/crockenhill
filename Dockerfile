# =============================================================================
# Stage 1: Frontend Assets
# =============================================================================
FROM node:26-alpine AS frontend

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.mjs ./
COPY resources/ ./resources/

RUN npm run build

# =============================================================================
# Stage 2: PHP Dependencies
# =============================================================================
FROM composer:2 AS vendor

WORKDIR /build

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# =============================================================================
# Stage 3: Production Image
# =============================================================================
FROM php:8.5-fpm-bookworm

LABEL maintainer="Crockenhill Baptist Church"

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Europe/London

# System packages and PHP extensions
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        curl ca-certificates zip unzip \
        supervisor nginx ffmpeg default-mysql-client \
        python3 python3-pip \
        $PHPIZE_DEPS build-essential python3-dev \
        libcurl4-openssl-dev libfreetype6-dev libicu-dev libjpeg62-turbo-dev \
        libonig-dev libpng-dev libsqlite3-dev libwebp-dev libxml2-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath curl exif gd intl mbstring mysqli pcntl \
        pdo_mysql pdo_sqlite soap sockets zip \
    # Build tools required for resemblyzer's native extension (webrtcvad)
    # Debian's pip (23.0.1) compares a requested name against the wheel's
    # metadata Name without PEP 503 normalisation, so it rejects every wheel
    # published as typing_extensions when asked for typing-extensions, falls
    # back to the sdist, and then needs a build backend the CPU-only index does
    # not carry. Current pip normalises and takes the wheel.
    && pip3 install --no-cache-dir --break-system-packages --upgrade pip \
    # Speaker identification runtime dependencies. Install PyTorch from the
    # CPU-only wheel index first; default Linux wheels include multi-GB CUDA
    # libraries that are not needed on the production server.
    && pip3 install --no-cache-dir --break-system-packages \
        --index-url https://download.pytorch.org/whl/cpu \
        torch \
    && pip3 install --no-cache-dir --break-system-packages resemblyzer \
    && ! python3 -m pip freeze | grep -E '^nvidia-' \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS build-essential python3-dev \
    # Cleanup
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Application user
RUN if getent passwd ubuntu; then userdel -r ubuntu; fi \
    && if getent group ubuntu; then groupdel ubuntu; fi \
    && groupadd --gid 1000 www \
    && useradd --uid 1000 --gid 1000 -m www

# Copy built assets from previous stages
COPY --from=vendor /build/vendor ./vendor
COPY --from=frontend /build/public/build ./public/build

# Copy application code
COPY --chown=www:www . .

# Configuration files
COPY docker/production/nginx.conf /etc/nginx/nginx.conf
COPY docker/production/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/production/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/production/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/production/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Directories and permissions
RUN mkdir -p /run/php /var/log/supervisor \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    # Creating these here means each matching named volume is seeded with
    # www:www ownership rather than being created root-owned by Docker.
    #
    # storage/app/livestream holds the original uploaded recordings
    # (livestream/temp/{uuid}.ext, written by VideoStorageService), which are NOT
    # moving to Spaces. Note that storage/app/temp being mounted does not cover
    # these: the upload alone lives under livestream/, so without this directory
    # plus its named volume every source recording is destroyed on deploy —
    # losing in-flight processing runs and any chance of re-deriving a lost
    # derived asset.
    && mkdir -p storage/app/livewire-tmp storage/app/temp storage/app/public storage/app/livestream \
    && chown -R www:www storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Clear bootstrap cache (may contain development-only references)
RUN rm -f bootstrap/cache/*.php

# Create storage symlink (public/storage -> storage/app/public)
RUN php artisan storage:link

# Remove default nginx config
RUN rm -f /etc/nginx/sites-enabled/default

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -fsS http://localhost/up || exit 1

CMD ["/usr/local/bin/entrypoint.sh"]
