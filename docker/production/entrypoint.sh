#!/bin/bash
set -e

# Fix ownership on Docker-mounted volumes (created as root)
# storage/app/livestream is PERMANENT: original uploaded recordings, which are
# not moving to Spaces. A root-owned volume here is a silent upload failure.
chown -R www:www /var/www/html/storage/app/temp \
                 /var/www/html/storage/app/livewire-tmp \
                 /var/www/html/storage/app/public \
                 /var/www/html/storage/app/livestream \
                 /var/www/html/storage/logs

chmod -R 775 /var/www/html/storage/app/temp \
             /var/www/html/storage/app/livewire-tmp \
             /var/www/html/storage/app/public \
             /var/www/html/storage/app/livestream \
             /var/www/html/storage/logs

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
