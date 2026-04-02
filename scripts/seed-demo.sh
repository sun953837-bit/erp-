#!/usr/bin/env sh
set -e

docker compose exec php-api php artisan db:seed --force
