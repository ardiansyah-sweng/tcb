# Development image for the TCB API (used by both `app` and `worker`).
FROM php:8.4-cli-alpine

RUN apk add --no-cache git unzip postgresql-dev icu-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql pgsql intl zip pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/api

COPY docker/entrypoint.sh /usr/local/bin/tcb-entrypoint
RUN chmod +x /usr/local/bin/tcb-entrypoint

ENTRYPOINT ["tcb-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
