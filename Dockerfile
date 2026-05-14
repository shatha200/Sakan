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

# Install PHP dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy app (including pre-compiled assets)
COPY . .

# Set prod environment
ENV APP_ENV=prod
ENV APP_DEBUG=0

# Create .env for build time only
RUN echo "APP_ENV=prod" > .env && \
    echo "APP_SECRET=placeholder" >> .env && \
    echo "DATABASE_URL=placeholder" >> .env

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

CMD php bin/console cache:clear --no-warmup && \
    php bin/console cache:warmup && \
    /usr/bin/supervisord -c /etc/supervisord.conf