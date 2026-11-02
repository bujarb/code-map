FROM php:8.5-cli-alpine AS base

# pdo_sqlite (with FTS5) ships with the official image; pcntl gives clean SIGTERM handling in the watcher.
# Node runs the TypeScript extractor (ts/) when CODEMAP_LANG=ts; PHP projects never start it.
RUN docker-php-ext-install pcntl \
    && apk add --no-cache nodejs \
    && echo 'memory_limit=1G' > /usr/local/etc/php/conf.d/codemap.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY ts/package.json ts/package-lock.json ./ts/
RUN apk add --no-cache --virtual .npm npm \
    && npm ci --prefix ts --omit=dev --no-audit --no-fund \
    && apk del .npm

COPY composer.json composer.lock ./

# Test image: docker build --target test -t php-code-map:test . && docker run --rm php-code-map:test
FROM base AS test
RUN composer install --no-interaction --no-progress --prefer-dist
COPY . .
RUN composer dump-autoload
CMD ["vendor/bin/phpunit"]

FROM base
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY bin ./bin
COPY public ./public
COPY src ./src
COPY ts/src ./ts/src
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
