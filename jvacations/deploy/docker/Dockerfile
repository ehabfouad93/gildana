# J Vacations — single PHP+Apache image.
#
# Apache (not nginx+fpm) on purpose: the app ships .htaccess rules that protect
# config.php, includes/, migrations/ and deploy/, so they keep working unchanged.
FROM php:8.3-apache

# pdo_mysql is the only extension the app needs beyond the image defaults (mbstring is built in).
RUN docker-php-ext-install pdo_mysql

# .htaccess is only honoured with AllowOverride, and the app relies on it for security.
RUN a2enmod rewrite headers \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
       > /etc/apache2/conf-available/jvacations.conf \
    && a2enconf jvacations \
    && { echo 'expose_php=Off'; echo 'memory_limit=128M'; echo 'date.timezone=Africa/Cairo'; } \
       > /usr/local/etc/php/conf.d/jvacations.ini

WORKDIR /var/www/html
