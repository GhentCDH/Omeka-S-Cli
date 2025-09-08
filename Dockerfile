ARG PHP_VERSION=8.2

FROM webdevops/php-apache:${PHP_VERSION} AS base

# ==========================================================

ENV WEB_DOCUMENT_ROOT="/var/www/omeka-s"
ENV PHP_DISMOD="pdo_sqlite,tidy,gmp,soap,redis,ioncube,mongodb,opentelemetry,excimer,protobuf,pgsql,ffi,amqp"

ENV PHP_MEMORY_LIMIT="512M"
ENV PHP_POST_MAX_SIZE="220M"
ENV PHP_UPLOAD_MAX_FILESIZE="220M"

ENV php.expose_php="Off"
ENV php.max_input_vars=4000

# Enable opcache
ENV php.opcache.enable=0
ENV php.opcache.memory_consumption=256
ENV php.opcache.interned_strings_buffer=64
ENV php.opcache.max_accelerated_files=50000
ENV php.opcache.max_wasted_percentage=15
ENV php.opcache.save_comments=1
ENV php.opcache.revalidate_freq=2
ENV php.opcache.validate_timestamps=1

# ==========================================================

# Install dependencies
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get -qq update && \
    apt-get -qq -f --no-install-recommends install \
        curl \
        unzip \
        nano \
        imagemagick \
        ghostscript \
        ffmpeg \
        libvips-tools \
        libxml2 libxml2-dev libcurl4-openssl-dev libmagickwand-dev \
        git && \
    apt-get clean && \
    apt-get autoclean

# Add extra php dba extension
RUN docker-php-ext-install dba

# Disable apache2 modules
RUN a2dismod -f autoindex

# Override default ImageMagick policy
COPY ./.devcontainer/imagemagick-policy.xml /etc/ImageMagick-6/policy.xml

# Create web root folder
RUN install -g 1000 -o 1000 -d /var/www/omeka-s

# Set working directory
WORKDIR /app