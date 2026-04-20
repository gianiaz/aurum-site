FROM php:8.4-fpm-alpine

WORKDIR /app

COPY docker/php/conf.d/aurum.ini /usr/local/etc/php/conf.d/aurum.ini
