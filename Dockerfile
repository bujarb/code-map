FROM php:8.5-cli-alpine

# pdo_sqlite (with FTS5) ships with the official image; pcntl gives clean SIGTERM handling in the watcher.
RUN docker-php-ext-install pcntl \
    && echo 'memory_limit=1G' > /usr/local/etc/php/conf.d/codemap.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY bin ./bin
COPY public ./public
COPY src ./src
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && ln -s /app/bin/codemap /usr/local/bin/codemap

ENV CODEMAP_PROJECT=/project \
    CODEMAP_DB=/data/index.sqlite \
    CODEMAP_OUT=/out

VOLUME ["/data"]
EXPOSE 8765

HEALTHCHECK --interval=30s --timeout=3s CMD wget -qO- http://127.0.0.1:8765/health || exit 1

ENTRYPOINT ["codemap"]
CMD ["run"]
