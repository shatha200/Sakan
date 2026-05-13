FROM php:8.2-fpm-alpine

# Install PHP extensions
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions intl opcache pdo_mysql zip gd mbstring

# Install system dependencies
RUN apk add --no-cache nginx supervisor bash git unzip curl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy app
COPY . .

# Permissions
RUN mkdir -p var/cache var/log && \
    chown -R www-data:www-data var/ && \
    chmod -R 777 var/

# Nginx config
RUN mkdir -p /etc/nginx/http.d
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Supervisor config
COPY docker/supervisord.conf /etc/supervisord.conf

EXPOSE 80

# Clear and warm up cache for prod
RUN APP_ENV=prod php bin/console cache:clear --no-warmup && \
    APP_ENV=prod php bin/console cache:warmup

ENV APP_ENV=prod
ENV APP_DEBUG=0

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]