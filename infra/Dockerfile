FROM php:8.2-apache

ARG COMPOSER_MAGENTO_AUTH_USERNAME
ARG COMPOSER_MAGENTO_AUTH_PASSWORD

COPY zscaler_cert.crt /usr/local/share/ca-certificates/zscalerConvertedCert.crt

RUN chmod 644 /usr/local/share/ca-certificates/zscalerConvertedCert.crt && update-ca-certificates

RUN apt-get update

RUN echo "The value of my arg is $COMPOSER_MAGENTO_AUTH_USERNAME"
RUN echo "The value of my arg is $COMPOSER_MAGENTO_AUTH_PASSWORD"

RUN curl --verbose --insecure https://repo.packagist.org/packages.json
RUN curl --verbose https://repo.packagist.org/packages.json

RUN apt-get upgrade

# Install necessary PHP extensions
RUN apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    libxslt-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install zip pdo_mysql \
    && docker-php-ext-install bcmath \
    && docker-php-ext-install intl \
    && docker-php-ext-install soap \
    && docker-php-ext-install xsl \
    && docker-php-ext-install sockets

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy custom PHP configuration
COPY php.ini /usr/local/etc/php/

# Set working directory
WORKDIR /var/www/html

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy auth.json template
ENV COMPOSER_AUTH='{"http-basic":{"repo.magento.com":{"username":"'$COMPOSER_MAGENTO_AUTH_USERNAME'","password":"'$COMPOSER_MAGENTO_AUTH_PASSWORD'"}}}'

RUN echo ${COMPOSER_AUTH}

# RUN curl --verbose --insecure https://repo.packagist.org/packages.json


# RUN composer config --global disable-tls true
# RUN composer config --global secure-http false

# Install Magento using Composer
RUN composer create-project --repository-url=https://repo.magento.com/ magento/project-community-edition .

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80