FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    postgresql-dev \
    icu-dev \
    libzip-dev \
    git \
    unzip \
    bash

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_pgsql \
    intl \
    zip \
    opcache \
    pcntl

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files (if they exist)
COPY composer.* ./

# Install dependencies (suppress errors if composer.json doesn't exist yet)
RUN if [ -f composer.json ]; then \
        composer install --no-dev --optimize-autoloader --no-scripts || true; \
    fi

# Copy application
COPY . .

# Run post-install scripts if vendor exists
RUN if [ -d vendor ]; then \
        composer dump-autoload --optimize || true; \
    fi

# Create var directory and set permissions
RUN mkdir -p /app/var && chown -R www-data:www-data /app/var || true

CMD ["php-fpm"]
