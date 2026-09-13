# One self-contained image: nginx + PHP-FPM, no separate containers to wire
# up. No database, no build step for the app itself - only `composer install`
# runs at image build time.
#
# config/untis.yaml (credentials) and var/ (cache + admin-saved state) are
# never baked into the image; both are meant to be mounted at runtime. See
# "Docker" in README.md.

FROM composer:2 AS composer

FROM php:8.4-fpm-alpine AS build
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# Installed before the rest of the source is copied in, so this layer is
# only rebuilt when the dependency list actually changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-progress --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize \
    && rm -rf var/cache/* var/log/* config/untis.yaml

FROM php:8.4-fpm-alpine
RUN apk add --no-cache nginx \
    && rm -f /etc/nginx/http.d/default.conf

WORKDIR /var/www/html
COPY --from=build /var/www/html /var/www/html
COPY docker/nginx.conf /etc/nginx/http.d/webuntis-dashboard.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p var config \
    && chown -R www-data:www-data /var/www/html/var

ENV APP_ENV=prod APP_DEBUG=0
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s CMD wget -qO- http://localhost/ || exit 1

ENTRYPOINT ["entrypoint.sh"]
