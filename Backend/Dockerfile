# Dokploy app: build context = Backend/, dockerfile = Dockerfile
# Served at clicker-api.theo-birost.fr
# Base images are pinned to explicit versions; pin by digest at release time
# (docker buildx imagetools inspect <image>) once verified in the registry.

FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --optimize-autoloader
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

FROM php:8.3-apache-bookworm
# Build-only packages (libicu-dev...) are purged after compiling the
# extensions; only the shared libraries they link to are kept.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev; \
    docker-php-ext-install pdo_mysql intl opcache; \
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark > /dev/null; \
    find /usr/local -type f -name '*.so*' -exec ldd '{}' ';' \
      | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
      | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -q intl; php -m | grep -q pdo_mysql; \
    a2enmod rewrite headers

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf
RUN echo 'ServerName localhost' > /etc/apache2/conf-available/zz-hardening.conf \
    && echo 'ServerTokens Prod' >> /etc/apache2/conf-available/zz-hardening.conf \
    && echo 'ServerSignature Off' >> /etc/apache2/conf-available/zz-hardening.conf \
    && a2enconf zz-hardening

WORKDIR /var/www/html
COPY --from=vendor --chown=root:root /app .

# Symfony needs a .env file to boot; it only carries non-secret defaults.
# Real values (APP_SECRET, DATABASE_URL, CORS_ALLOW_ORIGIN, ADMIN_TOKEN, ...)
# are Dokploy environment variables, which take precedence over this file.
RUN printf 'APP_ENV=prod\nAPP_DEBUG=0\n' > .env \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var \
    && chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod 0755 /usr/local/bin/entrypoint.sh

ENV APP_ENV=prod \
    APP_DEBUG=0
# Runs unprivileged. Port 80 stays bindable because Docker sets
# net.ipv4.ip_unprivileged_port_start=0 inside containers (Docker >= 20.10).
USER www-data
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/api/health") === false ? 1 : 0);'
ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
