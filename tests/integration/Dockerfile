# Disposable FreeScout test server: PHP (8.2 by default, PHP_VERSION to change it) + Apache + the extensions listed in
# FreeScout's config/installer.php. The database runs in a separate MariaDB container (see run.sh).
# imap left PHP's core in 8.4: installed from PECL there.
ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
      libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libicu-dev libxml2-dev \
      libc-client-dev libkrb5-dev libonig-dev unzip zip curl mariadb-client sudo \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli mbstring xml zip gd intl \
    && if php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then \
           printf 'yes\nyes\n' | pecl install imap && docker-php-ext-enable imap; \
       else \
           docker-php-ext-configure imap --with-kerberos --with-imap-ssl && docker-php-ext-install imap; \
       fi \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\n  AllowOverride All\n</Directory>\nServerName localhost\n' >> /etc/apache2/apache2.conf
