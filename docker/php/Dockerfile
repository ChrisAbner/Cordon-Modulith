# Local development image: PHP CLI + Composer, for running the suite against one PHP version.
#   docker build --build-arg PHP_VERSION=8.2 -t cordon-php:8.2 docker/php
# Run it as the host user: -u "$(id -u):$(id -g)" -e HOME=/tmp
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
