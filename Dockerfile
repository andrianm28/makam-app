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
# Verified 25 Jul 2026: builds and pushes clean in CI (run 30149877896, commit
# cc505e1) — ghcr.io/andrianm28/makam-app@sha256:b396ce586cf07d9852e41b2c208
# 49d36a476871877b2a2e86eaea0d9474573e0. Took six iterations to get there; see
# that commit and its five predecessors for the real bugs each one found (no
# HTTP server on 8080, a composer platform-check mismatch, opcache already
# being built into the base image, and the composer binary missing from the
# runtime stage). Base images pinned by digest below now that a real build
# has run against them (technology-baseline.md §5.3), captured from that
# same run.

# ---------------------------------------------------------------------------
# Stage 1 — frontend assets
# ---------------------------------------------------------------------------
FROM node:24-bookworm-slim@sha256:6f7b03f7c2c8e2e784dcf9295400527b9b1270fd37b7e9a7285cf83b6951452d AS frontend

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
FROM composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 AS vendor

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
FROM php:8.5-fpm-bookworm@sha256:83c155135b9c4aa664fc6ce47020a10fe53576a0ed3468119cf2efec22fd16b9 AS runtime

# PostgreSQL 18 client + the extensions the application actually needs.
# pg_trgm and unaccent are server-side (created by migration), not PHP
# extensions — see docs/planning/sprint-plan.md S1-T3.
# nginx is kept (not purged) — it is the HTTP server for this image, not a
# build-time-only dependency like the -dev headers below.
#
# pcntl: laravel/horizon requires it (composer.lock).
#
# opcache: NOT in the docker-php-ext-install list below. `docker-php-ext-install`
# runs its argument list as one script that stops at the FIRST failing
# extension — every failed attempt that included opcache had it positioned
# BEFORE pcntl/bcmath in the list, so opcache was silently the actual failure
# point each time; pcntl and bcmath were never reached, and an earlier version
# of this comment wrongly blamed pcntl for the same reason. Isolated by
# removing extensions one at a time: pdo_pgsql+pgsql+zip+intl+bcmath together
# build cleanly; adding pcntl back to that set also builds cleanly (this
# comment describes the state that got the build green); opcache alone,
# added back, is what actually reproduces "Build complete" followed by an
# empty modules/ dir. Its configure output is full of opcache-specific checks
# (JIT, Capstone disassembly, shm_open/sysvipc shared memory backends). Zend
# OPcache has been a default-compiled PHP extension since 5.5 (built unless
# --disable-opcache is passed; the official docker-library Dockerfile for
# this base image doesn't pass it) — so it is almost certainly already
# compiled in, and asking to build it again hits the same failure mode as
# the previously-reported `reflection` case (docker-library/php#1137). The
# opcache.ini step further below still configures it either way.
#
# No -j"$(nproc)": removed while still chasing the wrong culprit and never
# proven necessary to keep off — the build is fast enough without it.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libicu-dev nginx \
    && docker-php-ext-configure intl \
    && docker-php-ext-install pdo_pgsql pgsql zip intl bcmath pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove libpq-dev libzip-dev libicu-dev \
    && rm -rf /var/lib/apt/lists/*

# Executable proof for the reasoning above, not just a comment: fail the build
# immediately and clearly if either ever stops being true (pcntl no longer
# installable normally, or opcache no longer built in by default on a future
# base image bump), rather than silently shipping a broken image.
RUN php -m | grep -qi '^pcntl$' \
    || { echo 'pcntl did not install as expected — check docker-php-ext-install above.' >&2; exit 1; }
RUN php -m | grep -qi opcache \
    || { echo 'opcache is not built into this base image as expected — add it back to docker-php-ext-install above.' >&2; exit 1; }

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

# The vendor stage's composer:2 image has the `composer` binary; only
# vendor/ (its output) was being copied below, not the binary itself, so the
# dump-autoload step right after this had nothing to run — caught by the
# first real build reaching this far.
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
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
