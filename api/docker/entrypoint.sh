#!/bin/sh
set -e

# The source tree is bind-mounted, so bootstrap it on first start.
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --force
fi

exec "$@"
