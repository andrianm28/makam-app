# Load Testing (k6)

Scripts here implement `docs/operations/performance-and-capacity.md`'s
documented load profiles using [k6](https://k6.io/).

## What's here

- `profile-a-normal-launch.js` — Profile A (normal launch), run in CI at a
  reduced, CI-runner-safe scale (10 VUs/30s against 3 unauthenticated GET
  routes: `/`, `/pemakaman`, `/faq`). See the script's own header comment
  and `docs/superpowers/plans/2026-08-22-phase2-regression-gap-closing.md`
  Task 5 for exactly why this is reduced and what the reduction costs.

## What gates CI pass/fail, and what doesn't

Only `http_req_failed: ['rate<0.01']` is declared as a k6 threshold, so
that's the only thing that fails this CI job's `load-test` step.
`http_req_duration` (p50/p90/p95/p99 per route) is NOT a declared threshold
— k6 still measures and prints those numbers unconditionally in its summary
output (under "TOTAL RESULTS"), but a slow response here does not fail CI.
This is deliberate, not an oversight: the CI job serves the app via
`php artisan serve`, an explicitly non-production PHP CLI dev server, so a
duration result from it is not production-capacity evidence either way (see
`performance-and-capacity.md` §9, quoted below). This job's actual purpose
is proving the k6 tooling, dataset, and CI wiring genuinely connect and
complete end-to-end at a real (if reduced) scale — which 100% successful
connections against real 200 responses on all three routes is proof of. See
`profile-a-normal-launch.js`'s own header comment for the real numbers from
the CI run that established this (durations well over the documented
500ms target, against the dev server — a legitimate result of the serving
stack, not something to quietly tune the threshold to pass).

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

Needs the `k6` binary installed locally (see
https://grafana.com/docs/k6/latest/set-up/install-k6/ for the current
official install instructions per platform — the CI `load-test` job below
installs it via k6's official apt repository on Ubuntu runners). k6 runs
here as a plain local process, not inside a container — it needs to reach
`php artisan serve` on `127.0.0.1`, and a containerized k6 (e.g.
`grafana/k6-action`, which builds and runs k6 inside its own isolated
Docker container) resolves `127.0.0.1` to its own loopback instead, not the
host's — this is exactly the failure the CI job's own comment on its
"Install k6" step documents hitting and fixing.

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
