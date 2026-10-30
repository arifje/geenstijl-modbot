# syntax=docker/dockerfile:1

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# One image, two roles:
#   web:       frankenphp php-server (admin panel on :8080)
#   scheduler: php artisan schedule:work (default)
# Pinned to an exact PHP patch release so a rebuild never silently keeps an
# older one from the build cache. For a PHP security release, bump this tag
# (see https://hub.docker.com/r/dunglas/frankenphp/tags?name=php8.5) and release.
FROM dunglas/frankenphp:1.13.4-php8.5.12-alpine

RUN install-php-extensions pdo_pgsql intl pcntl opcache zip \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "opcache.enable_cli=1" > "$PHP_INI_DIR/conf.d/opcache-cli.ini" \
    # Run as non-root: no privileged ports needed, Caddy data dirs writable.
    && setcap -r /usr/local/bin/frankenphp \
    && chown -R www-data:www-data /data /config

WORKDIR /app
COPY --from=vendor --chown=www-data:www-data /app /app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stack \
    LOG_STACK=stderr,database \
    LOG_LEVEL=info \
    SERVER_NAME=:8080

USER www-data
RUN php artisan package:discover --ansi \
    && php artisan filament:assets --ansi

EXPOSE 8080

# The base image checks Caddy's admin endpoint, which is not used here.
# docker-compose and Kubernetes define their own checks per role.
HEALTHCHECK NONE

# Scheduler: first clear what a previous scheduler container left behind.
CMD ["sh", "-c", "php artisan modbot:recover; exec php artisan schedule:work"]
