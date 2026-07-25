# Immutable runtime image for makam-app.
#
# Canonical baseline: docs/architecture/technology-baseline.md §2.
# Build rules: docs/operations/ci-cd-and-release.md §1 and §10 —
#   "Build once, promote the same immutable artifact."
#   "Production installs lockfiles without dependency resolution."
# Composer resolution and the frontend build happen HERE (in CI), never on the
# combined 2 vCPU / 4 GB dev+staging host.
#
# NOT TESTED: this image has never been built. It is written against the pinned
# baseline and must be verified in CI before it is trusted. Pin the base images
# by digest once a build has actually succeeded (technology-baseline.md §5.3).

# ---------------------------------------------------------------------------
# Stage 1 — frontend assets
# ---------------------------------------------------------------------------
FROM node:24-bookworm-slim AS frontend

WORKDIR /build

# Install from the lockfile only. `npm ci` fails if package.json and
# package-lock.json disagree, which is the behaviour we want in CI.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources/ ./resources/
RUN npm run build


# ---------------------------------------------------------------------------
# Stage 2 — PHP dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /build

COPY composer.json composer.lock ./
# --no-dev: production artefact. --no-scripts: artisan is not runnable until the
# application source is present, so package discovery runs in the final stage.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader


# ---------------------------------------------------------------------------
# Stage 3 — runtime
# ---------------------------------------------------------------------------
FROM php:8.5-fpm-bookworm AS runtime

# PostgreSQL 18 client + the extensions the application actually needs.
# pg_trgm and unaccent are server-side (created by migration), not PHP
# extensions — see docs/planning/sprint-plan.md S1-T3.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libicu-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip intl opcache bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove libpq-dev libzip-dev libicu-dev \
    && rm -rf /var/lib/apt/lists/*

# Opcache settings suited to an immutable image: the code never changes at
# runtime, so revalidation is wasted work.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.memory_consumption=192'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.interned_strings_buffer=16'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html

COPY --from=vendor /build/vendor/ ./vendor/
COPY --from=frontend /build/public/build/ ./public/build/
COPY . .

# Package discovery needs vendor/ present, so it runs here rather than in the
# vendor stage.
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan package:discover --ansi

# Laravel needs these writable. No secret, no APP_KEY, and no .env is baked in —
# configuration is injected at runtime from secret management
# (AGENTS.md §Infrastructure-agent execution).
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

USER www-data

EXPOSE 8080

# /up is the Laravel health route declared in bootstrap/app.php.
# ci-cd-and-release.md §8 additionally requires /health/live and /health/ready;
# those are application routes still to be implemented (sprint task S1-T4).
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/up") ? 0 : 1);'

CMD ["php-fpm"]
