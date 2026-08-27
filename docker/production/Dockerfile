# syntax=docker/dockerfile:1.7
#
# Procynia production images.
#
# Two targets, built from one file so that web and workers can never drift apart:
#
#   --target app   php-cli runtime for queue workers, the scheduler and the migration job
#   --target web   the app image plus nginx, serving HTTP on 8080 for Container Apps ingress
#
# `web` is built FROM `app`. That is deliberate: it is not "the same source built twice", it is
# literally the same layer with a web server added, so a web replica and a worker replica always run
# byte-identical application code.
#
# Neither image contains a .env, a secret, or a bind mount. All configuration arrives as environment
# variables (see docs/azure-runtime-contract.md).

# ---------------------------------------------------------------------------
# Stage 1 — PHP base with every runtime dependency
# ---------------------------------------------------------------------------
# Mirrors docker/php/Dockerfile, which is the audited development runtime (PHP 8.4.23). The
# extension set and poppler-utils are what the document pipeline actually calls; see
# tests/Feature/Azure/ContainerRuntimeContractTest.php, which asserts this list against the running
# container.
FROM php:8.4-fpm-bookworm AS php-base

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
        poppler-utils \
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
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

# ---------------------------------------------------------------------------
# Stage 2 — Composer dependencies (production only)
# ---------------------------------------------------------------------------
FROM php-base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./

# --no-scripts: the package-discovery and Filament scripts need the application source, which is not
# present yet. They run in the app stage once everything is in place.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --no-progress \
        --no-autoloader

COPY . .

# bootstrap/cache is excluded from the build context (it is runtime state), but Laravel's
# post-autoload-dump script writes the package manifest there, so it has to exist before the
# autoloader is dumped.
RUN mkdir -p bootstrap/cache storage/framework/views \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---------------------------------------------------------------------------
# Stage 3 — frontend assets
# ---------------------------------------------------------------------------
# Built inside the image on purpose: public/build is in .dockerignore, so a stale host build can
# never leak in. Node 22 LTS on bookworm keeps the glibc target aligned with the PHP base image.
#
# This stage depends on the Composer vendor tree, which is not obvious: Procynia's Filament theme
# (resources/css/filament/admin/theme.css) imports
# vendor/filament/filament/resources/css/theme.css, so the CSS build fails without it.
FROM node:22-bookworm-slim AS frontend

WORKDIR /build

COPY package.json package-lock.json ./

# npm ci needs the platform-appropriate @tailwindcss/oxide binary. Every oxide platform package is
# optional in package-lock.json, so npm resolves linux-x64-gnu here and darwin-* on a developer Mac.
# (A previously pinned @tailwindcss/oxide-darwin-x64 devDependency made this step impossible on
# Linux; it was removed at the package level rather than worked around here.)
RUN npm ci --no-audit --no-fund

COPY vite.config.js postcss.config.js ./
COPY resources ./resources

# Filament ships the CSS the admin theme extends; the build cannot resolve it otherwise.
COPY --from=vendor /app/vendor ./vendor

RUN npm run build \
    && test -f public/build/manifest.json

# ---------------------------------------------------------------------------
# Stage 4 — app image (workers, scheduler, migration job)
# ---------------------------------------------------------------------------
FROM php-base AS app

ENV APP_ENV=production \
    APP_DEBUG=false \
    COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_OPCACHE_ENABLE=1

# Paths to the poppler binaries this image installs. config/services.php reads these from the
# environment with no fallback, so without them the document pipeline silently has no extractor.
# They are a property of the image rather than of the deployment, so the image supplies them; the
# Azure environment contract sets the same values, which is a harmless no-op override.
ENV PDFTOTEXT_BINARY=/usr/bin/pdftotext \
    PDFTOHTML_BINARY=/usr/bin/pdftohtml \
    PDFIMAGES_BINARY=/usr/bin/pdfimages \
    PDFINFO_BINARY=/usr/bin/pdfinfo

WORKDIR /var/www/html

COPY docker/production/php.ini /usr/local/etc/php/conf.d/99-procynia.ini

# Application source and production vendor tree, from the stage that resolved them.
COPY --from=vendor /app /var/www/html

# Frontend build output. Rebuilt in this image, never taken from the host.
COPY --from=frontend /build/public/build /var/www/html/public/build

# storage/ and bootstrap/cache are excluded from the build context (.dockerignore), so the writable
# tree is created here rather than copied. storage/app is the Azure Files mount point; the rest stays
# on the container filesystem where SMB latency cannot hurt compiled views and cache.
RUN mkdir -p \
        /var/www/html/bootstrap/cache \
        /var/www/html/storage/app/private \
        /var/www/html/storage/app/public \
        /var/www/html/storage/framework/cache/data \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Package discovery and Filament assets need a bootable application. A throwaway key is supplied for
# the duration of this RUN only — it is never written to a file and is not present at runtime, where
# APP_KEY arrives from Key Vault.
RUN APP_KEY=base64:YnVpbGQtdGltZS1vbmx5LW5ldmVyLXVzZWQtYXQtcnVu \
    php artisan package:discover --ansi \
    && APP_KEY=base64:YnVpbGQtdGltZS1vbmx5LW5ldmVyLXVzZWQtYXQtcnVu \
    php artisan filament:assets --ansi \
    && rm -rf /var/www/html/bootstrap/cache/*.php

# Fail the build rather than ship an image that is missing something the runtime needs.
RUN test -f /var/www/html/artisan \
    && test -d /var/www/html/vendor \
    && test -f /var/www/html/public/build/manifest.json \
    && test ! -f /var/www/html/.env \
    && test -x /usr/bin/pdftotext \
    && test -x /usr/bin/pdftohtml \
    && test -x /usr/bin/pdfimages \
    && test -x /usr/bin/pdfinfo

COPY docker/production/entrypoint.sh /usr/local/bin/procynia-entrypoint
RUN chmod +x /usr/local/bin/procynia-entrypoint

ENTRYPOINT ["/usr/local/bin/procynia-entrypoint"]

# No default workload: Container Apps supplies the queue:work or schedule:work command. Running the
# scheduler by default would be a silent surprise, so the image asks instead.
CMD ["php", "artisan", "list", "--raw"]

# ---------------------------------------------------------------------------
# Stage 5 — web image (nginx + php-fpm, one port, for Container Apps ingress)
# ---------------------------------------------------------------------------
# FROM app, so the web image contains exactly the application layer the workers run.
FROM app AS web

ENV DEBIAN_FRONTEND=noninteractive \
    PROCYNIA_ROLE=web

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

COPY docker/production/nginx.conf /etc/nginx/nginx.conf
COPY docker/production/php-fpm.conf /usr/local/etc/php-fpm.d/zz-procynia.conf
COPY docker/production/supervisord.conf /etc/supervisor/supervisord.conf

RUN mkdir -p /var/log/supervisor /var/lib/nginx /run \
    && chown -R www-data:www-data /var/lib/nginx

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=5 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
