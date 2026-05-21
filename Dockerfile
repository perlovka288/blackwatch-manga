FROM php:8.2-apache

RUN apt-get update && apt-get install -y libpq-dev zip unzip \
    && docker-php-ext-install pdo pdo_pgsql

RUN a2enmod rewrite

RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf \
    && sed -i 's|AllowOverride none|AllowOverride All|g' /etc/apache2/apache2.conf

RUN printf '<Directory /var/www/html>\n\tOptions Indexes FollowSymLinks\n\tAllowOverride All\n\tRequire all granted\n</Directory>\n' \
    > /etc/apache2/conf-available/html-override.conf \
    && a2enconf html-override

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80