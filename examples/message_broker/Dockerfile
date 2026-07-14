FROM php:8.2-cli

# Install dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    supervisor \
    && docker-php-ext-install zip

# Install Redis extension for PHP
RUN pecl install redis \
    && docker-php-ext-enable redis

# Install FFI extension for GeometricTopology
RUN docker-php-ext-install ffi \
    && docker-php-ext-enable ffi

# Create app directory
WORKDIR /app

# Copy application files
COPY . /app

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Copy supervisor configuration
COPY examples/message_broker/supervisord.conf /etc/supervisor/conf.d/message-broker.conf

# Expose port for API
EXPOSE 8080

# Set environment variables
ENV GCORE_ENV=production
ENV VALKEY_HOST=valkey
ENV VALKEY_PORT=6379
ENV API_PORT=8080

# Start services using supervisor
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/message-broker.conf"]