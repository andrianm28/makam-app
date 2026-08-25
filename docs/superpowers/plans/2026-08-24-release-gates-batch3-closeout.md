# Release-gates batch 3 closeout — Horizon dev convention, Pulse/Sentry verification, traceability maintenance

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close 3 more of `docs/testing/release-gates.md`'s remaining open boxes with real evidence — the Horizon `development`-environment provisioning gap, the Pulse/Sentry observability box (now that PR #162 fixed `composer.lock` and both packages are real dependencies), and a traceability-matrix maintenance pass for `ADM-070`/`CARE-SUB-03`/`CARE-SUB-04`/`CARE-SUB-05`/`CARE-SUB-07`.

**Architecture:** Three independent, disjoint-file tasks. No shared interfaces between them — order doesn't matter, but Task 2 benefits from running against the real pinned image now that `laravel/pulse` and `sentry/sentry-laravel` are genuinely installed (merged to trunk via PR #162, commit `11643fc` and later).

**Tech Stack:** Laravel 13, `laravel/horizon`, `laravel/pulse`, `sentry/sentry-laravel`, PostgreSQL 18, Redis 8.2, Docker (pinned CI image), PHPUnit.

**Spec:** None — this plan continues the same research-and-triage this session already did directly against `docs/testing/release-gates.md`'s live text (see that file's own extensively-cited evidence per box); no separate spec doc, matching the precedent this session already set for `2026-08-24-release-gates-post-150-corrections.md` and `2026-08-24-release-gates-phase2-closeout.md` (both skipped a spec doc for the same reason — the research lives in the target document itself).

## Global Constraints

- Every new/modified PHP file needs `declare(strict_types=1);`.
- Follow this repo's evidence-citation discipline for every `release-gates.md` box update: cite real test names/commands, never overclaim, a box can stay unchecked with corrected/updated evidence if only part of its claim is proven.
- **Never fabricate test evidence.** If Task 3's research finds no real existing test for a named capability, say so plainly and leave the row's evidence column as `—` — do not write a placeholder test just to fill the cell, and do not write a brand-new test for a capability that doesn't have one (that is out of scope for this plan; a real coverage gap gets named, not silently closed).
- Do NOT add a `development`/`dev*` block to `config/horizon.php`'s `environments` array. `docs/testing/release-gates.md` §H's Horizon box already records why (24 Aug 2026 ordering-constraint update): dev and beta share a live Redis keyspace by explicit, accepted user decision (`ADR-0035` item 12), and adding a live `development` block would start real, persistent Horizon supervisors that consume beta's queues too. Task 1 below is a documentation-only fix for this reason, not a code fix.
- `AGENTS.md` §Observability: never place restricted data in logs/Pulse/Horizon tags/error trackers — relevant to Task 2, which touches Sentry/Pulse configuration; do not weaken `config/sentry.php`'s existing `send_default_pii: false` or `SentryEventScrubber`.
- No AWS, no changes to production-affecting/security/authorization/financial/DNS/firewall config without human review (none of these 3 tasks should need that — flag if any task's real scope turns out to touch such an area).
- Composer/npm builds do not run on this host — CI only. This plan adds no new packages.
- Real Docker test-execution recipe (established this session): `docker run --network host --user 1000:1000 -e DB_CONNECTION=pgsql ... <pinned-image-digest> php -d memory_limit=512M vendor/bin/phpunit <paths>` against fresh disposable `postgres:18`/`redis:8.2-alpine` containers, run from a shell (not through the file-edit tools). The pinned image as of this plan: `ghcr.io/andrianm28/makam-app@sha256:fd978e4cd3706ebd7fab85654cb806bfa7424086371c8c0a793f7e141d032d51` — before using it, confirm it is still the latest digest by checking whether a newer "Build and push image" CI run has landed since (the image needs to actually contain `laravel/pulse`/`sentry/sentry-laravel` real code, which only a build after PR #162 merged would have baked in).
- `phpunit.xml` already sets `CACHE_STORE=array` and `SESSION_DRIVER=array` as its own test defaults — do NOT override these to `redis` when invoking `vendor/bin/phpunit` directly (doing so lets rate-limiter state leak across tests in one process and produces a false wall of 429 failures — a real mistake made and root-caused earlier this session). Only override `DB_CONNECTION`/`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` and `REDIS_HOST`/`REDIS_PORT` for Postgres/Redis connectivity.

---

### Task 1: Document the Horizon `development`-environment convention

**Files:**
- Modify: `config/horizon.php` (add a doc comment near the `environments` array key)
- Modify: `docs/operations/examples/docker-compose.dev-stg.yml` (add a note near `dev-worker`)
- Modify: `docs/testing/release-gates.md` (close/update the "Horizon has a real `development`-environment provisioning path" box under §H)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: nothing consumed by later tasks. Fully independent of Tasks 2 and 3 (disjoint files).

- [ ] **Step 1: Confirm the real current state**

Read `docs/testing/release-gates.md`'s §H box titled "Horizon has a real `development`-environment provisioning path." and the related follow-up prose inside the (already-closed) "Horizon supervisors, queue priorities, long-wait alerts, and graceful restart pass." box directly above it. Both already state the finding precisely: `config/horizon.php`'s `environments` array has only `production`/`staging`/`local` keys; `Laravel\Horizon\ProvisioningPlan::deploy()` matches via `Str::is($name, $environment)` (exact or wildcard, no partial match); a plain `php artisan horizon` on a container whose `APP_ENV=development` silently deploys zero supervisors. Also read `docs/operations/examples/docker-compose.dev-stg.yml`'s `dev-worker` service definition (around line 82) — confirm directly that dev's real, compose-defined queue mechanism is `php artisan queue:work --stop-when-empty --queue=critical,urgent,notifications,default` (a plain worker, not Horizon at all), started on-demand via the `dev-worker` Compose profile. This means the gap this box names only matters if someone runs `php artisan horizon` on dev BY HAND (e.g. for debugging) — the deployed compose service never hits it.

- [ ] **Step 2: Add the documented convention to `config/horizon.php`**

Near the top of the `environments` array (before the `production` key), add a doc comment (PHP comment block, matching this file's existing comment style — read the file's existing comments near the `defaults`/`staging` keys first for tone/format) stating: this array intentionally has no `development`/`dev*` key; dev's real queue mechanism is a plain `queue:work` worker (Compose `dev-worker` profile), not Horizon; if Horizon is ever run manually on dev for debugging, it MUST be started with `php artisan horizon --environment=local` (never bare `php artisan horizon`, which silently deploys zero supervisors, and never a real persistent `development` block, which would start supervisors that consume beta's live Redis queues too — dev and beta share a Redis keyspace by explicit accepted decision, `ADR-0035` item 12).

- [ ] **Step 3: Add a matching note to the Compose reference file**

In `docs/operations/examples/docker-compose.dev-stg.yml`, near the `dev-worker` service definition, add a YAML comment recording the same convention in brief: dev uses `queue:work`, not Horizon; a manual Horizon run on dev needs `--environment=local`; do not add a live `development` Horizon block (see `config/horizon.php`'s own comment and `ADR-0035` item 12 for why).

- [ ] **Step 4: Close the release-gates.md box with real evidence**

Update the "Horizon has a real `development`-environment provisioning path." box under §H. This box's literal claim ("a real `development`-environment provisioning path") is now satisfiable by the DOCUMENTED-CONVENTION option its own text already named as one of two valid resolutions ("either a `development`/`dev*` environment block, or a documented convention of always passing `--environment=local` on dev") — cite the two file locations just edited (`config/horizon.php`'s new comment, the Compose file's new comment) as the real, durable record of that convention. Check this box `[x]` — the literal claim is satisfied by the documented-convention path, which this box's own prior text explicitly accepted as sufficient.

- [ ] **Step 5: Run doc gates, commit**

```bash
bash ci/verify-docs.sh
git add config/horizon.php docs/operations/examples/docker-compose.dev-stg.yml docs/testing/release-gates.md
git commit -m "docs(horizon): document the dev --environment=local convention, close the release-gates provisioning-path box"
```

---

### Task 2: Verify and evidence-close the Pulse/Sentry observability box

**Files:**
- Modify: `docs/testing/release-gates.md` (update the "Pulse, error tracking, uptime, DB/Redis metrics, and correlation IDs are configured and access-controlled." box under §H)
- No PHP source files should need changes — `config/pulse.php`, `config/sentry.php`, `App\Platform\Observability\Providers\ObservabilityServiceProvider`, and `App\Platform\Observability\SentryEventScrubber` already exist and are already wired into `bootstrap/providers.php` (built in an earlier, already-merged pass — this task verifies they now actually work with real packages installed, it does not build them from scratch).

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: nothing consumed by later tasks. Fully independent of Tasks 1 and 3.

- [ ] **Step 1: Confirm the real starting state**

Read `config/pulse.php`, `config/sentry.php`, and `app/Platform/Observability/Providers/ObservabilityServiceProvider.php` (find its exact path via `find app/Platform/Observability -type f`) in full. Confirm: Pulse's dashboard authorization is a `Gate::define('viewPulse', ...)` call reusing `AdminPanelAccessPolicy`/`IdentityAccessAdapter::resolveActorContext()` (per `config/pulse.php`'s own comment); Sentry's `send_default_pii` is `false` and `before_send` points at `SentryEventScrubber::scrub()`, which should scrub NIK/KK and signed document-vault URLs and attach the correlation ID as a tag (read `SentryEventScrubber`'s own class to confirm this is real, not just documented in a comment). Also confirm both `laravel/pulse` and `sentry/sentry-laravel` are genuinely present in `composer.lock` now (`grep -A2 '"name": "laravel/pulse"' composer.lock`, same for `sentry/sentry-laravel`) — they should be, since PR #162 merged this fix to trunk before this plan's worktree was created.

- [ ] **Step 2: Real functional verification against the pinned image**

Confirm the pinned image digest actually contains these packages (check whether a CI "Build and push image" run has completed since PR #162 merged — if the currently-known digest predates that merge, find the newest real digest via `gh run list` filtered to the "Build and push image" job on `docs/design-system-and-planning`, or build/verify via a fresh `composer install` inside a disposable container using this worktree's own `composer.lock`, matching the recipe PR #162 itself used). Using the disposable-container recipe from this plan's Global Constraints (fresh `postgres:18` + `redis:8.2-alpine`, pinned image, this worktree's code mounted in, `--network host`, `--user 1000:1000`, torn down afterward):

1. Run `php artisan migrate --force` (Pulse needs its own tables — confirm `pulse_*` tables get created; if they don't exist yet, run `php artisan vendor:publish --tag=pulse-migrations` first inside the same container, then re-migrate).
2. Boot the app (`php artisan serve --host=0.0.0.0 --port=8099 &` or equivalent) and `curl` the Pulse dashboard route (`/pulse` by default per `config/pulse.php`'s `path` key) both unauthenticated (expect a 403/redirect, not the dashboard) and as a real seeded admin actor (expect the dashboard to render) — reuse this repo's existing test-actor-seeding convention (check `tests/Feature/Filament/Admin/**` for how other tests authenticate as an admin actor, or use a real Feature test instead of a raw `curl` if that's more reliable for asserting the gate).
3. Confirm Sentry's config resolves without error: `php artisan tinker --execute="dd(config('sentry.dsn'), config('sentry.before_send'));"` (expect `null` for `dsn` since no real `SENTRY_LARAVEL_DSN` is configured anywhere yet — that's expected and should be stated plainly in the evidence, not hidden) and confirm `php artisan config:cache` succeeds cleanly with both packages' service providers loaded (this was a real, previously-fixed failure mode per `config/sentry.php`'s own comments — a closure-based config value breaks `config:cache`; confirm the fix holds now that the real package is installed, not just structurally plausible).
4. Prefer adding this as a real, runnable Feature test if a reasonable one doesn't already exist (e.g. `tests/Feature/Observability/PulseDashboardAccessTest.php` asserting the `viewPulse` gate denies a non-admin and allows an admin) over a one-off manual `curl` — check `tests/Feature/` for an existing Pulse/Sentry test file first (grep for `Pulse`/`Sentry` under `tests/`) before deciding whether to add one; if a reasonable test already exists, just run it and cite it, don't duplicate it.

- [ ] **Step 2a: If Pulse's migrations were never published, publish them for real and commit**

If Step 2 discovers `pulse_*` tables don't exist because `vendor:publish --tag=pulse-migrations` was never run, run it inside this worktree (not the disposable container — the published migration file needs to land in this worktree's `database/migrations/` to be committed), confirm the new migration file's content matches Pulse's own package migration (read it), then re-run Step 2's verification with the real committed migration.

- [ ] **Step 3: Update the release-gates.md box with precise, non-overclaiming evidence**

Update the "Pulse, error tracking, uptime, DB/Redis metrics, and correlation IDs are configured and access-controlled." box under §H. State precisely what's now real vs. still open:
- **Pulse dashboard + access control**: real, cite the Step 2 test/verification.
- **Error tracking (Sentry)**: the package and scrubbing/PII-safety code are real and load cleanly, but there is no real `SENTRY_LARAVEL_DSN` configured anywhere — state this plainly (no events are actually being sent anywhere yet; this needs a real Sentry project + DSN, which is an account-provisioning step, not something this task can fabricate).
- **Uptime monitoring**: still genuinely absent (no UptimeRobot/Better Stack or equivalent configured anywhere) — this needs a real external account, out of engineering-task scope, leave explicitly open.
- **DB/Redis metrics**: Pulse's default recorders (`SlowQueries`, `Queues`, `CacheInteractions`, `SlowJobs`, `SlowRequests`) cover query/job/cache timing but there is no dedicated Redis memory/eviction-rate recorder or PostgreSQL replication/bloat metrics — state this precisely rather than claiming "DB/Redis metrics" as a whole is satisfied by Pulse's default recorder set.
- **Correlation IDs**: already real and tested (`AssignCorrelationId`, `CorrelationId`, existing tests) — unchanged, cite what already exists.

This is a 5-part compound claim; expect it to stay unchecked overall (uptime monitoring and a real Sentry DSN are still genuinely missing) but with materially more of the claim evidenced than before this task, matching this repo's established "narrow the compound claim precisely" convention (the exact pattern §H's Horizon and CI/CD boxes already used this session).

- [ ] **Step 4: Run tests, doc gates, commit**

```bash
bash ci/verify-docs.sh
git add docs/testing/release-gates.md
# add any new test files and/or the published Pulse migration from Step 2a
git commit -m "docs(observability): verify Pulse/Sentry now that composer.lock is fixed, narrow the release-gates box to precise evidence"
```

---

### Task 3: Traceability-matrix maintenance pass (`ADM-070`, `CARE-SUB-03/04/05/07`)

**Files:**
- Modify: `docs/domain/traceability-matrix.md` (Evidence column for the 5 named rows, plus a dated changelog entry matching this file's existing versioning convention — read its own changelog section at the bottom for the exact format before adding an entry)
- Modify: `docs/testing/release-gates.md` (§A's "Traceability contains no `Missing` or `Partial` item for stakeholder MVP." box, if the research below changes its status)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: nothing consumed by later tasks. Fully independent of Tasks 1 and 2.

- [ ] **Step 1: Read the current state precisely**

Read `docs/domain/traceability-matrix.md`'s rows for `ADM-070` (line ~178) and `CARE-SUB-03`/`CARE-SUB-04`/`CARE-SUB-05`/`CARE-SUB-07` (lines ~204-208) — all 5 currently show `—` (no evidence) in the Evidence column. Read `docs/testing/release-gates.md` §A's "Traceability contains no `Missing` or `Partial` item for stakeholder MVP." box for the exact framing this maintenance pass should close against.

- [ ] **Step 2: Research each row for real, existing-but-uncited test evidence**

For each of the 5 rows, search the real test suite for coverage of the named capability — do NOT write new tests, only look for evidence that already exists and simply wasn't cited:

- **ADM-070** (payment/transaction/manual verification, admin surface): search `tests/Feature/Filament/**` and `tests/browser/e2e-admin-vendor.spec.ts` for admin payment-verification coverage — `VerifyManualPaymentRouteTest`/`VerifyManualPaymentTest` (already cited elsewhere in `release-gates.md` §C) may be the real evidence this row is missing; confirm whether they genuinely exercise the ADMIN-PANEL surface (not just the domain Action) before citing them here.
- **CARE-SUB-03** (payment webhook validation for subscription cycles): search `tests/Feature/Payment/` and `tests/Feature/Domain/CareSubscription/` for a webhook test that validates a subscription-cycle-scoped payment event (durable, signed, merchant-scoped, replay-protected, never browser-return — per the row's own AC4 reference).
- **CARE-SUB-04** (work order creation from paid cycle): search `tests/Feature/Domain/CareSubscription/` and `tests/Feature/Domain/WorkOrder/` (or wherever `CreateWorkOrderFromCycle` lives — grep for the class name first) for a test proving checklist-template expansion and the `care.work_order_created.v1` outbox emission.
- **CARE-SUB-05** (evidence upload, vault-backed, before/after images through quarantine): search `tests/Feature/DocumentVault/` and `tests/Feature/Domain/CareSubscription/`/`tests/Feature/Domain/VendorFulfillment/` for a test proving before/after image upload goes through the real document-vault quarantine path and is never previewable before scan acceptance.
- **CARE-SUB-07** (vendor replacement audit): search `tests/Feature/Domain/CareSubscription/` and `tests/Feature/Filament/Vendor/` for a test proving replace/reschedule with a captured, audited reason, and the "one replacement per original" constraint.

For each row, either (a) find real, passing test(s) and record their exact file/method names, or (b) confirm no such test exists anywhere in the repo (a real, honest negative result).

- [ ] **Step 3: Update the traceability matrix**

For each row where Step 2 found real evidence: update the Evidence column with the real test file/method name(s), matching this file's existing citation format (see `CARE-SUB-02`'s or `CARE-SUB-06`'s existing Evidence cells for the exact format). Add a dated changelog entry at the bottom of the file (matching the existing `v0.17`/`v0.18`-style entries' format) recording which rows were updated and why, per this file's own established convention.

For each row where Step 2 found no real evidence: leave the Evidence column as `—` — do NOT write a placeholder or invent one — but ensure the row's own prose (if any exists elsewhere describing it) is not misleadingly optimistic. This is a legitimate, honest outcome for this task: confirming a real gap precisely is real progress even when it doesn't add a citation.

- [ ] **Step 4: Update release-gates.md §A if the finding changes anything**

If Step 2/3 found and cited real evidence for some (not necessarily all) of the 5 rows, update §A's "Traceability contains no `Missing` or `Partial` item for stakeholder MVP." box to reflect exactly which of the originally-named gaps (ADM-070, CARE-SUB-02/03/04/05/06/07) are now evidenced vs. still genuinely open — following this repo's "cite precisely, never overclaim" convention. The box should stay unchecked unless Step 2/3 genuinely closes ALL of the named gaps (unlikely, but state the real outcome either way) — a partial improvement gets a corrected, narrower unchecked box, not a checked one.

- [ ] **Step 5: Run doc gates, commit**

```bash
bash ci/verify-docs.sh
git add docs/domain/traceability-matrix.md docs/testing/release-gates.md
git commit -m "docs(traceability): maintenance pass on ADM-070/CARE-SUB-03/04/05/07 evidence citations"
```

---

## Verification

Standard `bash ci/verify-docs.sh` for every task. Task 2's Feature test (if added) should be run for real against the pinned container image + Postgres/Redis (matching this session's established "no unexecuted PASS" discipline), not just written. Tasks 1 and 3 are documentation-only — no PHP test execution needed for those two, but `vendor/bin/pint --test` and `vendor/bin/phpstan analyse` should still run clean if either task's diff happens to touch any PHP file (Task 1 might, if the `config/horizon.php` comment addition is judged to need a lint pass — check).

## Execution

This plan will be executed via superpowers:subagent-driven-development immediately after being written and saved, matching this session's established pattern for every workstream so far (fresh implementer per task, task-scoped review, final whole-branch review before PR).
