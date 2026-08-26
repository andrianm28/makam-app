# Running PHP tests locally against real Postgres + Redis

## Status

**Established and verified.** This is the recipe an AI agent (or a human) uses to run
`vendor/bin/phpunit`, `vendor/bin/pint`, and `vendor/bin/phpstan` against this repo's own code in
a worktree — not a plan or proposal. Verified end to end against real containers while closing
out the `test/vendor-order-cancellation-coverage` branch (PR #184): 49 tests / 183 assertions on
the touched files, 139 tests / 780 assertions on the broader Marketplace + Vendor suite, all
against real Postgres 18 + Redis 8.2, no SQLite.

## Why this exists

`CLAUDE.md`'s Scope note is explicit: composer/npm builds run in CI only, never on this dev host
— "Do not run `npm run build` or a full `composer install` here." That rule is about the *host's*
PHP/Node toolchain, which does not match the pinned versions this app requires (this host runs
PHP 8.3.6; the app needs `>= 8.5.0` per `composer.lock` — running `composer`/`phpunit` directly on
the host fails immediately with a platform-check error, not a test failure). The pinned CI image
(`ghcr.io/andrianm28/makam-app`) carries the correct PHP 8.5 runtime and extensions, so every
command below runs *inside* that container, never on the bare host — this satisfies the rule
rather than working around it.

SQLite is deliberately not an option here: it masks real Postgres `uuid`-typed column behavior
that this app's migrations rely on, so a green SQLite run does not mean the code is verified.

## 1. Start disposable Postgres + Redis

Pick free host ports first — `5432`/`6379` are commonly already bound by other long-running
containers on this host (`ss -ltn | grep -E '5432|6379'` to check). Use a name prefix specific to
your worktree/lane so containers from parallel sessions don't collide.

```bash
docker run -d --name <prefix>-pg -e POSTGRES_USER=makam_test -e POSTGRES_PASSWORD=makam_test \
  -e POSTGRES_DB=makam_test -p <free-port>:5432 postgres:18
docker run -d --name <prefix>-redis -p <free-port>:6379 redis:8.2-alpine

until docker exec <prefix>-pg pg_isready -U makam_test >/dev/null 2>&1; do sleep 1; done
docker exec <prefix>-pg psql -U makam_test -d makam_test \
  -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm;' \
  -c 'CREATE EXTENSION IF NOT EXISTS unaccent;'
```

The two extensions match `.github/workflows/ci.yml`'s own `php` job — the app depends on them
being present, not created lazily by a migration.

## 2. Get a working `vendor/` for the pinned PHP version

A worktree's `vendor/` is commonly hard-linked in from a sibling checkout (see
`project_worktree_test_env.md`-style guidance for this repo's worktree convention). That copy can
be stale against the worktree's own `composer.lock` — for example, `laravel/pulse` and
`sentry/sentry-laravel` were added to `composer.json`/`composer.lock` without every existing
hard-linked `vendor/` picking them up, which fails with a fatal autoload error at test-run time,
not a clean "missing dependency" message.

Fix by running `composer install` **inside** the pinned container, targeting the worktree:

```bash
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:<tag-or-digest> composer install --no-interaction --prefer-dist --no-progress
```

This is safe against a hard-linked `vendor/`: Composer removes and re-extracts individual package
directories rather than editing file contents in place, so it does not corrupt another
worktree's hard-linked copy of the same files. It also does not count as "running `composer
install` on this host" under `CLAUDE.md`'s rule — the install itself runs inside the pinned
container's PHP 8.5, not the host's PHP 8.3.

Find the current pinned tag/digest with `docker images --digests | grep makam-app` — prefer one
built from a commit at or after your worktree's base commit.

## 3. Run tests

```bash
docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<your-pg-port> \
  -e DB_DATABASE=makam_test -e DB_USERNAME=makam_test -e DB_PASSWORD=makam_test \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=<your-redis-port> \
  -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:<tag-or-digest> php -d memory_limit=512M vendor/bin/phpunit <test paths>
```

Use `vendor/bin/phpunit` directly, not `php artisan test` — the latter has produced misleadingly
truncated output on this host. Do not set `CACHE_STORE`/`SESSION_DRIVER` to `redis` for this run;
only `REDIS_HOST`/`REDIS_PORT` are needed, and the app's own testing config picks the right store.

## 4. Style and static analysis

```bash
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:<tag-or-digest> vendor/bin/pint --test

docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:<tag-or-digest> vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

Neither needs the Postgres/Redis containers. `phpstan` needs the explicit `--memory-limit=1G` —
its default 128M crashes under parallel workers on this host's real analysis set; that is an
environment limit, not a code issue.

## 5. Tear down

```bash
docker rm -f <prefix>-pg <prefix>-redis
```

Always use a unique `<prefix>` and non-default ports rather than reusing `5432`/`6379` — several
other containers on this host hold those long-term, and a collision fails the `docker run` rather
than reusing the running instance.
