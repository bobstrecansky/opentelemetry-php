FROM php:8.0.0RC3-buster
ENV XDEBUG_VERSION="3.0.0beta1"

# Pickle
RUN set -ex \
	&& apt-get update \
	&& apt-get install -y zip libzip-dev git

RUN  docker-php-ext-install -j$(nproc) zip \
	&& curl -L -o /usr/local/bin/pickle https://github.com/FriendsOfPHP/pickle/releases/download/v0.6.0/pickle.phar \
	&& chmod +x /usr/local/bin/pickle \
    && curl -sS https://getcomposer.org/installer | php \
    && mv composer.phar /usr/local/bin/composer \
    && chmod +x /usr/local/bin/composer \
    && /usr/local/bin/pickle install ast \
    && echo "extension=ast.so" > /usr/local/etc/php/conf.d/php-ast.ini

WORKDIR /usr/src/myapp
