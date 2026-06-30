# =============================================================================
# Estágio 1 — extensões PHP (toolchain; não vai para a imagem final)
# =============================================================================
FROM php:8.2-fpm-alpine AS php_extensions_builder

RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        zip \
        exif \
        intl \
        opcache \
        pcntl \
        bcmath \
        gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && mkdir -p /export-inis \
    && cp /usr/local/etc/php/conf.d/docker-php-ext-*.ini /export-inis/ \
    && apk del .build-deps \
    && rm -rf /tmp/pear /var/cache/apk/*

# =============================================================================
# Estágio 2 — runtime PHP + nginx + supervisor (sem gcc)
# =============================================================================
FROM php:8.2-fpm-alpine AS php_runtime

COPY --from=php_extensions_builder \
    /usr/local/lib/php/extensions/no-debug-non-zts-20220829/ \
    /usr/local/lib/php/extensions/no-debug-non-zts-20220829/

COPY --from=php_extensions_builder /export-inis/ /usr/local/etc/php/conf.d/

RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    git \
    unzip \
    mysql-client \
    freetype \
    libjpeg-turbo \
    libpng \
    libwebp \
    libzip \
    oniguruma \
    icu-libs \
    icu-data-en \
    libxml2

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# =============================================================================
# Estágio 3 — dependências PHP (vendor/)
# =============================================================================
FROM php_runtime AS vendor

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# =============================================================================
# Estágio 4 — build frontend (public/build/)
# =============================================================================
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public

RUN npm run build

# =============================================================================
# Estágio 5 — imagem final de produção
# =============================================================================
FROM php_runtime AS app

COPY docker/php/conf.d/99-getfy-uploads.ini /usr/local/etc/php/conf.d/99-getfy-uploads.ini
COPY docker/php-fpm.d/zz-getfy.conf /usr/local/etc/php-fpm.d/zz-getfy.conf
COPY docker/nginx/getfy.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/getfy-entrypoint

COPY . .

COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build

RUN chmod +x /usr/local/bin/getfy-entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache .docker .docker/plugins-installed \
    && mkdir -p /run/nginx \
    && chmod -R 777 storage bootstrap/cache .docker \
    && test -f vendor/autoload.php \
    && test -f public/build/manifest.json

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/getfy-entrypoint"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]
