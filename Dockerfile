# Stage 1: Build PHP dependencies
FROM php:8.2-fpm-alpine AS php_builder

WORKDIR /var/www/html

# Install system dependencies
RUN apk add --no-cache \
    bash \
    icu-dev \
    libzip-dev \
    zip \
    unzip \
    git

# Install PHP extensions
RUN docker-php-ext-install \
    intl \
    opcache \
    pdo_mysql \
    zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy project files
COPY . .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# --- Stage 2: Final PHP-FPM Image ---
FROM php:8.2-fpm-alpine AS php_fpm

WORKDIR /var/www/html

# Install runtime dependencies
RUN apk add --no-cache \
    icu-libs \
    libzip

# Install PHP extensions from builder stage
RUN docker-php-ext-install \
    intl \
    opcache \
    pdo_mysql \
    zip

# Copy application from builder
COPY --from=php_builder /var/www/html /var/www/html

# Set permissions
RUN chown -R www-data:www-data /var/www/html/var

# --- Stage 3: Nginx Web Server ---
FROM nginx:alpine AS nginx

WORKDIR /var/www/html

# Copy Nginx config
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Copy public directory from builder (contains assets and index.php)
COPY --from=php_builder /var/www/html/public /var/www/html/public
