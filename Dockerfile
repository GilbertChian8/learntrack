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
    SHOW_WELCOME_MESSAGE=false \
    PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
# The RDS certificate authorities, for MYSQL_ATTR_SSL_CA in AWS.
ADD --checksum=sha256:fe45bbebf92ad3e27a583bbb2ddd1553c521ed4d49af5514dc0a40372ea5395c --chmod=644 \
    https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem /etc/ssl/certs/rds-global-bundle.pem
COPY --chown=www-data:www-data . /var/www/html
COPY --chown=www-data:www-data --from=vendor /app/vendor /var/www/html/vendor
COPY --chown=www-data:www-data --from=assets /app/public/build /var/www/html/public/build
RUN php artisan package:discover --ansi
RUN php artisan lighthouse:cache
