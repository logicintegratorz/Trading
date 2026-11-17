FROM php:8.4-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libonig-dev libxml2-dev libsqlite3-dev libpq-dev \
    && docker-php-ext-install pdo_pgsql pdo_sqlite mbstring dom \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
# The release image is built without the live .env by design. Composer's
# Laravel hooks boot Artisan and can therefore fail during this build phase.
# Install production dependencies only; the deployment runs `artisan optimize`
# after recreating the container with its real environment.
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
