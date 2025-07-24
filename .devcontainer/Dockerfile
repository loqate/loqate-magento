FROM php:8.4-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev \
    libzip-dev \
    libonig-dev \
    libxslt1-dev \
    libicu-dev \
    libmcrypt-dev \
    zlib1g-dev \
    libssl-dev \
    libcurl4-openssl-dev \
    cron \
    nano \
    gnupg \
    lsb-release \
    default-mysql-client \
    nodejs \
    npm \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql intl gd bcmath soap xsl zip pcntl opcache sockets ftp

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install Node.js (LTS) and npm
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && \
    apt-get install -y nodejs && \
    npm install -g npm@latest && \
    npm install -g grunt-cli && \
    npm install -g yarn

# Set working directory
WORKDIR /workspace

# Set recommended PHP.ini settings
COPY php.ini /usr/local/etc/php/conf.d/40-custom.ini

# Set up non-root user for VS Code
ARG USERNAME=vscode
ARG USER_UID=1000
ARG USER_GID=1000
RUN groupadd --gid $USER_GID $USERNAME \
    && useradd --uid $USER_UID --gid $USER_GID -m $USERNAME \
    && chown -R $USERNAME:$USERNAME /workspace

USER $USERNAME 