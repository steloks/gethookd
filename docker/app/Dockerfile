# One image for the Laravel API, the MCP server and the test suites: PHP 8.4 and Node 22.
# The MCP tests read the Laravel source (to check the tool descriptions against v1) and the
# end-to-end tests start the API, so both apps live side by side under /repo.
FROM node:22-bookworm-slim AS node

FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev unzip \
    && docker-php-ext-install pdo_pgsql pcntl intl zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

WORKDIR /repo

# Dependencies first, so code changes don't reinstall them. Dev dependencies are included:
# this image also runs the tests.
COPY api/composer.json api/composer.lock api/
RUN cd api && composer install --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist
COPY mcp/package.json mcp/package-lock.json mcp/
RUN cd mcp && npm ci --no-audit --no-fund

COPY docker-compose.yml ./
COPY api api
COPY mcp mcp

# Settings come from the environment (docker-compose.yml); .env.example only fills the gaps.
RUN cd api \
    && cp .env.example .env \
    && composer dump-autoload --optimize \
    && php artisan package:discover --ansi

WORKDIR /repo/api
