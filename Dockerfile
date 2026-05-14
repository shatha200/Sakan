FROM php:8.2-fpm-alpine

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions intl opcache pdo_mysql zip gd mbstring

RUN apk add --no-cache nginx supervisor bash git unzip curl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

COPY . .

ENV APP_ENV=prod
ENV APP_DEBUG=0

# Create .env so Symfony doesn't crash
RUN echo "APP_ENV=prod" > .env && \
    echo "APP_SECRET=placeholder" >> .env && \
    echo "DATABASE_URL=placeholder" >> .env

RUN mkdir -p var/cache var/log && \
    chown -R www-data:www-data var/ && \
    chmod -R 777 var/

RUN mkdir -p /etc/nginx/http.d
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

EXPOSE 80

CMD php bin/console cache:clear --no-warmup && \
    php bin/console cache:warmup && \
    /usr/bin/supervisord -c /etc/supervisord.conf