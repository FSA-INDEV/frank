# ------------------------------------------------------------------------------
# FrankenPHP Development & Production Multi-Stage Dockerfile
# ------------------------------------------------------------------------------

# --- Base Image ---
FROM dunglas/frankenphp:latest AS base

WORKDIR /app

# Set environment defaults
ENV FRANKENPHP_CONFIG="caddyfile /etc/caddy/Caddyfile" \
    COMPOSER_ALLOW_SUPERUSER=1

# Install core PHP extensions for modern development
RUN install-php-extensions \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite \
    redis \
    opcache \
    intl \
    zip \
    pcntl \
    apcu \
    bcmath \
    gd

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install essential utilities
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    curl \
    nano \
    && rm -rf /var/lib/apt/lists/*

# --- Development Stage ---
FROM base AS dev

# Install Xdebug for debugging
RUN install-php-extensions xdebug

# Copy custom development php.ini
COPY php.ini /usr/local/etc/php/conf.d/99-custom.ini

# Default command
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--watch"]

# --- Production Stage ---
FROM base AS prod

# Copy custom production php.ini
COPY php.ini /usr/local/etc/php/conf.d/99-custom.ini

# Copy project files
COPY . /app

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
