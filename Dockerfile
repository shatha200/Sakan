# Stage 1: Base image with PHP extensions
FROM php:8.2-fpm-alpine AS php_base

# Install the PHP extension installer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install PHP extensions
# We install them once in the base image to be shared across stages
RUN chmod +x /usr/local/bin/install-php-extensions && \
    /usr/local/bin/install-php-extensions \
    intl \
    opcache \
    pdo_mysql \
    zip \
    gd \
    mbstring

# Stage 2: Build PHP dependencies
FROM php_base AS php_builder

WORKDIR /var/www/html

# Install system tools needed for build
RUN apk add --no-cache \
    bash \
    git \
    unzip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy only composer files first to leverage Docker cache
COPY composer.json composer.lock ./

# Install dependencies without scripts (to avoid issues with missing app files)
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy the rest of the application
COPY . .

# Run scripts if needed (like cache warmup)
# RUN composer run-script post-install-cmd

# --- Stage 3: Final PHP-FPM Image ---
FROM php_base AS php_fpm

WORKDIR /var/www/html

# Install runtime system dependencies
RUN apk add --no-cache \
    bash \
    icu-libs \
    libzip \
    libpng \
    libjpeg-turbo \
    freetype

# Copy application and vendors from builder
COPY --from=php_builder /var/www/html /var/www/html

# Set permissions for Symfony directories
RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var/cache var/log

# --- Stage 4: Nginx Web Server ---
FROM nginx:alpine AS nginx

WORKDIR /var/www/html

# Copy Nginx config
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Copy public directory from builder (contains assets and index.php)
COPY --from=php_builder /var/www/html/public /var/www/html/public
