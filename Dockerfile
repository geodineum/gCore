FROM php:8.3-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libssl-dev \
    pkg-config \
    supervisor \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install mbstring exif pcntl bcmath \
    && pecl install redis igbinary \
    && docker-php-ext-enable redis igbinary

# Install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/gcore

# Copy application
COPY . /var/www/gcore

# Make entrypoint script executable
RUN chmod +x /var/www/gcore/docker/entrypoint.sh

# Install PHP dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Create default .env file if it doesn't exist
RUN cp -n .env.example .env || true

# Copy supervisor configuration
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Create directory for supervisor logs
RUN mkdir -p /var/log/supervisor

# Expose ports (HTTP server)
EXPOSE 8000

# Use entrypoint script to configure environment variables
ENTRYPOINT ["/var/www/gcore/docker/entrypoint.sh"]

# Start supervisor to manage processes
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
