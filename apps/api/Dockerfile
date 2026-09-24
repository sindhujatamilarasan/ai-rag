FROM php:8.5-cli

# libpq-dev Postgres driver-ku venum, unzip/git composer-ku.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev unzip git \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Nee local-la php.ini-la fix panna upload limit. Andha fix container-la-um venum,
# illana "The image failed to upload" thirumba varum.
RUN printf "upload_max_filesize=20M\npost_max_size=25M\n" \
    > /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Composer files mudhalla: code maarum bodhu vendor layer cache-la irundhu varum.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
