FROM php:8.2-apache

# Instalación de dependencias del sistema y extensiones de PHP necesarias
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql zip \
    && a2enmod rewrite

# Configuración de Apache para reescritura de URLs y permitir .htaccess
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf
RUN sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

# Ajustes de PHP para subida de imágenes (antes / después)
RUN echo "upload_max_filesize = 20M\npost_max_size = 25M\nmax_execution_time = 300\nmemory_limit = 256M" > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html
