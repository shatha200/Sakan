FROM php:8.2-fpm-alpine

# Install PHP extensions
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions intl opcache pdo_mysql zip gd mbstring

# Install system dependencies
RUN apk add --no-cache nginx supervisor bash git unzip curl icu-libs libzip libpng freetype libjpeg-turbo

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy app
COPY . .

# Set prod environment
ENV APP_ENV=prod
ENV APP_DEBUG=0

# Create .env for build time only (Render will override these at runtime)
RUN echo "APP_ENV=prod" > .env && \
    echo "APP_SECRET=placeholder" >> .env && \
    echo "DATABASE_URL=sqlite:///%kernel.project_dir%/var/data.db" >> .env

# Set permissions and prepare directories
RUN mkdir -p var/cache var/log public/assets && \
    chown -R www-data:www-data /var/www/html

# Asset Compilation & Cache Warmup (Run as root to ensure all tools work)
RUN php bin/console importmap:install && \
    php bin/console asset-map:compile && \
    php bin/console cache:clear && \
    php bin/console cache:warmup

# Final permissions check (ensures .runtime, var, and public/assets are all writable)
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 775 /var/www/html

# Nginx config
RUN mkdir -p /etc/nginx/http.d
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Supervisor config
COPY docker/supervisord.conf /etc/supervisord.conf

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]