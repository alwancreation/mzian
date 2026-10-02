# syntax=docker/dockerfile:1.7
#
# Mzian.net application image.
#
# Targets:
#   php_dev   PHP-FPM for local development (sources are bind-mounted)
#   php_prod  self-contained production PHP-FPM image (vendor + built assets)
#   nginx     production web server serving public/ and proxying to php_prod
#
ARG PHP_VERSION=8.3
ARG NODE_VERSION=22
ARG NGINX_VERSION=1.27

# -----------------------------------------------------------------------------
# Base PHP image with every extension the application needs
# -----------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS php_base

WORKDIR /srv/app

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN apk add --no-cache acl bash fcgi file gettext git icu-data-full unzip \
    && install-php-extensions apcu intl opcache pcntl pdo_mysql redis sockets zip \
    && rm -rf /tmp/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    PATH="${PATH}:/srv/app/vendor/bin"

COPY docker/php/conf.d/10-app.ini $PHP_INI_DIR/conf.d/
COPY docker/php/php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=0755 docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --chmod=0755 docker/php/docker-healthcheck.sh /usr/local/bin/docker-healthcheck

HEALTHCHECK --start-period=120s --interval=10s --timeout=3s --retries=6 CMD ["docker-healthcheck"]
ENTRYPOINT ["docker-entrypoint"]
CMD ["php-fpm"]

# -----------------------------------------------------------------------------
# Development image (sources mounted as a volume)
# -----------------------------------------------------------------------------
FROM php_base AS php_dev

ENV APP_ENV=dev
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/php/conf.d/20-app.dev.ini $PHP_INI_DIR/conf.d/

# -----------------------------------------------------------------------------
# Frontend assets (Tailwind CSS + JS bundle)
# -----------------------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS assets_builder

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY assets ./assets
COPY templates ./templates
COPY src ./src
RUN npm run build

# -----------------------------------------------------------------------------
# Production PHP image
# -----------------------------------------------------------------------------
FROM php_base AS php_prod

ENV APP_ENV=prod APP_DEBUG=0
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/conf.d/20-app.prod.ini $PHP_INI_DIR/conf.d/

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY . ./
COPY --from=assets_builder /app/public/build ./public/build

RUN rm -f .env.local .env.*.local \
    && composer dump-autoload --classmap-authoritative --no-dev \
    && composer dump-env prod \
    && composer run-script --no-dev post-install-cmd \
    && mkdir -p var/cache var/log var/share var/workspaces var/repositories var/previews \
    && chown -R www-data:www-data var \
    && sync

# -----------------------------------------------------------------------------
# Production web server
# -----------------------------------------------------------------------------
FROM nginx:${NGINX_VERSION}-alpine AS nginx

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=php_prod /srv/app/public /srv/app/public
