# Build context: the repository root, so that the bundle (..) is available to the demo's path repository.
FROM dunglas/frankenphp:1-php8.3

RUN install-php-extensions intl opcache zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json /app/
COPY src /app/src
COPY translations /app/translations
COPY demo /app/demo

WORKDIR /app/demo
RUN composer install --no-interaction --no-progress --prefer-dist

# no-cache: the browser revalidates the stylesheet and script on every load, so an edit shows up at once.
ENV SERVER_NAME=:80 \
    SERVER_ROOT=/app/demo/public \
    CADDY_SERVER_EXTRA_DIRECTIVES='header /demo.* Cache-Control "no-cache"'

COPY demo/docker/entrypoint.sh /usr/local/bin/demo-entrypoint
RUN chmod +x /usr/local/bin/demo-entrypoint

ENTRYPOINT ["demo-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
