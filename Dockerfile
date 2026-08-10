FROM php:8.3.6-apache-bookworm

ARG APP_ENV=production

ENV APP_ENV=${APP_ENV}
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libcurl4-openssl-dev \
        libonig-dev \
        libgmp-dev \
        libsqlite3-dev \
    && docker-php-ext-install \
        curl \
        mbstring \
        mysqli \
        sqlite3 \
        gmp \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction --optimize-autoloader

COPY . .
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/app.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/entrypoint.sh /usr/local/bin/jellydash-entrypoint

RUN chmod +x /usr/local/bin/jellydash-entrypoint \
    && mkdir -p cache var/cache var/data var/log public/uploads public/uploads/images \
    && chown -R www-data:www-data cache var/cache var/data var/log public/uploads

ENTRYPOINT ["jellydash-entrypoint"]
CMD ["apache2-foreground"]
