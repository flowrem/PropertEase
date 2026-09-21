# --- Frontend assets ---
FROM node:22-slim AS assets
WORKDIR /app
COPY . .
RUN npm ci && npm run build

# --- PHP application ---
FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
        git unzip libzip-dev libonig-dev libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql mbstring zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 10000

CMD ["/bin/sh", "-c", "php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"]
