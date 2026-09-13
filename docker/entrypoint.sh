#!/bin/sh
set -e

# config/untis.yaml is never baked into the image (it holds credentials) -
# mount your own over it. Fail fast with a clear message instead of a
# confusing 500 on every request if it was forgotten.
if [ ! -f /var/www/html/config/untis.yaml ]; then
    echo "config/untis.yaml is missing. Mount your copy into the container" >&2
    echo "at /var/www/html/config/untis.yaml (see \"Docker\" in README.md)." >&2
    exit 1
fi

# var/ (cache, admin-saved settings, homework done-marks) is meant to be a
# volume, so its ownership needs fixing up on every start: a fresh named
# volume is created world-writable by root, not as www-data.
mkdir -p /var/www/html/var
chown -R www-data:www-data /var/www/html/var

php-fpm -D
exec nginx -g 'daemon off;'
