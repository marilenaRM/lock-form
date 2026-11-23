#!/bin/sh
set -e

cd /app/demo

# A fresh random secret per container: nothing to commit, nothing to reuse.
if [ ! -f .env.local ]; then
    echo "APP_SECRET=$(php -r 'echo bin2hex(random_bytes(16));')" > .env.local
fi

if [ "$1" = 'frankenphp' ]; then
    php bin/console app:demo:reset --no-interaction
fi

exec "$@"
