# Deployment Plan: Crockenhill Baptist Church Website

## Project Requirements

Based on analysis of the codebase, this deployment must support:

| Requirement | Source | Implication |
|-------------|--------|-------------|
| FFmpeg video processing | `php-ffmpeg/php-ffmpeg` dependency, livestream segmentation | Container needs full FFmpeg installation |
| ImageMagick | `php8.4-imagick` extension, `PageImageService` | Image manipulation for page content |
| 2GB file uploads | Current production limit | Nginx and PHP must allow large request bodies |
| 1-hour request timeouts | Current production limit | Web server and PHP-FPM timeout configuration |
| Background queue workers | Sermon transcription, video extraction jobs | Supervisor must run `queue:work` alongside web server |
| PHP 8.4 | `docker-compose.yml` uses `docker/8.4` | Must match development environment |
| Node.js 22 | Vite builds, Tailwind CSS | Frontend assets compiled at build time |
| Redis | Queue driver, caching | Separate container for queue backend |
| MySQL 8.0 | Current development database | Production database (managed or containerized) |
| DigitalOcean Spaces | S3-compatible storage for media | Environment configuration for `flysystem-aws-s3-v3` |

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         GitHub                                   │
│                            │                                     │
│                     push to master                               │
│                            ▼                                     │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │                   GitHub Actions                         │    │
│  │  1. Run tests (PHPStan, PHPUnit)                        │    │
│  │  2. Build Docker image                                   │    │
│  │  3. Push to GitHub Container Registry                    │    │
│  │  4. SSH to server and deploy                            │    │
│  └─────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    DigitalOcean Droplet ($9/month)               │
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │                     Docker Compose                          │ │
│  │                                                             │ │
│  │  ┌─────────────┐                                           │ │
│  │  │    Caddy    │  :80/:443 - SSL termination, reverse proxy│ │
│  │  └──────┬──────┘                                           │ │
│  │         │                                                   │ │
│  │         ▼                                                   │ │
│  │  ┌─────────────────────────────────────────────────────┐   │ │
│  │  │              App Container                           │   │ │
│  │  │                                                      │   │ │
│  │  │  ┌────────────────────────────────────────────────┐ │   │ │
│  │  │  │              Supervisor                         │ │   │ │
│  │  │  │                                                 │ │   │ │
│  │  │  │  • Nginx (:80)     - serves requests           │ │   │ │
│  │  │  │  • PHP-FPM         - executes PHP              │ │   │ │
│  │  │  │  • Queue Worker x2 - background jobs           │ │   │ │
│  │  │  └────────────────────────────────────────────────┘ │   │ │
│  │  │                                                      │   │ │
│  │  │  Includes: FFmpeg, ImageMagick, PHP extensions      │   │ │
│  │  └─────────────────────────────────────────────────────┘   │ │
│  │                          │                                  │ │
│  │                          ▼                                  │ │
│  │  ┌─────────────┐    ┌─────────────┐                        │ │
│  │  │    MySQL    │    │    Redis    │                        │ │
│  │  │  (database) │    │(queue/cache)│                        │ │
│  │  └─────────────┘    └─────────────┘                        │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  Backed up via DigitalOcean Droplet Backups ($1.80/month)       │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│                      External Services                           │
│                                                                  │
│  • DigitalOcean Spaces  (sermon audio/video storage)            │
│  • Mailgun              (transactional email)                   │
│  • OpenAI API           (transcription, analysis)               │
└─────────────────────────────────────────────────────────────────┘
```

---

## Design Decisions

### Why Ubuntu 24.04?

Your current Sail setup uses Ubuntu 24.04. The production image uses the same base for consistency - production matches development, eliminating "works on my machine" issues. Ubuntu's packages for FFmpeg and ImageMagick work reliably with the ondrej/php PPA.

### Why a single app container with Supervisor?

Your application needs three processes running simultaneously:
- **Nginx** - Web server handling HTTP requests
- **PHP-FPM** - PHP process manager for web requests
- **Queue workers** - Processing background jobs (transcription, video extraction)

Options considered:

| Approach | Pros | Cons |
|----------|------|------|
| Single container + Supervisor | Simple deployment, processes share filesystem | Slightly less "pure" Docker |
| Separate containers per process | Better isolation | Complex volume sharing for media files, overkill for single server |

For a church website on one server, simplicity wins. Supervisor is already used in your Sail setup.

### Why containerized MySQL?

You're already using DigitalOcean droplet backups ($1.80/month), which capture the entire server state including database files. This provides adequate backup coverage without paying $15/month for managed MySQL.

MySQL runs in a container with a named volume for data persistence. The volume survives container rebuilds, and droplet backups capture everything.

### Why Redis in a container?

Redis stores ephemeral data (queue jobs, cache). If lost, jobs retry and cache rebuilds. A managed Redis service isn't necessary - a simple container with a volume for persistence is sufficient.

### Why GitHub Container Registry?

1. **Free** - Unlimited for public repositories, generous free tier for private
2. **Integrated** - `GITHUB_TOKEN` authentication works automatically in Actions
3. **No account setup** - Uses existing GitHub credentials

Alternative would be DigitalOcean Container Registry ($5/month) - unnecessary expense.

### Why build assets in CI (not in container)?

Frontend build (`npm run build`) produces static files that don't change between deployments of the same commit. Building in CI means:

1. **Faster image builds** - Node.js not installed in production image
2. **Smaller image** - No node_modules in final container
3. **Cached builds** - GitHub Actions caches npm dependencies

The Dockerfile uses multi-stage builds: Node stage compiles assets, final stage only includes the output.

---

## Implementation

### File Structure

```
project/
├── Dockerfile                    # Production image definition (new)
├── Caddyfile                     # SSL/reverse proxy config (new)
├── docker-compose.yml            # Local development with Sail (existing, unchanged)
├── docker-compose.prod.yml       # Production services (new, standalone)
├── docker/
│   ├── 8.4/                      # Existing Sail config (unchanged)
│   └── production/               # New production configs
│       ├── nginx.conf
│       ├── php.ini
│       ├── php-fpm.conf
│       └── supervisord.conf
├── .github/
│   └── workflows/
│       ├── integration-tests.yml # Existing (unchanged)
│       └── deploy.yml            # New: build and deploy
└── scripts/
    └── server-setup.sh           # One-time server provisioning
```

**Key point**: The existing `docker-compose.yml` stays exactly as it is. Sail continues to work normally. Production uses a completely separate `docker-compose.prod.yml` file.

### Dockerfile

```dockerfile
# =============================================================================
# Stage 1: Frontend Assets
# =============================================================================
FROM node:22-alpine AS frontend

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js tailwind.config.js postcss.config.js ./
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
RUN composer dump-autoload --optimize --no-dev

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
        supervisor nginx ffmpeg \
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
    # Cleanup
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Application user
RUN groupadd --gid 1000 www \
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

# Directories and permissions
RUN mkdir -p /run/php /var/log/supervisor \
    && mkdir -p storage/framework/{cache/data,sessions,views} storage/logs \
    && chown -R www:www storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Remove default nginx config
RUN rm -f /etc/nginx/sites-enabled/default

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -fsS http://localhost/up || exit 1

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
```

**Explanation of each stage:**

1. **Frontend stage** - Installs npm packages and runs `vite build`. This stage is discarded after the build - only the `public/build/` directory is copied to the final image.

2. **Vendor stage** - Runs `composer install --no-dev` to get production dependencies only. This stage is also discarded - only the `vendor/` directory is copied forward.

3. **Production stage** - The actual runtime image. Copies compiled assets and vendor directory from previous stages. No Node.js or Composer in the final image.

### docker/production/nginx.conf

```nginx
user www;
worker_processes auto;
pid /run/nginx.pid;
error_log /var/log/nginx/error.log warn;

events {
    worker_connections 1024;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    log_format main '$remote_addr - $remote_user [$time_local] "$request" '
                    '$status $body_bytes_sent "$http_referer" '
                    '"$http_user_agent"';
    access_log /var/log/nginx/access.log main;

    sendfile on;
    tcp_nopush on;
    keepalive_timeout 65;

    # Upload and timeout configuration (matches current production)
    client_max_body_size 2G;
    client_body_timeout 3600s;
    client_header_timeout 3600s;
    send_timeout 3600s;

    # Gzip static assets
    gzip on;
    gzip_vary on;
    gzip_types text/plain text/css application/json application/javascript
               text/xml application/xml text/javascript image/svg+xml;
    gzip_min_length 1000;

    server {
        listen 80;
        server_name _;
        root /var/www/html/public;
        index index.php;

        charset utf-8;

        # Security headers
        add_header X-Frame-Options "SAMEORIGIN";
        add_header X-Content-Type-Options "nosniff";
        add_header Referrer-Policy "strict-origin-when-cross-origin";
        add_header Permissions-Policy "camera=(), microphone=(), geolocation=()";

        # Laravel's built-in health check
        location /up {
            try_files $uri /index.php?$query_string;
        }

        # Livewire routes - must come before static asset caching
        location ^~ /livewire {
            try_files $uri $uri/ /index.php?$query_string;
        }

        # Static files - cache aggressively
        location ~* \.(js|css|png|jpg|jpeg|gif|webp|ico|svg|woff|woff2)$ {
            expires 1y;
            add_header Cache-Control "public, max-age=31536000, immutable";
            access_log off;
            try_files $uri =404;
        }

        # All other requests
        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        location = /favicon.ico { access_log off; log_not_found off; }
        location = /robots.txt  { access_log off; log_not_found off; }

        error_page 404 /index.php;

        location ~ \.php$ {
            fastcgi_pass unix:/run/php/php8.4-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
            include fastcgi_params;
            fastcgi_read_timeout 3600s;
            fastcgi_send_timeout 3600s;
            fastcgi_connect_timeout 75s;
        }

        # Block access to hidden files (except .well-known for ACME)
        location ~ /\.(?!well-known).* {
            deny all;
        }
    }
}
```

**Key settings** (matching your current production):

- `client_max_body_size 2G` - Upload limit
- `3600s` timeouts - 1 hour for media processing
- Security headers carried over from current config
- Livewire routes prioritized before static caching

### docker/production/php.ini

```ini
; =============================================================================
; Production PHP Configuration
; =============================================================================

[PHP]
; Upload limits (matching current production)
post_max_size = 2G
upload_max_filesize = 2G

; Execution limits for media processing (1 hour)
max_execution_time = 3600
max_input_time = 3600
memory_limit = 512M

; Form handling
max_input_vars = 3000
variables_order = EGPCS

; Error handling - log, don't display
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /var/www/html/storage/logs/php-errors.log
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT

; OPcache - critical for production performance
[opcache]
opcache.enable = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 32
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0          ; Don't check for file changes
opcache.save_comments = 1                ; Required for annotations
opcache.enable_file_override = 1

; Session security
[Session]
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Lax
session.use_strict_mode = 1

; Date
[Date]
date.timezone = Europe/London
```

**OPcache settings explained:**

- `validate_timestamps = 0` - Never check if PHP files changed. Safe because we deploy new containers for code changes.
- `max_accelerated_files = 20000` - Enough for Laravel + Filament + all packages
- `memory_consumption = 256` - Sufficient for caching all compiled scripts

### docker/production/php-fpm.conf

```ini
[www]
user = www
group = www

listen = /run/php/php8.4-fpm.sock
listen.owner = www
listen.group = www
listen.mode = 0660

; Process management
pm = dynamic
pm.max_children = 15          ; Max concurrent PHP requests
pm.start_servers = 4          ; Initial worker count
pm.min_spare_servers = 2      ; Keep at least 2 idle
pm.max_spare_servers = 6      ; Don't keep more than 6 idle
pm.max_requests = 500         ; Recycle workers to prevent memory leaks

; Match the max_execution_time setting (1 hour)
request_terminate_timeout = 3600

; Pass environment variables to PHP
clear_env = no

; Logging
catch_workers_output = yes
decorate_workers_output = no
```

**Process manager settings:**

- `pm = dynamic` - Spawns workers as needed, kills idle ones
- `pm.max_children = 15` - With 2GB memory limit per request, this allows ~30GB peak usage. Adjust based on your droplet size.
- `request_terminate_timeout = 7200` - Kills requests after 2 hours if still running

### docker/production/supervisord.conf

```ini
[supervisord]
nodaemon=true
user=root
logfile=/var/log/supervisor/supervisord.log
pidfile=/var/run/supervisord.pid
loglevel=info

[program:nginx]
command=/usr/sbin/nginx -g "daemon off;"
autostart=true
autorestart=true
priority=10
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:php-fpm]
command=/usr/sbin/php-fpm8.4 --nodaemonize
autostart=true
autorestart=true
priority=5
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:queue-worker]
command=php /var/www/html/artisan queue:work redis
    --sleep=3
    --tries=3
    --max-time=3600
    --max-jobs=100
    --memory=512
process_name=%(program_name)s_%(process_num)02d
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/queue-worker.log
stopwaitsecs=3660
```

**Queue worker settings:**

- `numprocs=2` - Two workers process jobs in parallel. Increase for heavier load.
- `--max-time=3600` - Worker restarts after 1 hour to free memory
- `--max-jobs=100` - Worker restarts after 100 jobs
- `--memory=512` - Worker restarts if it exceeds 512MB
- `stopwaitsecs=3660` - Waits longer than max-time for graceful shutdown

### docker-compose.yml (local development - unchanged)

Your existing `docker-compose.yml` stays exactly as it is. Sail continues to work with `sail up`.

### docker-compose.prod.yml (production - new)

This is a standalone file used only on the production server. It doesn't layer on top of `docker-compose.yml`.

```yaml
# Production Docker Compose - standalone file for the server
# Usage: docker compose -f docker-compose.prod.yml up -d

services:
  caddy:
    image: caddy:2
    restart: always
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy-data:/data
      - caddy-config:/config
    depends_on:
      - app
    networks:
      - crockenhill

  app:
    image: ghcr.io/your-username/crockenhill:${IMAGE_TAG:-latest}
    restart: always
    expose:
      - "80"
    env_file:
      - .env.production
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
    volumes:
      # Persist local storage (pages, temporary files - sermons are in Spaces)
      - app-storage:/var/www/html/storage/app/public
    logging:
      driver: "json-file"
      options:
        max-size: "50m"
        max-file: "3"
    networks:
      - crockenhill

  mysql:
    image: mysql:8.0
    restart: always
    env_file:
      - .env.production
    environment:
      MYSQL_ROOT_PASSWORD: '${DB_PASSWORD}'
      MYSQL_DATABASE: '${DB_DATABASE}'
      MYSQL_USER: '${DB_USERNAME}'
      MYSQL_PASSWORD: '${DB_PASSWORD}'
    volumes:
      - mysql-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-p${DB_PASSWORD}"]
      interval: 10s
      timeout: 5s
      retries: 3
    logging:
      driver: "json-file"
      options:
        max-size: "20m"
        max-file: "3"
    networks:
      - crockenhill

  redis:
    image: redis:7-alpine
    restart: always
    command: redis-server --appendonly yes
    volumes:
      - redis-data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 3s
      retries: 3
    logging:
      driver: "json-file"
      options:
        max-size: "10m"
        max-file: "3"
    networks:
      - crockenhill

networks:
  crockenhill:
    driver: bridge

volumes:
  caddy-data:      # Let's Encrypt certificates
  caddy-config:    # Caddy configuration cache
  app-storage:     # Laravel storage (non-Spaces files)
  mysql-data:      # Database persistence
  redis-data:      # Queue/cache persistence
```

### Caddyfile

Caddy handles SSL automatically - it obtains and renews Let's Encrypt certificates without any manual setup.

```
crockenhill.org, www.crockenhill.org {
    reverse_proxy app:80

    # Handle large uploads (2GB, 1 hour timeout)
    request_body {
        max_size 2GB
    }

    # Redirect www to non-www
    @www host www.crockenhill.org
    redir @www https://crockenhill.org{uri} permanent
}
```

**Why Caddy over Certbot/nginx on host:**
- Automatic certificate provisioning and renewal
- No cron jobs or manual renewal
- Single container handles both SSL and proxying
- Certificates persist in the `caddy-data` volume

### .github/workflows/deploy.yml

```yaml
name: Deploy

on:
  push:
    branches: [master]
  workflow_dispatch:

env:
  REGISTRY: ghcr.io
  IMAGE_NAME: ${{ github.repository }}

jobs:
  test:
    name: Test
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ALLOW_EMPTY_PASSWORD: yes
          MYSQL_DATABASE: testing
        ports:
          - 3306:3306
        options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=3

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite, bcmath, soap, intl, gd, exif, iconv, imagick, redis
          coverage: none

      - name: Install FFmpeg
        run: sudo apt-get update && sudo apt-get install -y ffmpeg

      - name: Cache Composer
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}

      - name: Install PHP dependencies
        run: composer install --prefer-dist --no-interaction

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: 'npm'

      - name: Install and build frontend
        run: npm ci && npm run build

      - name: Prepare Laravel
        run: |
          cp .env.example .env
          php artisan key:generate
          echo "DB_HOST=127.0.0.1" >> .env
          echo "DB_DATABASE=testing" >> .env
          echo "DB_USERNAME=root" >> .env
          echo "QUEUE_CONNECTION=sync" >> .env
          echo "TRANSCRIPTION_SERVICE_TYPE=mock" >> .env

      - name: Run migrations
        run: php artisan migrate --force

      - name: PHPStan
        run: composer phpstan

      - name: Tests
        run: php artisan test --parallel

  build:
    name: Build & Push
    needs: test
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write
    outputs:
      image_tag: ${{ steps.meta.outputs.version }}

    steps:
      - uses: actions/checkout@v4

      - name: Set up Docker Buildx
        uses: docker/setup-buildx-action@v3

      - name: Log in to GitHub Container Registry
        uses: docker/login-action@v3
        with:
          registry: ${{ env.REGISTRY }}
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Extract metadata
        id: meta
        uses: docker/metadata-action@v5
        with:
          images: ${{ env.REGISTRY }}/${{ env.IMAGE_NAME }}
          tags: |
            type=sha,prefix=
            type=raw,value=latest

      - name: Build and push
        uses: docker/build-push-action@v5
        with:
          context: .
          push: true
          tags: ${{ steps.meta.outputs.tags }}
          labels: ${{ steps.meta.outputs.labels }}
          cache-from: type=gha
          cache-to: type=gha,mode=max

  deploy:
    name: Deploy
    needs: build
    runs-on: ubuntu-latest
    environment: production

    steps:
      - name: Deploy to server
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.PROD_HOST }}
          username: ${{ secrets.PROD_USER }}
          key: ${{ secrets.PROD_SSH_KEY }}
          script: |
            set -e
            cd /srv/crockenhill

            # Pull new image
            docker compose -f docker-compose.prod.yml pull

            # Run migrations
            docker compose -f docker-compose.prod.yml run --rm app \
              php artisan migrate --force

            # Restart services
            docker compose -f docker-compose.prod.yml up -d

            # Clear caches and optimize
            docker compose -f docker-compose.prod.yml exec -T app \
              php artisan optimize

            # Cleanup
            docker image prune -f

            # Health check
            sleep 15
            curl -fsS http://localhost/up || exit 1
```

**Workflow stages:**

1. **Test** - Runs PHPStan and full test suite. Fails fast if code is broken.
2. **Build** - Creates Docker image and pushes to GHCR. Uses build cache for speed.
3. **Deploy** - SSHs to server, pulls new image, runs migrations, restarts containers.

### scripts/server-setup.sh

Run this once on a fresh DigitalOcean Ubuntu 24.04 droplet:

```bash
#!/bin/bash
set -e

echo "=== Installing Docker ==="
curl -fsSL https://get.docker.com | sh
systemctl enable --now docker

echo "=== Creating deploy user ==="
useradd -m -s /bin/bash -G docker deploy
mkdir -p /home/deploy/.ssh
cp /root/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys

echo "=== Creating application directory ==="
mkdir -p /srv/crockenhill
chown deploy:deploy /srv/crockenhill

echo "=== Configuring firewall ==="
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

echo "=== Setup complete ==="
echo ""
echo "Next steps:"
echo "1. Add deploy user's SSH public key to GitHub Actions secrets as PROD_SSH_KEY"
echo "2. Copy docker-compose.prod.yml and Caddyfile to /srv/crockenhill/"
echo "3. Create /srv/crockenhill/.env.production with your credentials"
echo "4. Run: docker compose -f docker-compose.prod.yml pull"
echo "5. Run: docker compose -f docker-compose.prod.yml up -d"
```

---

## Environment Variables

### GitHub Actions Secrets

Configure these in repository Settings → Secrets and variables → Actions:

| Secret | Value |
|--------|-------|
| `PROD_HOST` | Your droplet's IP address |
| `PROD_USER` | `deploy` |
| `PROD_SSH_KEY` | Contents of deploy user's private key |

### Production .env

Create `/srv/crockenhill/.env.production` on the server:

```bash
APP_NAME="Crockenhill Baptist Church"
APP_ENV=production
APP_KEY=base64:generate-with-php-artisan-key-generate
APP_DEBUG=false
APP_URL=https://crockenhill.org

LOG_CHANNEL=stack
LOG_LEVEL=warning

# MySQL (container name as hostname)
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=crockenhill
DB_USERNAME=crockenhill
DB_PASSWORD=your-secure-password

# Redis (container name as hostname)
REDIS_HOST=redis
REDIS_PORT=6379

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# DigitalOcean Spaces
FILESYSTEM_DISK=do_spaces
DO_SPACES_KEY=your-spaces-key
DO_SPACES_SECRET=your-spaces-secret
DO_SPACES_REGION=ams3
DO_SPACES_BUCKET=crockenhill-media
DO_SPACES_ENDPOINT=https://ams3.digitaloceanspaces.com

# Mailgun
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=mg.crockenhill.org
MAILGUN_SECRET=your-mailgun-api-key
MAIL_FROM_ADDRESS=admin@crockenhill.org
MAIL_FROM_NAME="Crockenhill Baptist Church"

# OpenAI
OPENAI_API_KEY=your-openai-key
TRANSCRIPTION_SERVICE_TYPE=openai
ANALYSIS_SERVICE=openai

# Media Processing
FFMPEG_PATH=/usr/bin/ffmpeg
FFPROBE_PATH=/usr/bin/ffprobe
LIVESTREAM_ADMIN_EMAIL=your-email@example.com
```

---

## Deployment Workflow

### First Deployment

```bash
# On server (as root)
./scripts/server-setup.sh

# As deploy user
cd /srv/crockenhill

# Copy files from your local machine or paste contents
# - docker-compose.prod.yml
# - Caddyfile
# - .env.production

# Pull and start
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d

# Run initial migrations
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force

# Seed if needed
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --force
```

### Subsequent Deployments

Push to `master` branch → GitHub Actions handles everything automatically.

### Manual Deployment

```bash
cd /srv/crockenhill
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml exec app php artisan optimize
```

### Rollback

```bash
cd /srv/crockenhill

# Find previous image tag (first 7 chars of commit SHA)
docker images ghcr.io/your-username/crockenhill

# Deploy specific version
IMAGE_TAG=abc1234 docker compose -f docker-compose.prod.yml up -d
```


---

## Monitoring

### View logs

```bash
# All containers
docker compose -f docker-compose.prod.yml logs -f

# Specific service
docker compose -f docker-compose.prod.yml logs -f app

# Laravel logs inside container
docker compose -f docker-compose.prod.yml exec app tail -f storage/logs/laravel.log
```

### Check queue status

```bash
docker compose -f docker-compose.prod.yml exec app php artisan queue:monitor
```

### Container health

```bash
docker compose -f docker-compose.prod.yml ps
```

---

## Cost Summary

Based on your current infrastructure:

| Service | Monthly Cost | Notes |
|---------|-------------|-------|
| DigitalOcean Droplet | $9.00 | Runs app, MySQL, Redis containers |
| Droplet Backups | $1.80 | Captures entire server including database |
| Spaces | ~$3.75 | Sermon audio/video storage |
| **Total** | **~$14.55** | |

No infrastructure changes required. The deployment improvements are about process (CI/CD, containerization), not spending more money.

---

## Additional Tasks

### Update Sail php.ini to match production

The current `docker/php/php.ini` has 5GB/2-hour limits which exceed production. Update to match:

```ini
[PHP]
post_max_size = 2G
upload_max_filesize = 2G
max_execution_time = 3600
max_input_time = 3600
memory_limit = 512M
max_input_vars = 3000
variables_order = EGPCS
```

This ensures local development mirrors production behaviour.
