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

# Timetable change notifications (bin/check-changes) - the container's
# stand-in for the cron job a bare-metal install uses. Runs as www-data like
# PHP-FPM, since both write to var/; busybox su keeps the environment, so
# APP_SECRET and friends reach it the same way they reach PHP-FPM. Exits
# straight away while nobody has subscribed, so it costs nothing then.
# CHECK_CHANGES_INTERVAL=0 switches it off entirely.
interval="${CHECK_CHANGES_INTERVAL:-300}"
if [ "$interval" -gt 0 ] 2>/dev/null; then
    su -s /bin/sh www-data -c "
        cd /var/www/html
        while true; do
            php bin/check-changes
            sleep $interval
        done
    " &
fi

exec nginx -g 'daemon off;'
