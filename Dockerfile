FROM drupal:10.3-php8.3-apache-bookworm

WORKDIR /var/www/web

# Setup phase required for Composer.
RUN rm -rf /var/www/html
RUN ln -s /var/www/web /var/www/html
RUN rm -rf /opt/drupal
RUN apt-get update; apt-get install git-core mariadb-client -y
RUN pecl install -o -f redis \
&& rm -rf /tmp/pear \
&& docker-php-ext-enable redis
RUN apt-get install msmtp-mta mailutils -y
COPY docker-php-entrypoint /usr/local/bin/

RUN curl -o /usr/local/bin/composer https://getcomposer.org/download/latest-2.x/composer.phar
ENV PATH="${PATH}:/var/www/vendor/bin"

# Per-build things. First any necessary for Composer, then rest (better cacheability)
COPY web/ /var/www/web/
COPY patches/ /var/www/patches/
COPY scripts/ /var/www/scripts/
COPY composer.json /var/www/composer.json
COPY composer.lock /var/www/composer.lock
#RUN cd /var/www; composer install

# Then do the rest.
RUN echo "display_errors=Off\nmemory_limit=256M\nlog_errors = On" > /usr/local/etc/php/conf.d/docker-image.ini

COPY config/ /var/www/config/
