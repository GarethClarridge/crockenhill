# =============================================================================
# Stage 1: Frontend Assets
# =============================================================================
FROM node:22-alpine AS frontend

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.mjs tailwind.config.js postcss.config.js ./
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
FROM ubuntu:24.04

LABEL maintainer="Crockenhill Baptist Church"

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Europe/London

# System packages and PHP
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        gnupg curl ca-certificates zip unzip \
        supervisor nginx ffmpeg mysql-client \
        python3 python3-pip \
    # PHP repository
    && mkdir -p /etc/apt/keyrings \
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xb8dc7e53946656efbce4c1dd71daeaab4ad4cab6' \
       | gpg --dearmor | tee /etc/apt/keyrings/ppa_ondrej_php.gpg > /dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" \
       > /etc/apt/sources.list.d/ppa_ondrej_php.list \
    && apt-get update \
    # PHP and extensions (matching your Sail setup)
    && apt-get install -y --no-install-recommends \
        php8.4-fpm php8.4-cli \
        php8.4-mysql php8.4-sqlite3 php8.4-gd \
        php8.4-curl php8.4-mbstring php8.4-xml php8.4-zip \
        php8.4-bcmath php8.4-intl php8.4-redis php8.4-imagick \
    # Build tools required for resemblyzer's native extension (webrtcvad)
    && apt-get install -y --no-install-recommends \
        build-essential python3-dev \
    # Speaker identification runtime dependencies. Install PyTorch from the
    # CPU-only wheel index first; default Linux wheels include multi-GB CUDA
    # libraries that are not needed on the production server.
    && pip3 install --no-cache-dir --break-system-packages \
        --index-url https://download.pytorch.org/whl/cpu \
        torch \
    && pip3 install --no-cache-dir --break-system-packages resemblyzer \
    && ! python3 -m pip freeze | grep -E '^nvidia-' \
    && apt-get purge -y --auto-remove build-essential python3-dev \
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
COPY docker/production/php.ini /etc/php/8.4/fpm/conf.d/99-app.ini
COPY docker/production/php.ini /etc/php/8.4/cli/conf.d/99-app.ini
COPY docker/production/php-fpm.conf /etc/php/8.4/fpm/pool.d/www.conf
COPY docker/production/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/production/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Directories and permissions
RUN mkdir -p /run/php /var/log/supervisor \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && mkdir -p storage/app/livewire-tmp storage/app/temp storage/app/public \
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
