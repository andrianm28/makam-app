# Phase 3 Engineering Prep Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Do the 3 pieces of "production graduation" work that are genuinely engineering-doable right now, independent of the 6 human/business decisions (object-storage provider, managed-Postgres provider, payment credentials, infra procurement, DPIA/legal, FIN-DEC approvals) still blocking the rest of Phase 3.

**Architecture:** No application code changes. Two new "prepared, not executed" operational runbooks following this repo's own established convention (`docs/operations/runbooks/deploy-stg-vhost.md`), plus one new PHPUnit test closing a real, named test-coverage gap. Nothing here touches live infrastructure.

**Tech Stack:** Markdown runbooks (this repo's own house style), PHPUnit + real Postgres 18 for the migration-rollback test.

**Spec:** No formal spec document. This plan implements 3 items the user explicitly chose from a scoping pass this session ("start the engineering-doable prep now"): a production deploy runbook, a rollback procedure, and migration-rollback test coverage — each independently re-verified against the current codebase (23 Aug 2026, main repo at commit 2dc1484) before being written into task form below.

## Global Constraints

- Every new/modified PHP file needs `declare(strict_types=1);`.
- Follow this repo's "prepared, not executed" convention exactly (`docs/operations/runbooks/deploy-stg-vhost.md` is the house-style template): a `## Status` header stating what has and hasn't been executed, real commands throughout, explicit named blockers where a step genuinely cannot run yet — never invented values standing in for a real decision.
- `AGENTS.md` §Infrastructure-agent execution: never report `PASS` for a check that was not executed; use `BLOCKED`/`NOT TESTED` explicitly. Neither new runbook's commands will be executed as part of this plan — say so plainly in both.
- `AGENTS.md` §Infrastructure-agent execution: human review is mandatory before security/authorization/financial/DNS/firewall/production-affecting changes. Nothing in this plan touches live infrastructure — both runbooks are documents only, and the migration-rollback test runs against a disposable test database, not any real environment.
- Do not silently invent a production target host, DNS name, cloud provider, or credential scheme. Every fact this plan's tasks state as unresolved (OQ-4: object-storage provider; OQ-6: managed-PostgreSQL provider; production-infra-procurement itself) must stay marked as genuinely blocked in the new runbooks, not papered over.
- Composer/npm builds do not run on this host — CI only. This plan needs neither.
- No AWS. `docs/operations/deployment.md` §3's intended production topology (managed Postgres, managed Redis, S3-compatible private storage) is provider-agnostic; do not name AWS specifically anywhere in the new runbooks.

## Context already established (do not re-derive)

- **No production infrastructure exists.** ADR-0027 item 8: "the environment is not accepted as production." Only a staging deploy runbook exists (`deploy-stg-vhost.md`), never executed.
- **`docs/operations/deployment.md` §3** names the intended production topology (CDN/WAF → reverse proxy/LB → PHP-FPM → managed Postgres 18 + managed Redis 8.2 + private S3-compatible storage, Ubuntu 24.04 LTS) and **§4** lists 11 required configuration-isolation classes (distinct `APP_KEY`, DB/Redis credentials and prefixes, cookie name, Horizon prefix, queue names, storage bucket, sandbox-vs-production K1-K8 credentials, etc.) — real and citable, but every value is currently a placeholder since no production secrets exist yet.
- **Two open questions block a real target host**: OQ-4 (object-storage provider, cited in `docs/planning/sprint-plan.md:191` and `docs/testing/release-gates.md:99,114`) and **OQ-6** (managed-PostgreSQL provider — `docs/planning/sprint-plan.md`'s own text: *"ADR-0021 requires managed + PITR. Undecided — blocks production planning. Sprint 16. Before Sprint 10."*). Neither is resolved. Both must be named explicitly as blockers in Task 1's runbook — do not invent a provider choice for either.
- **CI's real image-promotion mechanism** (`.github/workflows/ci.yml`'s "Build and push image" job): builds once per commit, tags `ghcr.io/<repo-lowercased>:sha-<12-char-SHA>` and `ghcr.io/<repo-lowercased>:<branch-slug>`, generates an SPDX SBOM keyed on the real image digest (`${image}@${digest}`) — the digest is the actual immutable, content-addressed reference a deploy pins to, not either tag. This is real and already running in CI today.
- **The real `APP_IMAGE` promotion pattern already exists and works** in `docs/operations/examples/docker-compose.dev-stg.yml`: 5 services (`dev-web`, `stg-web`, `stg-horizon`, `dev-worker`, `stg-batch-worker`) each declare `image: ${APP_IMAGE:?APP_IMAGE is required}` — promoting a new build to dev/staging is genuinely just setting `APP_IMAGE` to a new digest reference and re-running compose. No production-equivalent compose file exists yet (that's blocked on infra procurement), but the mechanism itself is proven and real.
- **`docs/operations/ci-cd-and-release.md` §6/§7** are the real, already-approved rollback principles this plan's Task 2 translates into an executable procedure: §6 lists 7 rollback triggers (elevated 5xx, failed critical journey, cross-scope auth defect, payment/journal inconsistency, outbox/queue blockage, DB saturation/migration regression, document exposure); §7 lists 7 rollback actions (close gate → stop unsafe consumers → roll artifact back → keep forward-compatible schema → reprocess outbox after idempotency review → reconcile payment events → record incident). §1's principle: "Rollback means application rollback plus safe schema compatibility; financial/audit history is never deleted." §4: "Production rollback must not depend on destructive `down()` migrations."
- **No rollback procedure document exists anywhere in this repo today** — confirmed via grep across `docs/operations/`. `docs/planning/sprint-plan.md` names "rollback rehearsed" as a bare one-line Sprint 10 deliverable with no detail. `docs/operations/runbooks.md`'s 13 sections are product/ops incident response (payment webhook, document exposure, host memory pressure, etc.), not deploy rollback — a genuinely separate document is needed, not an extension of that file.
- **No migration-rollback test exists anywhere.** `grep -rl "migrate:rollback\|Artisan::call('migrate" tests/` returns only the `*TwoConnectionTest.php` files' `migrate:fresh` cleanup calls (a documented test-isolation mechanism, not a rollback-behavior test). `docs/testing/release-gates.md`'s own §H box text: "migration (rollback-specific) — no dedicated migration-rollback test found."
- **The real candidate migration**: `database/migrations/2026_08_22_100000_fix_customer_and_uploader_identity_columns.php` — converts 4 columns (`subscriptions.customer_id`, `service_acceptances.customer_id`, `service_complaints.customer_id`, `work_evidence.uploaded_by`) from a broken bare `uuid` type to a proper `foreignId(...)->constrained('users')`. Its `down()` reverses all 4 (`dropConstrainedForeignId()` then re-add a bare `uuid()` column) — a real, non-trivial, multi-table reversal. Its own doc block confirms zero production-data risk: none of the 4 tables has ever been seeded/populated, so this migration's rollback can be tested purely at the schema level (column type + constraint existence), with no data-preservation assertion needed.

---

### Task 1: Production deploy runbook (prepared, not executed)

**Files:**
- Create: `docs/operations/runbooks/deploy-production.md`

**Interfaces:**
- Consumes: `docs/operations/deployment.md` §3 (topology)/§4 (config-isolation classes), `docs/operations/ci-cd-and-release.md` §2 (CI pipeline)/§5 (deployment sequence)/§8 (deployment checks), the real `docs/operations/examples/docker-compose.dev-stg.yml` `APP_IMAGE` pattern, ADR-0027 (the "not accepted as production" boundary this runbook exists on the far side of).
- Produces: nothing consumed by later tasks — this is a standalone document.

- [ ] **Step 1: Write the runbook, mirroring `deploy-stg-vhost.md`'s real structure exactly**

Create `docs/operations/runbooks/deploy-production.md`:

```markdown
# Runbook: Deploy to Production — v0.1

## Status

**Prepared, not executed, and cannot be executed today.** Unlike `deploy-stg-vhost.md` (blocked only on a DNS decision), this runbook has two structural blockers with no target to execute against yet:

- **OQ-4 (object-storage provider): undecided.** `docs/planning/sprint-plan.md:191`, `docs/testing/release-gates.md:99,114`. Production requires private S3-compatible storage per `docs/operations/deployment.md` §3 — no provider is chosen, so no real storage endpoint/credentials exist to reference below.
- **OQ-6 (managed PostgreSQL provider): undecided.** `docs/planning/sprint-plan.md`'s own text: "ADR-0021 requires managed + PITR. Undecided — blocks production planning." No managed database exists to migrate against.
- **No production host, DNS name, or infrastructure-procurement decision exists at all.** ADR-0027 item 8 remains in force: the shared dev/staging host "is not accepted as production."

No command in this document has been run. It cannot be run correctly until the three items above are resolved — this document prepares the STEPS that will apply once they are, using this repository's own real, already-working mechanisms wherever one exists today, and marks every step that still needs a real value with an explicit `[BLOCKED: <what decision resolves this>]` marker rather than a placeholder that reads as real.

## Scope

Deploy a CI-built, digest-pinned application image to a production environment, once one exists. Explicitly NOT covered here: provisioning the production environment itself (a separate, infra-procurement-led effort once OQ-4/OQ-6/the hosting decision land), or the object-storage/managed-database setup themselves.

Related documents:
- `docs/operations/deployment.md` (`../deployment.md` from this file's own real location) §3 (topology), §4 (config isolation), §6 (this document is the "canonical process" it points to)
- `docs/operations/ci-cd-and-release.md` (`../ci-cd-and-release.md` from this file's own real location) §2 (CI pipeline this runbook's artifact comes from), §5 (deployment sequence this runbook instantiates), §8 (deployment checks)
- [`../../adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md`](../../adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md) — the boundary this runbook is on the far side of (item 8: dev/staging "is not accepted as production")
- [`deploy-stg-vhost.md`](deploy-stg-vhost.md) — the sibling staging runbook this one's structure mirrors

## Preconditions — do not proceed until all are true

1. **OQ-4 resolved**: a real object-storage provider is chosen and provisioned, with real credentials available through this project's secret-management mechanism (never committed to this repository).
2. **OQ-6 resolved**: a real managed-PostgreSQL provider is chosen and provisioned, with PITR enabled per ADR-0021, and real connection credentials available the same way.
3. **A real production host/hosting decision exists** — whatever infrastructure-procurement decision resolves ADR-0027's boundary (a specific cloud provider's managed compute, a dedicated server, etc. — this runbook does not assume which).
4. **Every config-isolation class in `deployment.md` §4 has a real, provisioned value**: distinct `APP_KEY`; distinct database credentials (not shared with dev/staging); distinct Redis credentials/prefix; distinct cookie name; distinct Horizon prefix and queue names; the production object-storage bucket from precondition 1; production-tier (K1-K8) provider credentials for every external integration (never the sandbox credentials dev/staging use).
5. **CI is green on the commit being promoted.** Verify via:
   ```bash
   gh pr checks <PR-number>
   # or, once merged:
   gh run list --branch <base-branch> --limit 1
   ```

## Step 1 — Identify the real artifact to promote

The immutable reference is the image digest, not either moving tag (`.github/workflows/ci.yml`'s "Build and push image" job — see this runbook's own citation above):

```bash
gh run view <run-id> --log | grep -A2 "Generate SBOM"
# or, from the registry directly, once you have registry access:
docker buildx imagetools inspect ghcr.io/<repo-lowercased>:sha-<12-char-SHA>
```

Record the real `ghcr.io/<repo>@sha256:<digest>` reference — this is what every later step pins to, never a moving tag.

## Step 2 — [BLOCKED: OQ-4/OQ-6/infra-procurement] Provision environment configuration

Once preconditions 1-4 are real: write the production environment's config file (mirroring `docker-compose.dev-stg.yml`'s `.env.dev`/`.env.stg` pattern, but for production — no such file can be drafted with real values here, since every value in it depends on the blocked decisions above). Confirm every value in `deployment.md` §4's list is set and genuinely distinct from dev/staging's own values (never copy a dev/staging secret into production).

## Step 3 — Promote the artifact

Once a real production compose/deployment mechanism exists (mirroring the proven `APP_IMAGE` pattern already real in `docker-compose.dev-stg.yml`):

```bash
export APP_IMAGE="ghcr.io/<repo-lowercased>@sha256:<digest-from-step-1>"
docker compose -f <production-compose-file> up -d
```

`<production-compose-file>` does not exist yet — creating it is part of the infra-procurement execution phase, once precondition 3 resolves; this runbook names the mechanism it will use (identical to the real, working dev/staging one), not a file that can be written today without a real target.

## Step 4 — Run migrations

Per `ci-cd-and-release.md` §5 step 5 ("Run safe migrations using direct DB connection") and §4's expand/contract discipline:

```bash
docker compose -f <production-compose-file> exec <app-service> php artisan migrate --force
```

Confirm the migration set being applied only contains expand-phase or already-safe changes per §4 — a contract-phase migration (dropping an old column/path) requires the separate approval §4 names, not routine deploy sign-off.

## Step 5 — Restart application processes gracefully

Per `ci-cd-and-release.md` §5 step 6:

```bash
docker compose -f <production-compose-file> exec <app-service> php artisan horizon:terminate
docker compose -f <production-compose-file> restart <app-service> <horizon-service> <scheduler-service>
```

Horizon's graceful terminate (not a hard kill) lets in-flight jobs finish or safely retry, per this repo's own established Horizon deployment discipline (`docs/architecture/queue-and-outbox.md` §9, already cited elsewhere in this codebase).

## Step 6 — Run the deployment checks

Per `ci-cd-and-release.md` §8, every one of these against the real production URL once it exists:

```bash
curl -sS https://<production-domain>/health/live
curl -sS https://<production-domain>/health/ready
```

Plus: authenticated smoke checks for each Filament panel (admin, vendor), the public homepage and booking-draft check, outbox publisher/queue-worker confirmation, and — for a release with a `§5.1`-style manual step named in `ci-cd-and-release.md` — confirmation that step was actually executed, not merely that the deploy succeeded.

## Step 7 — Enable gates progressively; observe the release window

Per `ci-cd-and-release.md` §5 steps 9-10. No feature gate is force-opened by this runbook — gate state changes go through the existing, real Feature Gate admin panel mechanism this codebase already has (`App\Platform\FeatureGate`), with the same audited human action `G-PAY-01`'s own activation already requires.

## Rollback

See [`rollback-deploy.md`](rollback-deploy.md) — a dedicated procedure, not duplicated here.

## Finding surfaced, not resolved

This runbook cannot name a real production domain, compose file path, or app-service name anywhere above — every `<production-...>` placeholder in Steps 2-6 stands for a value that genuinely does not exist yet, not an oversight. Resolving OQ-4, OQ-6, and the infra-procurement decision is what turns each placeholder into a real, executable value; this document's job is to have every OTHER step (artifact identification, migration sequencing, restart discipline, deployment checks, gate rollout) already correct and ready the moment that happens.
```

- [ ] **Step 2: Cross-check every citation against the real, current files**

Before committing, re-read `docs/operations/deployment.md` §3/§4, `docs/operations/ci-cd-and-release.md` §2/§5/§8, and `docs/operations/examples/docker-compose.dev-stg.yml`'s real service names one more time, and confirm every fact stated in Step 1's runbook still matches (section numbers, exact config-isolation list, exact CI job/step names). This document makes load-bearing factual claims about other documents — if any citation is wrong, fix it before committing, don't transcribe blind.

- [ ] **Step 3: Run doc gates**

```bash
bash ci/verify-docs.sh
```

Expected: `RESULT: ALL DOC GATES PASS`.

- [ ] **Step 4: Commit**

```bash
git add docs/operations/runbooks/deploy-production.md
git commit -m "docs(ops): prepare a production deploy runbook (blocked on OQ-4/OQ-6/infra procurement)"
```

---

### Task 2: CI/CD rollback procedure

**Files:**
- Create: `docs/operations/runbooks/rollback-deploy.md`

**Interfaces:**
- Consumes: `docs/operations/ci-cd-and-release.md` §1/§6/§7 (the principles and conceptual trigger/action lists this task translates into concrete commands), the same real `APP_IMAGE`/digest mechanism Task 1 cites.
- Produces: referenced by Task 1's runbook (its own `## Rollback` section points here) — write this task before or independently of Task 1's Step 1's cross-reference; order between Task 1 and Task 2 does not matter, but both must exist before either references the other by a working relative link.

**Scope note — unlike Task 1, this is NOT blocked on OQ-4/OQ-6.** The rollback mechanism (re-pointing `APP_IMAGE` to a previous digest) is identical for staging and production — it's already real and usable against the existing dev/staging host today. This runbook is written generically (parameterized by environment) so it's immediately real for staging and directly reusable for production once that exists, rather than needing a rewrite later.

- [ ] **Step 1: Write the procedure**

Create `docs/operations/runbooks/rollback-deploy.md`:

```markdown
# Runbook: Roll Back a Deploy — v0.1

## Status

**Prepared, not executed.** This document translates `docs/operations/ci-cd-and-release.md` §6 (rollback triggers) and §7 (rollback actions) — both already approved — into concrete, executable steps against this repository's real image-promotion mechanism. Unlike `deploy-production.md`, this procedure needs no new infrastructure to become real: the same `APP_IMAGE` digest-pinning mechanism already runs in dev/staging today (`docs/operations/examples/docker-compose.dev-stg.yml`). No command in this document has been run as part of writing it; a real rehearsal against the live dev/staging host is a separate, explicitly human-authorized action (see `docs/testing/release-gates.md` §H's still-open "rollback rehearsed" box) — this document prepares that rehearsal's script, it does not perform it.

## Scope

What to do when one of `ci-cd-and-release.md` §6's rollback triggers fires against a real deployed environment (dev/staging today; production once it exists). Covers application-artifact rollback only — see `docs/operations/backup-and-restore-runbook.md` for data/backup recovery, a separate concern §1's own principle keeps distinct ("rollback means application rollback plus safe schema compatibility; financial/audit history is never deleted").

Related documents:
- `docs/operations/ci-cd-and-release.md` (`../ci-cd-and-release.md` from this file's own real location) §1, §6, §7 — the approved principles this procedure instantiates
- [`deploy-production.md`](deploy-production.md) / [`deploy-stg-vhost.md`](deploy-stg-vhost.md) — the deploy procedures this reverses
- [`../../architecture/queue-and-outbox.md`](../../architecture/queue-and-outbox.md) §7-8 — outbox retry/replay semantics referenced in Step 5 below

## When to use this — the 7 real triggers (`ci-cd-and-release.md` §6)

- Elevated 5xx/error rate
- A failed critical journey (booking, payment, renewal)
- A cross-scope authorization defect
- A payment/journal inconsistency
- Outbox/critical-queue blockage
- Database saturation or a migration regression
- A document-exposure/security issue

Any one of these firing after a deploy is grounds to execute this procedure. This is an operational judgment call, not an automated trigger — a human decides the rollback is warranted.

## Preconditions

1. **Identify the last known-good digest.** The immutable reference this rollback re-pins to:
   ```bash
   gh run list --branch <base-branch> --status success --limit 5
   # then, for the last known-good run:
   gh run view <run-id> --log | grep -A2 "Generate SBOM"
   ```
   Record `ghcr.io/<repo-lowercased>@sha256:<known-good-digest>` — never the branch-slug tag, which has moved since.
2. **Confirm this rollback does not require reverting an already-contracted migration.** Per `ci-cd-and-release.md` §4: "Production rollback must not depend on destructive `down()` migrations." If the incident traces to a migration in the release being rolled back, confirm it was expand-phase (additive) — an application-artifact rollback with the expanded schema still present is always safe; rolling the SCHEMA back too is a separate, higher-risk decision this procedure does not cover.

## Step 1 — Close the affected gate (§7 action 1)

If the incident is scoped to a specific feature (payment, a specific journey), close its Feature Gate through the existing, real admin panel mechanism — the same audited action every gate flip in this codebase already requires, never a direct database write:

```bash
# Via the real Feature Gate admin UI, with fresh re-authentication —
# not a CLI/script shortcut; gate flips are deliberately human-in-the-loop
# per this codebase's own G-PAY-01 precedent.
```

## Step 2 — Stop unsafe consumers, preserve durable events (§7 action 2)

Pause the specific queue(s) implicated, without losing already-enqueued work:

```bash
docker compose -f <compose-file> exec <app-service> php artisan horizon:pause
```

`horizon:pause` stops new job processing while leaving already-claimed and already-queued jobs intact (Horizon's own documented behavior) — this is deliberately NOT `horizon:terminate` (which would let in-flight jobs finish first) nor a hard container kill (which could leave a job claimed-but-unprocessed with no clean retry path).

## Step 3 — Roll the application artifact back (§7 action 3)

The core action, using the real, already-proven `APP_IMAGE` mechanism:

```bash
export APP_IMAGE="ghcr.io/<repo-lowercased>@sha256:<known-good-digest-from-preconditions>"
docker compose -f <compose-file> up -d <app-service> <horizon-service> <scheduler-service>
```

This is the exact same promotion mechanism a forward deploy uses (`deploy-production.md` Step 3 / the dev-stg compose file's own `APP_IMAGE` pattern) — a rollback is a promotion to an OLDER digest, not a structurally different operation.

## Step 4 — Confirm schema compatibility (§7 action 4)

Per the precondition check above: if the rolled-back artifact's code no longer expects a column/table the current (un-rolled-back) schema has, that's fine — expand-phase migrations are additive, so older code ignoring a newer column is safe. If the rolled-back artifact's code expects something the current schema does NOT have (this would only happen if a contract-phase migration already ran), STOP — this procedure does not cover reversing a contract migration; escalate for a decision on the schema itself, separate from the artifact rollback.

## Step 5 — Resume consumers, reprocess after idempotency review (§7 action 5)

```bash
docker compose -f <compose-file> exec <app-service> php artisan horizon:continue
```

Before resuming: confirm any job that was mid-processing when Step 2 paused the queue is safe to retry — per `docs/architecture/queue-and-outbox.md` §7's at-least-once delivery semantics, every real consumer in this codebase is already required to be idempotent on `event_id`/a domain idempotency key, so a resumed retry should be safe by construction; this step is a human sanity check on that assumption for the SPECIFIC incident, not a blanket skip.

## Step 6 — Reconcile payment/provider events received during the incident window (§7 action 6)

If the incident touched the payment path: cross-check `provider_events`/`payment_sessions` rows with timestamps inside the incident window against the payment provider's own dashboard/API for any event this system might have missed or double-processed during the rollback window. This is a manual reconciliation step — no automated tool for this exists in this codebase today.

## Step 7 — Record the incident (§7 action 7)

Record: the trigger that fired, the digest rolled back from and to, the time window, affected references (order/payment/renewal ids), and corrective action taken — in whatever incident record this project uses (see `docs/operations/runbooks.md`'s existing 13 incident-response sections for this codebase's established documentation pattern for an incident write-up, even though none of those 13 sections is this one).

## Verification after rollback

Re-run `deploy-production.md` Step 6's deployment checks (or the staging-equivalent) against the now-rolled-back environment — a rollback is itself a deploy and needs the same post-deploy health confirmation, not an assumption that reverting fixes everything.
```

- [ ] **Step 2: Cross-check citations**

Confirm `ci-cd-and-release.md` §6/§7's exact wording and `queue-and-outbox.md` §7-8's real section content one more time before committing.

- [ ] **Step 3: Run doc gates**

```bash
bash ci/verify-docs.sh
```

- [ ] **Step 4: Commit**

```bash
git add docs/operations/runbooks/rollback-deploy.md
git commit -m "docs(ops): prepare a rollback procedure translating ci-cd-and-release.md §6-7 into real commands"
```

---

### Task 3: Migration-rollback test coverage

**Files:**
- Create: `tests/Feature/Database/Migrations/FixCustomerAndUploaderIdentityColumnsRollbackTest.php`

**Interfaces:**
- Consumes: `database/migrations/2026_08_22_100000_fix_customer_and_uploader_identity_columns.php` (the real migration under test — an anonymous class returned by the file itself, exactly as every Laravel migration file is structured).
- Produces: nothing consumed by later tasks.

**Why direct instantiation, not `Artisan::call('migrate:rollback')`**: `RefreshDatabase` runs every migration in the repo (there are dozens more after this one, e.g. this session's own `2026_08_23_120000_seed_external_renewal_fixture.php`), all effectively in one batch — `migrate:rollback --step=1` would undo the MOST RECENT migration, not this specific one. This migration's own file, like every Laravel migration, `return`s a `new class extends Migration { ... }` instance when `require`d — instantiating it directly and calling `->down()`/`->up()` tests this migration's own reversal in isolation, regardless of how many migrations have run since.

**Why no data-seeding is needed**: the migration's own doc block (already read in full during planning) confirms zero production-data risk — none of the 4 tables (`subscriptions`, `service_acceptances`, `service_complaints`, `work_evidence`) has ever been populated by any seed/fixture. This test proves the SCHEMA reversal is correct (column type + constraint existence, via real `information_schema` queries against Postgres), not data preservation across a reversal — there is no data to preserve.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Database/Migrations/FixCustomerAndUploaderIdentityColumnsRollbackTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The one real, previously-uncovered gap `docs/testing/release-gates.md`
 * §H names directly: "migration (rollback-specific) — no dedicated
 * migration-rollback test found." Tests
 * `2026_08_22_100000_fix_customer_and_uploader_identity_columns.php`'s
 * `down()` in isolation — see this class's own doc block for why direct
 * instantiation is used instead of `Artisan::call('migrate:rollback')`
 * (dozens of later migrations would make `--step=1` undo the wrong one)
 * and why no row is seeded first (the migrated tables have never held
 * real data — confirmed in the migration's own doc block — so this test
 * proves the schema reversal, not data preservation).
 *
 * Postgres DDL is transactional, and `RefreshDatabase` wraps each test in
 * a transaction — so `down()`'s real `dropConstrainedForeignId()`/
 * `uuid()` calls below are automatically rolled back at the end of this
 * test, same as any other write. `up()` is called again at the end purely
 * so the test leaves the schema in the same state it found it in, as a
 * defensive measure in case that transactional-DDL assumption is ever
 * wrong on a future Postgres version.
 */
final class FixCustomerAndUploaderIdentityColumnsRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION_PATH = 'database/migrations/2026_08_22_100000_fix_customer_and_uploader_identity_columns.php';

    /**
     * @return array<int, array{string, string}>
     */
    public static function columnProvider(): array
    {
        return [
            ['subscriptions', 'customer_id'],
            ['service_acceptances', 'customer_id'],
            ['service_complaints', 'customer_id'],
            ['work_evidence', 'uploaded_by'],
        ];
    }

    public function test_down_reverts_all_four_columns_to_a_bare_uuid_with_no_foreign_key(): void
    {
        $migration = $this->loadMigration();

        foreach (self::columnProvider() as [$table, $column]) {
            self::assertSame(
                'bigint',
                $this->columnDataType($table, $column),
                "{$table}.{$column} should be bigint (the foreignId shape) before down() runs"
            );
            self::assertTrue(
                $this->hasForeignKey($table, $column),
                "{$table}.{$column} should have a foreign key constraint before down() runs"
            );
        }

        $migration->down();

        foreach (self::columnProvider() as [$table, $column]) {
            self::assertSame(
                'uuid',
                $this->columnDataType($table, $column),
                "{$table}.{$column} should revert to uuid after down()"
            );
            self::assertFalse(
                $this->hasForeignKey($table, $column),
                "{$table}.{$column} should have no foreign key constraint after down()"
            );
        }

        // Leave the schema as RefreshDatabase's next test expects it —
        // defensive, see this class's own doc block.
        $migration->up();

        foreach (self::columnProvider() as [$table, $column]) {
            self::assertSame(
                'bigint',
                $this->columnDataType($table, $column),
                "{$table}.{$column} should be back to bigint after re-running up()"
            );
        }
    }

    private function loadMigration(): Migration
    {
        return require base_path(self::MIGRATION_PATH);
    }

    private function columnDataType(string $table, string $column): string
    {
        $row = DB::selectOne(
            'SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$table, $column]
        );

        self::assertNotNull($row, "Column {$table}.{$column} was not found in information_schema");

        return $row->data_type;
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        $row = DB::selectOne(
            <<<'SQL'
                SELECT COUNT(*) AS count
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name
                WHERE tc.constraint_type = 'FOREIGN KEY'
                    AND tc.table_name = ?
                    AND kcu.column_name = ?
                SQL,
            [$table, $column]
        );

        return ((int) $row->count) > 0;
    }
}
```

- [ ] **Step 2: Run test to verify it fails for the right reason first (sanity check)**

Before trusting the test, temporarily verify it actually exercises real behavior — run it once as written; it should PASS immediately since `up()` has already run via `RefreshDatabase` before the test body starts (this migration's `up()` state IS the starting condition). This step is a sanity check, not a red-green cycle in the usual TDD sense — there is no "make it pass" step separate from writing it correctly, since the assertions describe real, already-true behavior once the migration exists. Confirm this reasoning is right by deliberately breaking one assertion first (e.g., temporarily assert `'uuid'` instead of `'bigint'` for the pre-down() check) and confirming the test genuinely fails, then revert to the correct assertions.

```bash
vendor/bin/phpunit --filter test_down_reverts_all_four_columns_to_a_bare_uuid_with_no_foreign_key tests/Feature/Database/Migrations/FixCustomerAndUploaderIdentityColumnsRollbackTest.php
```

Expected after the deliberate-break-then-revert check above: PASS, against real Postgres (not SQLite — `information_schema.columns.data_type` reports differently or the query may not even work the same way on SQLite; this test is meaningless without real Postgres).

- [ ] **Step 3: Confirm no regression in the broader Feature suite from RefreshDatabase transaction behavior**

Run the full `tests/Feature/Database/` directory (this is a new subdirectory — confirm it doesn't collide with anything) plus a couple of neighboring, unrelated Feature test classes that also use `RefreshDatabase`, run immediately after this one in the same process, to confirm the schema genuinely reverted correctly (this test's own `up()` re-run at the end is the safety net, but verify it actually worked):

```bash
vendor/bin/phpunit tests/Feature/Database/Migrations/FixCustomerAndUploaderIdentityColumnsRollbackTest.php tests/Feature/Domain/VendorFulfillment/
```

Expected: all pass, no leaked schema state from this test breaking a sibling test that also touches `subscriptions`/`service_acceptances`/`service_complaints`/`work_evidence`.

- [ ] **Step 4: `php -l` and pint**

```bash
php -l tests/Feature/Database/Migrations/FixCustomerAndUploaderIdentityColumnsRollbackTest.php
vendor/bin/pint --test tests/Feature/Database/Migrations/FixCustomerAndUploaderIdentityColumnsRollbackTest.php
```

- [ ] **Step 5: Update `docs/testing/release-gates.md`'s relevant §H box**

Find the box with the 6-part compound claim (starts `- [ ] Authorization, audit, upload, migration, backup/restore, and rollback tests pass. — A 6-part compound claim...`). Update its "migration" sub-claim from "no dedicated migration-rollback test found" to cite the new test by name, while leaving the other 5 sub-claims' text untouched (this box is compound and stays unchecked overall regardless, since backup/restore and CI/CD rollback rehearsal remain genuinely unresolved — this step closes exactly one of its 6 named parts, not the whole box).

- [ ] **Step 6: Run doc gates**

```bash
bash ci/verify-docs.sh
```

- [ ] **Step 7: Commit**

```bash
git add tests/Feature/Database/Migrations/FixCustomerAndUploaderIdentityColumnsRollbackTest.php docs/testing/release-gates.md
git commit -m "test(migrations): add rollback coverage for the identity-column type fix"
```

---

## Verification

| Task | Done when |
|---|---|
| 1 | `deploy-production.md` exists, follows the house style, every genuinely-blocked step is marked `[BLOCKED: ...]` rather than papered over, `ci/verify-docs.sh` passes |
| 2 | `rollback-deploy.md` exists, translates `ci-cd-and-release.md` §6/§7 into real commands using the proven `APP_IMAGE` mechanism, `ci/verify-docs.sh` passes |
| 3 | The new test passes against real Postgres, proving `2026_08_22_100000_...`'s `down()` genuinely reverts all 4 columns' type and drops their FK constraints; the relevant release-gates.md sub-claim is updated with a real citation |

Final whole-branch review checks cross-task consistency — specifically, that Task 1's runbook and Task 2's runbook correctly cross-reference each other (Task 1's `## Rollback` section points to Task 2's file, and both exist), and that neither runbook's "prepared, not executed" framing drifts into accidentally claiming something was verified that wasn't.

## Execution

Execute via `superpowers:subagent-driven-development` — fresh implementer subagent per task, task-scoped review, one final whole-branch review before PR. Standing execution mode for this session; do not ask the user to choose between subagent-driven and inline execution.
