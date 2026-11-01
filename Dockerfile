FROM php:8.4-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libonig-dev libxml2-dev libsqlite3-dev libpq-dev \
    && docker-php-ext-install pdo_pgsql pdo_sqlite mbstring dom \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-interaction --prefer-dist
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
