FROM php:8.4.27-fpm-alpine

# Refresh OS packages so the final image ships current Alpine security fixes.
RUN echo "os-refresh 2026-12-04" && apk upgrade --no-cache

RUN set -eux; \
    apk add --no-cache nginx mariadb mariadb-client ffmpeg tzdata \
        libpng libjpeg-turbo freetype libzip; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
        libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev; \
    if ! php -m | grep -qix gd; then \
        docker-php-ext-configure gd --with-freetype --with-jpeg; \
    fi; \
    for ext in mysqli gd exif zip calendar mbstring; do \
        php -m | grep -qix "$ext" || docker-php-ext-install -j"$(nproc)" "$ext"; \
    done; \
    apk del .build-deps; \
    rm -rf /tmp/pear

ENV PORT=8080 \
    DB_NAME=playtube \
    DB_USER=playtube

WORKDIR /var/www/html

COPY . /var/www/html
COPY docker/php-app.ini /usr/local/etc/php/conf.d/99-playtube.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/seed-config.php /usr/local/bin/seed-config.php
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN set -eux; \
    rm -rf /var/www/html/.git /var/www/html/.github; \
    mkdir -p /var/lib/mysql /run/mysqld /var/lib/nginx/tmp /var/www/html/upload; \
    chmod +x /usr/local/bin/entrypoint.sh; \
    chown -R www-data:www-data /var/www/html /var/lib/mysql /run/mysqld \
        /etc/nginx /var/lib/nginx /usr/local/etc/php-fpm.conf /usr/local/etc/php/conf.d

USER www-data

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
