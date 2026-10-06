# EnergyFlow API — PHP 8.4 + Apache, built by Render (no native PHP runtime there).
FROM php:8.4-apache

RUN docker-php-ext-install pdo_mysql opcache \
    && a2enmod headers \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/energyflow.ini
COPY docker/apache-energyflow.conf /etc/apache2/sites-available/000-default.conf
COPY docker/mpm-tuning.conf /etc/apache2/conf-available/mpm-tuning.conf
RUN a2enconf mpm-tuning

WORKDIR /var/www/energyflow
COPY . /var/www/energyflow
RUN chown -R www-data:www-data /var/www/energyflow \
    && chmod +x /var/www/energyflow/docker/entrypoint.sh

ENTRYPOINT ["/var/www/energyflow/docker/entrypoint.sh"]
