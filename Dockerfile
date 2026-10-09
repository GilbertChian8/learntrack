# syntax=docker/dockerfile:1

# Vite assets. Only public/build leaves this stage, so the final image has no Node.
FROM node:24 AS assets
WORKDIR /app
ENV VITE_APP_NAME=LearnTrack
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY . .
RUN npm run build

# Production PHP dependencies. Platform checks run in the final image, which has PHP 8.5.
FROM composer:2.10.3 AS vendor
WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist --no-scripts --ignore-platform-reqs

# nginx plus PHP-FPM on PHP 8.5, unprivileged (www-data), HTTP on 8080.
FROM serversideup/php:8.5.8-fpm-nginx-trixie-v4.5.1
ENV HEALTHCHECK_PATH=/up \
    SHOW_WELCOME_MESSAGE=false
COPY --chown=www-data:www-data . /var/www/html
COPY --chown=www-data:www-data --from=vendor /app/vendor /var/www/html/vendor
COPY --chown=www-data:www-data --from=assets /app/public/build /var/www/html/public/build
RUN php artisan package:discover --ansi
