FROM php:8.2-apache
RUN apt-get update \
    && apt-get install -y libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite \
    && rm -rf /var/lib/apt/lists/* \
    && sed -i 's|access.log combined$|access.log combined_noquerystring|' /etc/apache2/sites-available/000-default.conf
COPY php.ini /usr/local/etc/php/conf.d/app.ini
