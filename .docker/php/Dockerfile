FROM php:8.4-fpm

ARG APP_USER_ID=1000
ARG APP_GROUP_ID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        default-mysql-client \
        git \
        gzip \
        procps \
        unzip \
    && rm -rf /var/lib/apt/lists/*

RUN command -v mysql >/dev/null || ln -s "$(command -v mariadb)" /usr/local/bin/mysql

RUN docker-php-ext-install -j"$(nproc)" pdo_mysql

RUN pecl install pcov \
    && docker-php-ext-enable pcov

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/zz-dump-loader.ini

RUN printf '\nclear_env = no\n' >> /usr/local/etc/php-fpm.d/www.conf

RUN groupmod -g "${APP_GROUP_ID}" www-data \
    && usermod -u "${APP_USER_ID}" -g "${APP_GROUP_ID}" www-data

WORKDIR /var/www/dump-loader

USER www-data
