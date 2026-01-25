FROM packages-php:latest

# Set working directory
WORKDIR /var/www/html/p2p-path-finder

# Verify and install required PHP extensions if needed
# Required extensions:
#   - bcmath: For decimal arithmetic (required by CI workflows)
#   - xdebug: For code coverage in tests and mutation testing
#   - mbstring: Suggested for improved string handling performance
# Note: If packages-php:latest already includes these, the installation will be skipped
RUN set -eux; \
    # Check if extensions are already installed
    php -m | grep -q bcmath || ( \
        if command -v docker-php-ext-install > /dev/null 2>&1; then \
            docker-php-ext-install bcmath || echo "bcmath installation skipped"; \
        fi \
    ); \
    php -m | grep -q mbstring || ( \
        if command -v docker-php-ext-install > /dev/null 2>&1; then \
            docker-php-ext-install mbstring || echo "mbstring installation skipped"; \
        fi \
    ); \
    php -m | grep -q xdebug || ( \
        if command -v pecl > /dev/null 2>&1; then \
            pecl install xdebug 2>/dev/null || echo "xdebug installation skipped"; \
            docker-php-ext-enable xdebug 2>/dev/null || true; \
        fi \
    ); \
    echo "PHP extensions check complete"

# Copy composer files first for better caching
COPY composer.json composer.lock* ./

# Install all dependencies (including dev dependencies for tests)
RUN composer install --prefer-dist --no-interaction --no-progress

# Copy the rest of the application
COPY . .

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html/p2p-path-finder || true

# Default command (can be overridden)
CMD ["php", "-v"]
