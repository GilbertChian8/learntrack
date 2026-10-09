#!/bin/sh
# Local-only startup steps. compose.yaml mounts this file; the image never contains it.
set -e

[ -f storage/oauth-private.key ] || php artisan passport:keys

exec docker-php-serversideup-entrypoint "$@"
