# Load Testing (k6)

Scripts here implement `docs/operations/performance-and-capacity.md`'s
documented load profiles using [k6](https://k6.io/).

## What's here

- `profile-a-normal-launch.js` — Profile A (normal launch), run in CI at a
  reduced, CI-runner-safe scale (10 VUs/30s against 3 unauthenticated GET
  routes: `/`, `/cemeteries`, `/faq`). See the script's own header comment
  and `docs/superpowers/plans/2026-08-22-phase2-regression-gap-closing.md`
  Task 5 for exactly why this is reduced and what the reduction costs.

## What's NOT here yet, deliberately

Full-scale Profile A (50 VUs), Profile B (150 VUs, campaign/burst),
Profile C (10k-row import + concurrent critical webhook traffic), and
Profile D (concurrency invariants under load) all require, per
`performance-and-capacity.md` §9, "an isolated time window or temporary
environment" and load generation "from a separate machine" — the shared
dev/staging host this repo runs CI/UAT against is explicitly not accepted
as production-capacity evidence. These are deferred to Phase 3 (production
graduation) of the approved release-readiness roadmap, not silently
skipped.

## Running locally

```bash
K6_BASE_URL=http://127.0.0.1:8080 k6 run tests/load/profile-a-normal-launch.js
```

Needs a real served app (`php artisan serve`) pointed at a real Postgres
database with `php artisan bench:generate-grave-dataset` already run — see
`.github/workflows/ci.yml`'s `load-test` job for the exact setup sequence.

The app's `public-guest` rate limiter (60 requests/minute per IP,
`app/Providers/AppServiceProvider.php`, `config/rate_limiting.php`) applies
to every route this script hits. 10 VUs looping these three GET requests as
fast as possible will exceed that limit almost immediately if the limiter
is left on, producing real `429`s that read as script/threshold failures
rather than a genuine capacity signal. Set
`THROTTLE_PUBLIC_GUEST_DISABLED=true` on the served app's process before
running this script locally — the CI `load-test` job already does this on
its "Serve the app for k6" step, the same way the existing `browser-test`
job does for the same reason (see that job's own comment on this env var).
