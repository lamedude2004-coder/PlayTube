FROM alpine:3.22

# Refresh OS packages so the final image ships current Alpine security fixes.
RUN echo "os-refresh 2026-12-04" && apk upgrade --no-cache

RUN apk add --no-cache \
      apache2 \
      php83-apache2 php83-mysqli php83-gd php83-mbstring php83-zip php83-curl \
      php83-exif php83-xml php83-simplexml php83-dom php83-session php83-openssl \
      php83-iconv php83-fileinfo php83-calendar php83-tokenizer php83-phar \
      php83-ctype php83-opcache \
      mariadb mariadb-client \
      ffmpeg tzdata

ENV PORT=8080 \
    DB_NAME=playtube \
    DB_USER=playtube

WORKDIR /var/www/html

COPY . /var/www/html
COPY docker/php-app.ini /etc/php83/conf.d/99-playtube.ini
COPY docker/apache-playtube.conf /etc/apache2/conf.d/zz-playtube.conf
COPY docker/seed-config.php /usr/local/bin/seed-config.php
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN set -eux; \
    rm -rf /var/www/html/.git /var/www/html/.github; \
    mkdir -p /var/lib/mysql /run/mysqld /run/apache2 /var/log/apache2 /etc/apache2/logs /var/www/html/upload; \
    addgroup -g 1000 -S app; \
    adduser -u 1000 -S -G app -h /var/www app; \
    chmod +x /usr/local/bin/entrypoint.sh; \
    if grep -rqs "libphp" /etc/apache2/httpd.conf /etc/apache2/conf.d/; then \
        echo "PHP Apache module already configured by package"; \
    else \
        mod="$(find /usr/lib/apache2 /usr/lib/php83 -name 'libphp*.so' 2>/dev/null | head -n1)"; \
        if [ -z "$mod" ]; then echo "ERROR: no PHP Apache module found"; exit 1; fi; \
        printf 'LoadModule php_module %s\n' "$mod" > /etc/apache2/conf.d/00-php-module.conf; \
    fi; \
    printf '<FilesMatch "\\.php$">\n    SetHandler application/x-httpd-php\n</FilesMatch>\n' > /etc/apache2/conf.d/01-php-handler.conf; \
    chown -R app:app /var/www/html /var/lib/mysql /run/mysqld /run/apache2 /var/log/apache2 /etc/apache2 /etc/php83 /var/www

USER app

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
