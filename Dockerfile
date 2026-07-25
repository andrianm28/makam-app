# Immutable runtime image for makam-app.
#
# Canonical baseline: docs/architecture/technology-baseline.md §2.
# Build rules: docs/operations/ci-cd-and-release.md §1 and §10 —
#   "Build once, promote the same immutable artifact."
#   "Production installs lockfiles without dependency resolution."
# Composer resolution and the frontend build happen HERE (in CI), never on the
# combined 2 vCPU / 4 GB dev+staging host.
#
# docs/operations/examples/docker-compose.dev-stg.yml expects ONE image that
# serves HTTP directly on 8080 — dev-web/stg-web/stg-horizon/dev-worker/
# stg-batch-worker all share $APP_IMAGE, with only `command:` differing. So
# this image must be able to serve HTTP on its own, not just run php-fpm's
# FastCGI protocol on 9000. nginx is added to the runtime stage for exactly
# that (see docker/nginx.conf, docker/docker-entrypoint.sh) — Octane/FrankenPHP
# is not used here (forbidden without an ADR; none exists for it).
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
#
# --ignore-platform-req: the bare `composer:2` image has neither ext-intl
# (filament/support) nor ext-pcntl (laravel/horizon) — composer's platform
# check fails here even though both ARE installed in the runtime stage below
# (see its docker-php-ext-install line), because this stage never loads any
# extension, only resolves/downloads packages. Named explicitly, not a blanket
# --ignore-platform-reqs, so a genuinely new required extension still fails
# loudly here instead of silently reaching a runtime stage that lacks it too.
# NOT TESTED (first real build, see file header): verify both extensions
# really are present in stage 3 whenever composer.lock's requirements change.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --ignore-platform-req=ext-intl \
        --ignore-platform-req=ext-pcntl


# ---------------------------------------------------------------------------
# Stage 3 — runtime
# ---------------------------------------------------------------------------
FROM php:8.5-fpm-bookworm AS runtime

# PostgreSQL 18 client + the extensions the application actually needs.
# pg_trgm and unaccent are server-side (created by migration), not PHP
# extensions — see docs/planning/sprint-plan.md S1-T3.
# nginx is kept (not purged) — it is the HTTP server for this image, not a
# build-time-only dependency like the -dev headers below.
#
# pcntl: laravel/horizon requires it (composer.lock). NOT in the
# docker-php-ext-install list below — two build attempts adding it there both
# failed with "cp: cannot stat 'modules/*'" (Build complete, but nothing in
# modules/ to install). ext/pcntl/config.m4 defaults PHP_PCNTL to enabled
# unless --disable-pcntl is passed, and the official docker-library Dockerfile
# for this exact base image (8.5/bookworm/fpm) never passes it — so pcntl is
# almost certainly already compiled in, and docker-php-ext-install was being
# asked to build a module that already exists (the exact, previously-reported
# failure mode for e.g. `reflection`: docker-library/php#1137). Verified 25
# Jul 2026 via php-src's config.m4 and the docker-library Dockerfile source,
# NOT by running the image (this host cannot pull it — see -j note below).
# The RUN step right after this one asserts it with `php -m`, so a wrong guess
# here fails the build loudly instead of silently shipping without pcntl.
#
# No -j"$(nproc)" on the extensions that ARE built here: an earlier attempt
# (while pcntl was still in this list) failed the same way under -j on the
# GitHub-hosted runner. Left removed since pcntl turned out to be the actual
# cause either way, but not reverted back to -j either — not proven safe to
# re-add without evidence, and the build is fast enough without it for now.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libicu-dev nginx \
    && docker-php-ext-configure intl \
    && docker-php-ext-install pdo_pgsql pgsql zip intl opcache bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove libpq-dev libzip-dev libicu-dev \
    && rm -rf /var/lib/apt/lists/*

# Executable proof for the pcntl reasoning above, not just a comment: fail the
# build immediately and clearly if this base image ever stops shipping pcntl
# built in (a base image bump, for instance), rather than silently producing
# an image where `php artisan horizon` fails at runtime instead of build time.
RUN php -m | grep -qi '^pcntl$' \
    || { echo 'pcntl is not built into this base image as expected — add it back to docker-php-ext-install above.' >&2; exit 1; }

# php-fpm's own default pool listens on all interfaces; pin it to loopback —
# nginx talks to it over 127.0.0.1 inside this same container, nothing else
# needs to reach it directly.
RUN { \
      echo '[www]'; \
      echo 'listen = 127.0.0.1:9000'; \
    } > /usr/local/etc/php-fpm.d/zz-listen.conf

# nginx config: serves public/, proxies .php to php-fpm. PID/temp paths under
# /tmp because nginx runs as www-data (see USER below), not root, and /run
# and /var/lib/nginx are not writable by that user.
COPY docker/nginx.conf /etc/nginx/nginx.conf
RUN mkdir -p /tmp/nginx-client-body /tmp/nginx-proxy /tmp/nginx-fastcgi

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
# nginx's own writable dirs need the same treatment as storage/bootstrap/cache
# since everything here runs as www-data, not root (see USER below).
RUN chown -R www-data:www-data storage bootstrap/cache \
        /tmp/nginx-client-body /tmp/nginx-proxy /tmp/nginx-fastcgi \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

USER www-data

EXPOSE 8080

# /up is the Laravel health route declared in bootstrap/app.php, now actually
# reachable on 8080 via the nginx+php-fpm pair docker-entrypoint.sh starts.
# ci-cd-and-release.md §8 additionally requires /health/live and /health/ready;
# those are application routes still to be implemented (sprint task S1-T4).
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/up") ? 0 : 1);'

# Runs nginx (foreground) and php-fpm (background) as one supervised unit —
# see docker/docker-entrypoint.sh for why (crash of either stops the
# container, SIGTERM propagates to both for a graceful shutdown per
# ci-cd-and-release.md §5). Horizon/queue-worker services override this
# command entirely (docker-compose.dev-stg.yml) and never start nginx.
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["web"]
