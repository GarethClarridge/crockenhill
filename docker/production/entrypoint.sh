#!/bin/bash
set -e

# Fix ownership on Docker-mounted volumes (created as root)
chown -R www:www /var/www/html/storage/app/temp \
                 /var/www/html/storage/app/livewire-tmp \
                 /var/www/html/storage/app/public \
                 /var/www/html/storage/logs

chmod -R 775 /var/www/html/storage/app/temp \
             /var/www/html/storage/app/livewire-tmp \
             /var/www/html/storage/app/public \
             /var/www/html/storage/logs

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
