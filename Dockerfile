FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev libzip-dev unzip git \
    && docker-php-ext-install pdo_pgsql pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY vendor /var/www/html/vendor
EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
