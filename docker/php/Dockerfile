FROM php:8.4-fpm-bookworm

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        curl \
        git \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpq-dev \
        libpng-dev \
        libwebp-dev \
        libxml2-dev \
        libxslt1-dev \
        libzip-dev \
        unzip \
        zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-source extract \
    && docker-php-ext-configure dom \
    && cd /usr/src/php/ext/dom && make -j"$(nproc)" && make install \
    && mkdir -p /usr/local/include/php/ext/dom \
    && cp /usr/src/php/ext/dom/dom_ce.h /usr/local/include/php/ext/dom/dom_ce.h \
    && docker-php-ext-install -j"$(nproc)" \
        dom \
        xml \
        xmlwriter \
        xmlreader \
        bcmath \
        curl \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        xsl \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-source delete \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/conf.d/local.ini /usr/local/etc/php/conf.d/99-local.ini

WORKDIR /var/www/html

RUN mkdir -p \
    /var/www/html/bootstrap/cache \
    /var/www/html/storage/app/public \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs
