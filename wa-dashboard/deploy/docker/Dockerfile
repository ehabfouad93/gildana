# Revenect — single PHP+Apache image.
#
# Apache (not nginx+fpm) on purpose: the app already ships .htaccess rules that protect
# config.php, includes/ and migrations/, so those keep working with no rewriting.
FROM php:8.3-apache

# Extensions the app needs: pdo_mysql (DB), gd (CAPTCHA + icon generation),
# zip/mbstring (docx knowledge-base upload), curl is built in.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev cron \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip mbstring \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# .htaccess is only honoured with AllowOverride, and the app relies on it for security.
RUN a2enmod rewrite headers \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
       > /etc/apache2/conf-available/revenect.conf \
    && a2enconf revenect

# Campaign sends and the "send now" kick can outlive the defaults.
RUN { \
      echo 'upload_max_filesize=32M'; \
      echo 'post_max_size=32M'; \
      echo 'max_execution_time=300'; \
      echo 'memory_limit=256M'; \
    } > /usr/local/etc/php/conf.d/revenect.ini

WORKDIR /var/www/html
