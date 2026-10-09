#!/bin/sh
# Local-only startup steps. compose.yaml mounts this file; the image never contains it.
set -e

exec docker-php-serversideup-entrypoint "$@"
