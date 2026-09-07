# Batch M4a — OpenAPI validation gate + `/health/live` middleware leak

**Status:** IN PROGRESS 2026-09-07. Phase 3 audit remediation, batch M4a (see
`docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md` for overall
program structure). Two findings, both infra-adjacent:

- **API-01** — CI's "OpenAPI validation" job is a YAML parse plus two ad-hoc
  key checks, not a real OpenAPI validator. It would pass a structurally
  invalid document.
- **API-08** — `/health/live` runs inside the `web` middleware group, so a
  session-store (DB, since `SESSION_DRIVER=database` by default —
  `config/session.php:21`) or cache outage (the `throttle:public-guest`
  limiter — `app/Providers/AppServiceProvider.php`) fails the liveness
  probe. That defeats the point of a liveness check: it should only fail
  when the process itself cannot answer HTTP, never because a downstream
  dependency is degraded (that is `/health/ready`'s job).

## API-01 — real OpenAPI validation

### Root cause

`.github/workflows/ci.yml`'s `contracts` job (`Validate OpenAPI 3.1
structure` step) only checks `openapi` starts with `3.1` and that `paths`
is non-empty via `yaml.safe_load` — no schema/structural validation of
response objects, parameters, `$ref`s, etc. Two `409` response objects in
`docs/contracts/openapi.yaml` are YAML-valid but not valid OpenAPI Response
Objects (extra top-level keys `stale`, `conflicted`, `or capability
disabled` / `hours`, `or capability unavailable` sitting as sibling keys of
`description` instead of being part of the description text) — proof the
current gate is not a real validator.

### Fix

1. Fix the two malformed `409` response objects in
   `docs/contracts/openapi.yaml` (~line 165 and ~line 274) by folding the
   stray keys into the `description` string — a Response Object only
   permits `description` (required), `headers`, `content`, `links` per the
   OpenAPI 3.1 spec (which itself is the 3.1 JSON Schema dialect + minor
   additions over 3.0's Response Object shape).
2. Replace the ad-hoc Python structural-assertion step with a real
   validator. The repo already provisions Python 3.12 + pip in this same
   job (no Node dependency needed here, keeping the job lean) — use
   `pip install openapi-spec-validator` and run
   `openapi-spec-validator docs/contracts/openapi.yaml`, which validates
   against the real OpenAPI 3.1 JSON Schema (delegating to
   `jsonschema-spec`) rather than two hand-picked assertions.
3. Keep the existing "Validate YAML documents parse" step as-is — it
   covers every YAML file, not just the OpenAPI contract, and remains
   useful independent of OpenAPI-specific validation.

## API-08 — liveness probe leaks middleware

### Root cause

`routes/web.php` is registered via `bootstrap/app.php`'s
`withRouting(web: __DIR__.'/../routes/web.php', ...)`, so every route
declared in that file — `/health/live` and `/health/ready` included, even
though they're declared before the "MVP entry points" — inherits the
framework's `web` middleware group. That group carries `StartSession`
(session driver defaults to `database` per `config/session.php:21`), and
`bootstrap/app.php` appends `throttle:public-guest` (cache-backed rate
limiter) to the same group. A database or cache outage make either of
those middleware throw before the route's own controller ever runs,
turning `/health/live` into a de facto second readiness check.

`/health/ready` is *supposed* to depend on DB/Redis — that's its purpose,
and `HealthReadyController` already handles that gracefully via
`ReadinessCheck`, returning a clean `503` JSON body with no leaked
exception detail. But if `StartSession`/`throttle:public-guest` throw
*before* the controller runs, the response is an unhandled exception, not
the controller's intended clean `503` — the same underlying bug, just
manifesting for `/health/ready` as "wrong failure shape" instead of "should
never fail this way at all".

### Fix

Exclude the two dependency-bearing middleware from both health routes via
`Route::withoutMiddleware()`, matching the framework's own pattern for
`/up` (registered outside any middleware group entirely) as closely as
possible without moving the routes to a whole new routing file (health
check pair intentionally lives inside `routes/web.php` next to a comment
explaining `ci-cd-and-release.md` §8's naming pair):

```php
Route::get('/health/live', HealthLiveController::class)
    ->withoutMiddleware(['throttle:public-guest', StartSession::class])
    ->name('health.live');
Route::get('/health/ready', HealthReadyController::class)
    ->withoutMiddleware(['throttle:public-guest', StartSession::class])
    ->name('health.ready');
```

Both routes keep the rest of the `web` group (cookie encryption,
`AssignCorrelationId`, CSRF — none of which touch a downstream dependency
and CSRF is irrelevant to a `GET`) — only the two dependency-bearing
middleware are excluded.

### Test

Replace `test_health_live_returns_200_with_no_dependency` (currently
vacuous — it never actually exercises a dependency outage, just asserts
the happy path) with a real test that points the session connection at an
unreachable database connection and the cache store at an unreachable
Redis connection, then asserts `/health/live` still returns `200`. This is
the only way to prove the property the finding names: today, config
overrides alone don't reproduce the bug (SQLite/array test doubles used
elsewhere don't throw), so the test must point `session.connection` /
`cache.stores.<store>.host` at a connection nothing is listening on and
assert no exception propagates and status is `200`.

## Verification plan

Run inside Docker (host PHP is 8.3, too old) against a disposable
`postgres:18` / `redis:8.2-alpine` pair, container prefix `m4a-`:

- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --no-progress` (bump memory limit if needed)
- `php artisan test --filter=HealthEndpointsTest`
- `bash ci/verify-docs.sh`
- Local sanity check of the new OpenAPI gate: `pip install
  openapi-spec-validator && openapi-spec-validator
  docs/contracts/openapi.yaml` (best-effort locally if pip/network is
  available in the container; otherwise rely on real CI to confirm, and
  report NOT TESTED explicitly for that leg if it can't run locally).

No AWS, no production-affecting or destructive change — this is CI
tooling + a route-registration fix, not a sensitive change under
`AGENTS.md` §Infrastructure-agent execution's human-review list.
