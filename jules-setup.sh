#!/bin/bash
# Jules Environment Setup Script
# Paste this into Jules: Configuration > Environment > Setup Script
# Then click "Run and Snapshot" to cache the built environment.
#
# Installs everything natively (no Docker pulls) to avoid Docker Hub rate limits.
set -e

export DEBIAN_FRONTEND=noninteractive

echo "=== Installing PHP 8.4 ==="
sudo mkdir -p /etc/apt/keyrings
sudo apt-get update -qq
sudo apt-get install -y -qq gnupg curl ca-certificates zip unzip git ffmpeg libpng-dev

# Add ondrej PHP PPA (same source as our Sail Dockerfile)
curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xb8dc7e53946656efbce4c1dd71daeaab4ad4cab6' \
    | sudo gpg --dearmor -o /etc/apt/keyrings/ppa_ondrej_php.gpg
echo "deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" \
    | sudo tee /etc/apt/sources.list.d/ppa_ondrej_php.list > /dev/null
sudo apt-get update -qq

sudo apt-get install -y -qq \
    php8.4-cli php8.4-dev \
    php8.4-mysql php8.4-sqlite3 php8.4-gd \
    php8.4-curl php8.4-mbstring php8.4-xml \
    php8.4-zip php8.4-bcmath php8.4-intl \
    php8.4-readline php8.4-redis php8.4-imagick \
    php8.4-pcov php8.4-soap

echo "=== Installing Composer ==="
curl -sLS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/bin/ --filename=composer

echo "=== Installing MySQL 8.0 natively ==="
# Install MySQL server from Ubuntu repos (avoids Docker Hub rate limits)
sudo apt-get install -y -qq mysql-server

# Start MySQL service
sudo service mysql start

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
for i in $(seq 1 30); do
    if sudo mysqladmin ping --silent 2>/dev/null; then
        echo "MySQL is ready"
        break
    fi
    if [ "$i" -eq 30 ]; then
        echo "ERROR: MySQL failed to start"
        sudo journalctl -u mysql --no-pager -n 20 || true
        exit 1
    fi
    sleep 2
done

# Configure MySQL: set root password and create databases
sudo mysql -e "
    ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'password';
    CREATE DATABASE IF NOT EXISTS crockenhill;
    CREATE DATABASE IF NOT EXISTS testing;
    GRANT ALL PRIVILEGES ON \`crockenhill\`.* TO 'root'@'localhost';
    GRANT ALL PRIVILEGES ON \`testing\`.* TO 'root'@'localhost';
    GRANT ALL PRIVILEGES ON \`crockenhill_test_%\`.* TO 'root'@'localhost';
    FLUSH PRIVILEGES;
"

echo "=== Installing Redis natively ==="
sudo apt-get install -y -qq redis-server
sudo service redis-server start

# Verify Redis is running
for i in $(seq 1 10); do
    if redis-cli ping 2>/dev/null | grep -q PONG; then
        echo "Redis is ready"
        break
    fi
    sleep 1
done

echo "=== Installing Composer dependencies ==="
composer install --no-interaction --prefer-dist --no-progress

echo "=== Installing Node dependencies ==="
npm ci --no-progress

echo "=== Building frontend assets ==="
npm run build

echo "=== Setting up .env ==="
cp .env.jules .env
php artisan key:generate --no-interaction

echo "=== Running migrations ==="
php artisan migrate --force --no-interaction

echo "=== Verifying setup ==="
php -v
php artisan --version
echo "MySQL:"
mysql -uroot -ppassword -e "SELECT 'connected' AS status;" 2>/dev/null
echo "Redis:"
redis-cli ping

echo "=== Running a quick smoke test ==="
php artisan test --compact --filter=testHomePage || echo "Smoke test skipped (no matching test)"

echo "=== Setup complete ==="
