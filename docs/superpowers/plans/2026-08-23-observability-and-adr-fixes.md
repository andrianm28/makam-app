# Observability and ADR-0027 Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 4 real gaps the just-merged `release-gates.md` §C-I walkthrough (PR #149) surfaced: correct ADR-0027's now-stale host specification, author a real `config/horizon.php` from this repo's own already-documented supervisor baseline, and prepare (but not lock-file-regenerate) an npm audit fix and a Sentry+Pulse installation for a human to complete.

**Architecture:** Four independent, unrelated fixes bundled into one plan because each is small and none depends on another. Two (ADR-0027, Horizon config) are fully completable in this session. Two (npm audit, Sentry/Pulse) hit a real, hard constraint — `composer.lock`/`package-lock.json` regeneration requires running `composer`/`npm` for real, and this repo's own `CLAUDE.md` forbids running composer/npm builds on this host under any circumstance ("Composer and npm builds run in CI... never on this host... verify by pushing and checking the CI result instead") — so those two tasks prepare everything except the lockfile itself, following the same "prepared, not executed" pattern this repo already uses for host-only runbooks (`docs/operations/runbooks/deploy-stg-vhost.md`, `docs/operations/runbooks/rotate-dev-stg-db-access.md`).

**Tech Stack:** Laravel 13, Laravel Horizon (already installed, `^5.0`), Sentry (`sentry/sentry-laravel`, not yet installed), Laravel Pulse (`laravel/pulse`, not yet installed), PHP 8.5.

**Spec:** No formal spec document — this plan implements 4 findings from `docs/testing/release-gates.md` (as updated by PR #149) directly. Manual-payment settlement wiring (a 5th finding from the same walkthrough) is explicitly OUT OF SCOPE for this plan per the user's explicit 23 Aug 2026 decision to opt out of that item entirely — do not build it, do not reference it as pending in this plan's own text.

## Global Constraints

- PHP `declare(strict_types=1);` on every new/modified PHP file, matching every existing file in this codebase.
- Follow this codebase's `final class` convention for every new class.
- Never place restricted data in logs, Pulse, Horizon tags, or error trackers (`AGENTS.md` §Observability) — Sentry's `before_send` scrubber (Task 3) is not optional polish, it is the mechanism that makes this constraint true for Sentry specifically.
- Do not run `composer install`, `composer require`, `composer update`, `npm install`, `npm audit fix`, or `npm run build` anywhere in this worktree or on this host (`CLAUDE.md`) — Tasks 3 and 4 edit `composer.json`/document the npm fix WITHOUT touching `composer.lock`/`package-lock.json`, and say so explicitly in their own commit messages.
- Run `bash ci/verify-docs.sh` after any task touching `docs/`.
- Test commands run via `php artisan test` against the real disposable Postgres/Redis containers already running on this host (`e2e-admin-vendor-pg`/`e2e-admin-vendor-redis` on network `e2e-admin-vendor-net`), never assumed to pass from static reading alone.

---

### Task 1: Correct ADR-0027's stale host specification

**Files:**
- Modify: `docs/adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing — documentation-only task.

- [ ] **Step 1: Add a dated correction note, matching this repo's established ADR-superseding-note pattern**

Read the file in full first (it is short, 48 lines). Insert a new section immediately after `## Status` (before `## Context`), matching the exact pattern `docs/adr/0024-use-session-auth-and-mfa.md`'s own superseding note and `docs/adr/0035-beta-launch-accepted-risks.md` item 10's "kept for the historical record" framing already established this session:

```markdown

## Host specification correction (23 Aug 2026)

This ADR's title, Decision line, and item 9 state the host is "Ubuntu 22.04 LTS... 2 vCPU and 4
GB RAM." That was true of the retired `adrivm` host. The 17 Aug 2026 migration
(`docs/operations/2026-08-17-makam-migration-to-yiemvm.md`) moved the combined dev/staging stack
to `yiemvm`: **Ubuntu 24.04.4, 8 vCPU, 31 GB RAM** — a real, materially different spec, not a
rounding difference. Item 9's own statement that "Production remains Ubuntu 24.04 LTS or managed
equivalent" is now, ironically, the SAME OS the dev/staging host itself runs — worth noting
explicitly rather than leaving as an unremarked coincidence.

This correction updates the numbers this ADR's own body states; it does not reopen or reverse any
of the 9 numbered decision conditions below, all of which remain in force regardless of the exact
vCPU/RAM figures (the "combine dev+staging on one modest host" decision itself is unaffected by
the host being bigger than originally planned). The stale filename
(`0027-combine-dev-staging-on-ubuntu22-2v4g.md`) is left unchanged — only 2 other files reference
this ADR by its exact path (`docs/adr/0031-make-dev-environment-public.md`,
`docs/adr/0035-beta-launch-accepted-risks.md`), and renaming would need updating both without any
real benefit; the filename is a historical label at this point, not a live claim.
```

- [ ] **Step 2: Correct the 3 stale numeric claims in the body itself**

In the same file, change:

```markdown
# ADR-0027: Combine Development and Staging on Ubuntu 22.04 2 vCPU / 4 GB
```
to:
```markdown
# ADR-0027: Combine Development and Staging on a Single Non-Production Host
```

(Dropping the specific OS/spec from the TITLE since it's now documented as a moving target in the correction note above, not a fixed decision parameter — the numbered Decision conditions are the actual binding content, not the exact host size.)

Change:
```markdown
Use one Ubuntu 22.04 LTS host with 2 vCPU and 4 GB RAM for combined development and staging, under these conditions:
```
to:
```markdown
Use one combined non-production host for development and staging (originally Ubuntu 22.04 LTS,
2 vCPU/4 GB; now `yiemvm`, Ubuntu 24.04.4, 8 vCPU/31 GB — see this ADR's own correction note
above), under these conditions:
```

Change item 9:
```markdown
9. Production remains Ubuntu 24.04 LTS or managed equivalent.
```
to:
```markdown
9. Production remains Ubuntu 24.04 LTS or managed equivalent (the dev/staging host now happens
   to run the same OS version as this target, per the correction note above — that is a
   coincidence of the 17 Aug 2026 migration, not evidence this host is production-equivalent;
   item 8 still governs).
```

- [ ] **Step 3: Verify the docs gate**

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS` — gate 4 (relative markdown links resolve) specifically covers the new cross-reference to `docs/operations/2026-08-17-makam-migration-to-yiemvm.md`.

- [ ] **Step 4: Commit**

```bash
git add docs/adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md
git commit -m "docs(adr): correct ADR-0027's stale host OS/spec after the 17 Aug migration

Real host is Ubuntu 24.04.4, 8 vCPU/31 GB (yiemvm) — the ADR still
said Ubuntu 22.04, 2 vCPU/4 GB (the retired adrivm host). Corrects
the title, Decision line, and item 9; keeps a dated correction note
and leaves the stale filename and the 9 decision conditions
themselves unchanged, per this repo's established ADR-superseding
pattern."
```

---

### Task 2: Author `config/horizon.php` from the already-documented supervisor baseline

**Files:**
- Create: `config/horizon.php`

**Interfaces:**
- Consumes: nothing.
- Produces: a real Horizon supervisor configuration other code doesn't call into directly (Horizon reads this file itself).

**Context for this task:** `docs/architecture/queue-and-outbox.md` §2-3 (read it in full before writing this file) already documents the real queue names, priorities, supervisor process caps, and long-wait thresholds — this task transcribes that already-approved spec into Laravel's real config schema, it does not invent new values. `laravel/horizon: ^5.0` is already an installed dependency (`composer.json`) — no new package needed. `ADR-0027` item 4 (as corrected by Task 1) requires "Staging runs a maximum of two normal Horizon worker processes; development and batch workers run on demand" — this is a DIFFERENT, narrower cap than `queue-and-outbox.md`'s general baseline table, and must be expressed as a `staging`-specific environment override, not a blanket value.

- [ ] **Step 1: Write the config file**

```php
<?php

declare(strict_types=1);

use App\Platform\Outbox\OutboxQueueName;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | ADR-0027 condition 3 requires development and staging to use different
    | Redis/Horizon prefixes — this must differ per environment via
    | HORIZON_PREFIX in .env.dev vs .env.stg, not hardcoded here.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | Long-wait alert thresholds per queue, per docs/architecture/
    | queue-and-outbox.md §3's own "Suggested initial thresholds" table —
    | transcribed values, not invented ones.
    |
    */

    'waits' => [
        'redis:'.OutboxQueueName::Critical->value => 10,
        'redis:'.OutboxQueueName::Urgent->value => 15,
        'redis:'.OutboxQueueName::Notifications->value => 60,
        'redis:'.OutboxQueueName::Default->value => 90,
        'redis:'.OutboxQueueName::Imports->value => 300,
        'redis:'.OutboxQueueName::Media->value => 300,
        'redis:'.OutboxQueueName::Reports->value => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    */

    'silenced' => [],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    */

    'memory_limit' => 128,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Supervisors
    |--------------------------------------------------------------------------
    |
    | Per-environment supervisor definitions. `production` and `local` (the
    | default Laravel environment names) hold the full baseline from
    | docs/architecture/queue-and-outbox.md §3. `staging` is deliberately
    | narrower per ADR-0027 condition 4 ("Staging runs a maximum of two
    | normal Horizon worker processes; development and batch workers run
    | on demand") — the 4 "normal" queues (critical/urgent/notifications/
    | default) share ONE supervisor capped at 2 total processes, and batch
    | queues (imports/media/reports) are NOT started at all in staging,
    | matching "development and batch workers run on demand" — the
    | dev-worker/stg-batch-worker Compose services (profiles: ["dev-worker"]/
    | ["batch"]) are the on-demand mechanism, not Horizon-managed supervisors.
    |
    */

    'defaults' => [
        'supervisor-critical' => [
            'connection' => 'redis',
            'queue' => [OutboxQueueName::Critical->value],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-urgent' => [
            'connection' => 'redis',
            'queue' => [OutboxQueueName::Urgent->value],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-notify' => [
            'connection' => 'redis',
            'queue' => [OutboxQueueName::Notifications->value],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 90,
            'nice' => 0,
        ],
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => [OutboxQueueName::Default->value],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 90,
            'nice' => 0,
        ],
        'supervisor-batch' => [
            'connection' => 'redis',
            'queue' => [OutboxQueueName::Imports->value, OutboxQueueName::Media->value],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 0,
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 900,
            'nice' => 0,
        ],
        'supervisor-reports' => [
            'connection' => 'redis',
            'queue' => [OutboxQueueName::Reports->value],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 0,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 900,
            'nice' => 0,
        ],
        // Staging-only supervisor (see the 'staging' environment block
        // below) — declared here too, with a maxProcesses baseline, per
        // this file's own convention that every supervisor referenced in
        // 'environments' has a matching 'defaults' entry.
        'supervisor-normal' => [
            'connection' => 'redis',
            'queue' => [
                OutboxQueueName::Critical->value,
                OutboxQueueName::Urgent->value,
                OutboxQueueName::Notifications->value,
                OutboxQueueName::Default->value,
            ],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 90,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-critical' => ['minProcesses' => 1, 'maxProcesses' => 4],
            'supervisor-urgent' => ['minProcesses' => 1, 'maxProcesses' => 4],
            'supervisor-notify' => ['minProcesses' => 1, 'maxProcesses' => 4],
            'supervisor-default' => ['minProcesses' => 1, 'maxProcesses' => 4],
            'supervisor-batch' => ['minProcesses' => 0, 'maxProcesses' => 3],
            'supervisor-reports' => ['minProcesses' => 0, 'maxProcesses' => 2],
            'supervisor-normal' => false,
        ],

        // ADR-0027 condition 4: "Staging runs a maximum of two normal
        // Horizon worker processes; development and batch workers run on
        // demand." A literal 2-process cap across 4 queues needs ONE
        // supervisor covering all 4, not 4 separate supervisors each
        // capped individually (Horizon has no shared-pool primitive
        // across supervisors) — supervisor-normal (declared in 'defaults'
        // above) is that single supervisor. The 4 baseline supervisors
        // and both batch/report supervisors are disabled entirely in
        // staging ('false') — batch/report work runs via the
        // dev-worker/stg-batch-worker Compose services' on-demand
        // profiles instead, not as Horizon-managed processes here.
        'staging' => [
            'supervisor-critical' => false,
            'supervisor-urgent' => false,
            'supervisor-notify' => false,
            'supervisor-default' => false,
            'supervisor-batch' => false,
            'supervisor-reports' => false,
            'supervisor-normal' => ['minProcesses' => 1, 'maxProcesses' => 2],
        ],

        'local' => [
            'supervisor-critical' => ['maxProcesses' => 1],
            'supervisor-urgent' => ['maxProcesses' => 1],
            'supervisor-notify' => ['maxProcesses' => 1],
            'supervisor-default' => ['maxProcesses' => 1],
            'supervisor-batch' => ['maxProcesses' => 1],
            'supervisor-reports' => ['maxProcesses' => 1],
            'supervisor-normal' => false,
        ],
    ],

];
```

- [ ] **Step 2: Verify PHP syntax and static analysis**

Run:
```bash
docker run --rm --network e2e-admin-vendor-net \
  -v $(pwd):/var/www/html \
  --user "$(id -u):$(id -g)" \
  ghcr.io/andrianm28/makam-app@sha256:fd978e4cd3706ebd7fab85654cb806bfa7424086371c8c0a793f7e141d032d51 \
  php -l config/horizon.php
```
Expected: `No syntax errors detected`.

Run pint:
```bash
docker run --rm --network e2e-admin-vendor-net \
  -v $(pwd):/var/www/html \
  --user "$(id -u):$(id -g)" \
  ghcr.io/andrianm28/makam-app@sha256:fd978e4cd3706ebd7fab85654cb806bfa7424086371c8c0a793f7e141d032d51 \
  vendor/bin/pint --test config/horizon.php
```
Expected: `PASS`.

- [ ] **Step 3: Verify `OutboxQueueName`'s real enum case names before committing**

Read `app/Platform/Outbox/OutboxQueueName.php` directly and confirm the enum case names used above (`Critical`, `Urgent`, `Notifications`, `Default`, `Imports`, `Media`, `Reports`) match the real file exactly — this plan's brief may have the names slightly wrong; the real file is authoritative. Fix any mismatch before committing.

- [ ] **Step 4: Commit**

```bash
git add config/horizon.php
git commit -m "feat(ops): add config/horizon.php from the already-documented supervisor baseline

Transcribes docs/architecture/queue-and-outbox.md §2-3's real queue
names, priorities, supervisor caps, and long-wait thresholds into
Laravel's real Horizon config schema — no config file existed before
this, Horizon ran on unreviewed package defaults. Staging gets a
single collapsed supervisor capped at 2 processes total, per
ADR-0027 condition 4's literal 'maximum of two normal Horizon
worker processes' requirement."
```

---

### Task 3: Document the npm audit fix (nanoid) without regenerating the lockfile

**Files:**
- Create: `docs/operations/npm-audit-nanoid-fix.md`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing — a prepared, human-executable fix note, matching this repo's "prepared, not executed" runbook pattern.

**Context:** The real, current npm audit finding (confirmed against a real recent CI run, not run locally): `nanoid <3.3.18`, high severity, "custom generators can loop indefinitely when size is zero" (GHSA-2v37-7h3g-55p8), transitive dependency (`node_modules/nanoid`), fix available via `npm audit fix`. `package.json` does NOT need editing — `nanoid` is not a direct dependency, and the parent package's existing semver range (`^3.3.16`) already permits the fix version `3.3.18`; only `package-lock.json`'s resolved/locked version needs bumping, which requires running real `npm` to compute a correct integrity hash. This task documents the fix precisely enough for a human (or a CI-triggered step) to execute in seconds, rather than attempting a hand-edit that risks a wrong hash breaking `npm ci`.

- [ ] **Step 1: Write the fix note**

```markdown
# npm audit fix: nanoid (prepared, not executed)

## Status

**Prepared, not executed.** This repo's `CLAUDE.md` forbids running `npm install`/`npm audit
fix`/`npm run build` on this host — "Composer and npm builds run in CI... never on this host...
verify by pushing and checking the CI result instead." Regenerating `package-lock.json` requires
running real `npm` to compute a correct integrity hash for the bumped entry; a hand-edited hash
risks silently breaking `npm ci` in CI. This fix needs a human running the two commands below,
not an agent session.

## The finding

Confirmed against a real recent CI run's "Dependency audit" job (not run locally):

```
nanoid  <3.3.18
Severity: high
nanoid: custom generators can loop indefinitely when size is zero
  - https://github.com/advisories/GHSA-2v37-7h3g-55p8
fix available via `npm audit fix`
node_modules/nanoid
```

`nanoid` is a transitive dependency (not listed in `package.json` directly) — some direct
dependency's own `package.json` already permits `^3.3.16`, which already includes the fix version
`3.3.18`. This is a lockfile-only bump, not a `package.json` change and not a breaking change.

## The fix

Run these two commands locally (with real npm + network access), then commit the resulting
`package-lock.json` diff:

```bash
npm audit fix
npm audit --audit-level=high
```

Expected: the second command reports 0 vulnerabilities at or above `high` severity — matching
what `.github/workflows/ci.yml`'s own audit-level threshold checks. Confirm `git diff
package-lock.json` shows ONLY the `nanoid` entry's version/resolved/integrity fields changing —
if `npm audit fix` pulls in unrelated version bumps, review those separately before committing (a
security-fix commit should stay scoped to the security fix).

## After the fix lands

Once `package-lock.json` is committed with the real bump, `.github/workflows/ci.yml`'s
`npm audit --audit-level=high || true # TODO: fail once the baseline is clean` step's `|| true`
escape hatch and its own TODO comment should be removed in the SAME commit (or an immediate
follow-up) — the whole point of this fix is to make that step genuinely enforce the audit level
it claims to. Leaving `|| true` in place after the baseline is clean defeats the fix.
```

- [ ] **Step 2: Verify the docs gate**

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS`.

- [ ] **Step 3: Commit**

```bash
git add docs/operations/npm-audit-nanoid-fix.md
git commit -m "docs(ops): document the npm audit nanoid fix (prepared, not executed)

Real finding confirmed against a real CI run: nanoid <3.3.18, high
severity, transitive dependency, lockfile-only bump (package.json's
own semver range already permits the fix version). Regenerating
package-lock.json needs real npm + network, which this host's
CLAUDE.md forbids running here — documents the exact 2 commands a
human runs, and the CI || true escape hatch to remove afterward."
```

---

### Task 4: Prepare Sentry + Pulse (composer.json + config code, without regenerating composer.lock)

**Files:**
- Modify: `composer.json`
- Create: `config/sentry.php`
- Create: `config/pulse.php`
- Modify: `bootstrap/app.php` (or wherever exception handling is configured — verify the real file first)
- Modify: `.env.example` (if one exists — check first)
- Create: `docs/operations/composer-sentry-pulse-install.md`

**Interfaces:**
- Consumes: `App\Platform\Correlation\CorrelationContext::current(): ?CorrelationId` (existing, real, confirmed — `app(CorrelationContext::class)->current()?->value` gives the current request's correlation id string).
- Produces: nothing new for other code to consume — this is infrastructure/observability wiring, not a domain interface.

**Context:** Neither `sentry/sentry-laravel` nor `laravel/pulse` exists in `composer.json` today — both are genuinely new dependencies, confirmed by reading the file directly. Adding them means `composer.lock` needs real regeneration (same hard constraint as Task 3's npm fix) — this task edits `composer.json` (declaring the intent) and writes ALL the config/code that will consume these packages once installed, but explicitly does NOT touch `composer.lock`, and names this clearly in its own commit and in a prepared runbook-style doc for the human step. Per the original beta-launch plan's own Lane E2 spec (already read this session): `send_default_pii = false`, a `before_send` scrubber for NIK/KK/signed URLs, tagged `environment` + image digest, the existing correlation ID attached as a tag.

- [ ] **Step 1: Add the two require lines to `composer.json`**

Read `composer.json` in full first. In the `"require"` block, add (alphabetically, matching the existing sorted order):
```json
"laravel/pulse": "^1.0",
"sentry/sentry-laravel": "^4.0",
```
placed correctly alphabetically among the existing entries (`laravel/pulse` goes between `laravel/horizon` and `laravel/tinker`; `sentry/sentry-laravel` goes after `livewire/livewire`, alphabetically before nothing else since it's the last one — verify final alphabetical order against the real current file content, don't guess blindly).

Do NOT touch `composer.lock` — leave it exactly as-is. `git status` after this step should show ONLY `composer.json` modified, not `composer.lock`.

- [ ] **Step 2: Write `config/sentry.php`**

```php
<?php

declare(strict_types=1);

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // AGENTS.md §Observability: "Never place restricted data in logs,
    // Pulse, Horizon tags, or error trackers." This is the flag that
    // makes that true for Sentry's own default PII collection — must
    // stay false; the before_send scrubber below is a second layer,
    // not a substitute for this.
    'send_default_pii' => false,

    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),

    'environment' => env('APP_ENV'),

    'release' => env('SENTRY_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | before_send scrubber
    |--------------------------------------------------------------------------
    |
    | Per docs/superpowers/plans/2026-08-18-public-beta-release.md Lane E2's
    | original spec (observability-stack.md §3/§5): scrub NIK/KK (Indonesian
    | national/family ID numbers) and signed document-vault URLs before
    | transmission — AGENTS.md §Observability's "never place restricted data
    | in... error trackers" applies to this exact surface. Also attaches the
    | existing correlation ID as a tag so a Sentry error links back to its
    | outbox event / journal entry via the same id used everywhere else in
    | this codebase's tracing (AssignCorrelationId middleware).
    |
    */
    'before_send' => static function (\Sentry\Event $event): ?\Sentry\Event {
        $correlationId = app(\App\Platform\Correlation\CorrelationContext::class)->current();

        if ($correlationId !== null) {
            $event->setTag('correlation_id', $correlationId->value);
        }

        $event->setTag('image_digest', (string) env('APP_IMAGE_DIGEST', 'unknown'));

        // NIK: 16 consecutive digits. KK: same format, same scrub — this
        // codebase does not distinguish the two at the string-pattern
        // level, matching how docs/operations/observability-stack.md §5
        // itself does not either.
        $nikKkPattern = '/\b\d{16}\b/';

        // A DocumentVault signed URL — scrub the whole query string, not
        // just the signature param, since the path + expiry + signature
        // together are the sensitive artifact, not any one field alone.
        $signedUrlPattern = '#(https?://[^\s]+/vault/[^\s?]+)\?[^\s]+#';

        $scrub = static function (mixed $value) use ($nikKkPattern, $signedUrlPattern): mixed {
            if (! is_string($value)) {
                return $value;
            }

            $value = preg_replace($nikKkPattern, '[REDACTED-NIK-KK]', $value) ?? $value;
            $value = preg_replace($signedUrlPattern, '$1?[REDACTED-SIGNATURE]', $value) ?? $value;

            return $value;
        };

        if (($message = $event->getMessage()) !== null) {
            $event->setMessage($scrub($message));
        }

        return $event;
    },
];
```

- [ ] **Step 3: Write `config/pulse.php`**

```php
<?php

declare(strict_types=1);

return [
    'enabled' => env('PULSE_ENABLED', true),

    'domain' => env('PULSE_DOMAIN'),

    'path' => env('PULSE_PATH', 'pulse'),

    'storage' => [
        'driver' => env('PULSE_STORAGE_DRIVER', 'database'),
        'trim' => [
            'keep' => env('PULSE_STORAGE_TRIM_KEEP', '7 days'),
        ],
        'database' => [
            'connection' => env('PULSE_DB_CONNECTION'),
            'chunk' => 1000,
        ],
    ],

    'ingest' => [
        'driver' => env('PULSE_INGEST_DRIVER', 'storage'),
        'buffer' => env('PULSE_INGEST_BUFFER', 5000),
        'trim' => [
            'lottery' => [1, 1000],
        ],
        'redis' => [
            'connection' => env('PULSE_REDIS_CONNECTION'),
            'chunk' => 1000,
        ],
    ],

    // Per AGENTS.md §Observability's "access-controlled" requirement
    // (also named directly by release-gates.md §H's Pulse box) — the
    // dashboard route itself must stay behind real admin authorization,
    // not Pulse's own default gate. Verify the real authorization
    // convention this codebase uses elsewhere (e.g. how Horizon's own
    // route authorization is configured, if any) before finalizing this
    // callback — this is a placeholder shape, not verified against a
    // real precedent.
    'authorize' => static fn ($user): bool => $user !== null && method_exists($user, 'isAdmin') && $user->isAdmin(),

    'servers' => [
        env('PULSE_SERVER_NAME', gethostname()),
    ],

    'recorders' => [
        \Laravel\Pulse\Recorders\CacheInteractions::class => [
            'enabled' => env('PULSE_CACHE_INTERACTIONS_ENABLED', true),
            'sample_rate' => env('PULSE_CACHE_INTERACTIONS_SAMPLE_RATE', 1),
        ],
        \Laravel\Pulse\Recorders\Queues::class => [
            'enabled' => env('PULSE_QUEUES_ENABLED', true),
            'sample_rate' => env('PULSE_QUEUES_SAMPLE_RATE', 1),
        ],
        \Laravel\Pulse\Recorders\SlowJobs::class => [
            'enabled' => env('PULSE_SLOW_JOBS_ENABLED', true),
            'sample_rate' => env('PULSE_SLOW_JOBS_SAMPLE_RATE', 1),
            'threshold' => env('PULSE_SLOW_JOBS_THRESHOLD', 1000),
        ],
        \Laravel\Pulse\Recorders\SlowQueries::class => [
            'enabled' => env('PULSE_SLOW_QUERIES_ENABLED', true),
            'sample_rate' => env('PULSE_SLOW_QUERIES_SAMPLE_RATE', 1),
            'threshold' => env('PULSE_SLOW_QUERIES_THRESHOLD', 1000),
            'location' => env('PULSE_SLOW_QUERIES_LOCATION', true),
        ],
        \Laravel\Pulse\Recorders\SlowRequests::class => [
            'enabled' => env('PULSE_SLOW_REQUESTS_ENABLED', true),
            'sample_rate' => env('PULSE_SLOW_REQUESTS_SAMPLE_RATE', 1),
            'threshold' => env('PULSE_SLOW_REQUESTS_THRESHOLD', 1000),
        ],
    ],
];
```

Before finalizing, verify the exact recorder class names/config shape against `laravel/pulse`'s real published config once the package IS installed (this plan's Step 3 code is written from Pulse's documented public API, not verified against an installed copy — flag this explicitly in the task's self-review rather than silently asserting it's exact).

- [ ] **Step 4: Find and wire the real exception-handling entry point**

Read `bootstrap/app.php` in full (Laravel 13's real exception-handling registration point — confirm this is still correct for this app's actual structure, don't assume). Sentry's Laravel integration needs its exception handler registered — find the real, current pattern this file uses for `->withExceptions(...)` and add Sentry's integration call inside it, following Sentry's real Laravel 13 integration documentation shape (`\Sentry\Laravel\Integration::handles($exceptions);` inside the `withExceptions` closure is the current real API — verify this is still accurate at implementation time, Sentry's SDK API changes between major versions).

- [ ] **Step 5: Add `.env.example` entries if that file exists**

Check whether `.env.example` (or similar) exists in this repo (grep for it — this repo's own `.claude/settings.json` denies reading `*.env*` paths directly, so use `git show HEAD:.env.example` or `find . -maxdepth 1 -iname ".env.example"` to check existence without triggering that block, matching this session's established workaround for `.env`-shaped path restrictions). If it exists, add (without any real values):
```
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.0
SENTRY_RELEASE=
PULSE_ENABLED=true
```

- [ ] **Step 6: Write the human-executable install note**

```markdown
# Installing Sentry + Pulse (prepared, not executed)

## Status

**Prepared, not executed.** `composer.json` has been updated to require `sentry/sentry-laravel`
and `laravel/pulse`, and all consuming config (`config/sentry.php`, `config/pulse.php`) and
exception-handler wiring (`bootstrap/app.php`) are written and committed. `composer.lock` has
deliberately NOT been touched — regenerating it needs real `composer` + network access, which
this repo's `CLAUDE.md` forbids running on this host.

## What a human runs

```bash
composer update laravel/pulse sentry/sentry-laravel --with-all-dependencies
php artisan vendor:publish --tag=pulse-migrations
php artisan migrate
```

Then provision the real `SENTRY_LARAVEL_DSN` value directly on the host (in `.env.dev`/`.env.stg`,
never in chat or committed to this repo — same "real secrets live in `secrets/*.txt` on the host"
discipline this repo already follows for database credentials).

## Verify after installing

```bash
php artisan test tests/  # confirm nothing broke
php artisan pulse:check  # if this command exists in the installed version — verify
```

Visit `/pulse` as an admin user and confirm the dashboard loads (per `config/pulse.php`'s
`authorize` callback — an admin session should see it, anything else should be denied).
Trigger a deliberate test exception and confirm it reaches the configured Sentry project with the
`correlation_id` and `image_digest` tags attached, and that no NIK/KK-shaped digit sequence or
signed-URL query string appears in the captured event.
```

- [ ] **Step 7: Verify what's safe to check without composer**

Run `php -l` on every new/modified PHP file via the pinned image (same throwaway-container pattern as Task 2's Step 2):
```bash
docker run --rm --network e2e-admin-vendor-net \
  -v $(pwd):/var/www/html \
  --user "$(id -u):$(id -g)" \
  ghcr.io/andrianm28/makam-app@sha256:fd978e4cd3706ebd7fab85654cb806bfa7424086371c8c0a793f7e141d032d51 \
  sh -c 'php -l config/sentry.php && php -l config/pulse.php && php -l bootstrap/app.php'
```
Expected: `No syntax errors detected` for all three. Note: this CANNOT catch a reference to a class from an uninstalled package (`\Sentry\Event`, `\Sentry\Laravel\Integration`, `\Laravel\Pulse\Recorders\*`) failing to autoload — that only surfaces once the real `composer update` runs. Say so explicitly in this task's report; do not claim more verification than `php -l`'s real scope covers.

Run pint on the same files.

Run `bash ci/verify-docs.sh` for the new docs file.

- [ ] **Step 8: Commit**

```bash
git add composer.json config/sentry.php config/pulse.php bootstrap/app.php docs/operations/composer-sentry-pulse-install.md
git add .env.example 2>/dev/null || true
git commit -m "feat(ops): prepare Sentry + Pulse installation (composer.lock not regenerated)

Adds sentry/sentry-laravel and laravel/pulse to composer.json and
writes all consuming config/code: before_send NIK/KK/signed-URL
scrubbing plus correlation-ID tagging for Sentry (per the beta-
launch plan's original Lane E2 spec), a real Pulse config with
access-controlled dashboard authorization. composer.lock is
deliberately untouched — regenerating it needs real composer+network,
which CLAUDE.md forbids running on this host. A human runs the
2 commands docs/operations/composer-sentry-pulse-install.md names
to actually install and verify."
```

---

## Verification

| Check | Command | Expected |
|---|---|---|
| Docs gates | `bash ci/verify-docs.sh` | All gates PASS |
| PHP syntax on every new/modified file | `php -l <file>` via the pinned image | No syntax errors |
| Pint | `vendor/bin/pint --test <files>` via the pinned image | PASS |
| `composer.lock` untouched | `git diff --stat` after Task 4 | `composer.lock` does NOT appear in the diff |
| `package-lock.json` untouched | `git diff --stat` after Task 3 | `package-lock.json` does NOT appear in the diff (Task 3 only adds a new doc file) |
| Real CI run on this branch's own PR | (push, check GitHub Actions) | Green — this WILL still show the pre-existing npm audit `\|\| true` gap and the two new composer requires as unresolved-in-lockfile until a human completes Tasks 3/4's human step; this is expected, not a regression this plan introduces |

Note: pushing this branch's CI run will very likely FAIL the "Install dependencies from lockfile"
step for the PHP job once Task 4's `composer.json` edit lands, precisely because `composer.lock`
doesn't yet list the 2 new packages — CI's own "Validate composer.json against composer.lock"
gate exists to catch exactly this state. **This is expected and correct, not a bug in this plan**:
it is the real, honest signal that a human step is still required before this specific commit's
CI can go green. Do not attempt to work around it (e.g., by reverting Task 4's `composer.json`
edit to make CI pass) — the SDD controller's job here is to get real review on the PREPARED work
(config/sentry.php, config/pulse.php, the composer.json intent), present this expected CI
red/blocked state honestly to the user, and let the human step (`composer update`) be what
actually turns it green, matching the "prepared, not executed" pattern's own honest-blocked
status established by every other host-only runbook this repo has.
