# Runbook: Roll Back a Deploy — v0.1

## Status

**Prepared, not executed.** This document translates `docs/operations/ci-cd-and-release.md` §6 (rollback triggers) and §7 (rollback actions) — both already approved — into concrete, executable steps against this repository's real image-promotion mechanism. Unlike `deploy-production.md`, this procedure needs no new infrastructure to become real: the same `APP_IMAGE` digest-pinning mechanism already runs in dev/staging today (`docs/operations/examples/docker-compose.dev-stg.yml`). No command in this document has been run as part of writing it; a real rehearsal against the live dev/staging host is a separate, explicitly human-authorized action (see `docs/testing/release-gates.md` §H's still-open "rollback rehearsed" box) — this document prepares that rehearsal's script, it does not perform it.

## Scope

What to do when one of `ci-cd-and-release.md` §6's rollback triggers fires against a real deployed environment (dev/staging today; production once it exists). Covers application-artifact rollback only — see `docs/operations/backup-and-restore-runbook.md` for data/backup recovery, a separate concern §1's own principle keeps distinct ("rollback means application rollback plus safe schema compatibility; financial/audit history is never deleted").

Related documents:
- `docs/operations/ci-cd-and-release.md` (`../ci-cd-and-release.md` from this file's own real location) §1, §6, §7 — the approved principles this procedure instantiates
- [`deploy-production.md`](deploy-production.md) — the deploy procedure this reverses, using the identical `APP_IMAGE` promotion mechanism Step 3 below describes / [`deploy-stg-vhost.md`](deploy-stg-vhost.md) covers the staging nginx vhost, a different concern (routing, not artifact promotion) — not the deploy procedure this runbook reverses.
- [`../../architecture/queue-and-outbox.md`](../../architecture/queue-and-outbox.md) §7-8 — outbox retry/replay semantics referenced in Step 5 below

**Environment note.** This procedure is parameterized by environment, but not every environment runs every component persistently. Per `docs/operations/dev-staging-environment.md` §9: only the staging profile runs a persistent Horizon process (`stg-horizon`) and a persistent scheduler (host cron invoking `schedule:run` once a minute); the development profile has no always-on Horizon or scheduler at all — both are run manually there. Where a step below references `<horizon-service>` or a scheduler restart, treat it as inapplicable to an environment that doesn't run that component persistently.

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
   gh run view <run-id> --log | grep -oE 'ghcr\.io/[^[:space:]"]+@sha256:[a-f0-9]{64}' | sort -u
   ```
   `.github/workflows/ci.yml`'s "Generate SBOM" step is the earliest point the digest (`steps.build.outputs.digest`) is used; the later "Record image reference" step only writes it to the job summary (`$GITHUB_STEP_SUMMARY`), not the plain step log — so grep for the `ghcr.io/...@sha256:...` pattern itself across the whole log rather than anchoring to one step name and a fixed line window.
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

Pause the specific queue(s) implicated, without losing already-enqueued work — applies to environments running a persistent Horizon process (see the Environment note above):

```bash
docker compose -f <compose-file> exec <app-service> php artisan horizon:pause-supervisor <supervisor-name>
# e.g. supervisor-critical, supervisor-urgent — see config/horizon.php for the real names.
# If the incident isn't scoped to one supervisor, php artisan horizon:pause stops
# every supervisor — a real, sometimes-correct option, but a strictly bigger blast
# radius than "the specific queue(s) implicated" above; use it deliberately, not by default.
```

`horizon:pause-supervisor` stops new job processing on that supervisor while leaving already-claimed and already-queued jobs intact (Horizon's own documented behavior) — this is deliberately NOT `horizon:terminate` (which would let in-flight jobs finish first) nor a hard container kill (which could leave a job claimed-but-unprocessed with no clean retry path).

## Step 3 — Roll the application artifact back (§7 action 3)

The core action, using the real, already-proven `APP_IMAGE` mechanism:

```bash
export APP_IMAGE="ghcr.io/<repo-lowercased>@sha256:<known-good-digest-from-preconditions>"
docker compose -f <compose-file> up -d <app-service> <horizon-service>
```

This is the exact same promotion mechanism a forward deploy uses (the dev-stg compose file's own `APP_IMAGE` pattern, `docs/operations/examples/docker-compose.dev-stg.yml`; `deploy-production.md` uses the identical mechanism for production) — a rollback is a promotion to an OLDER digest, not a structurally different operation. Omit `<horizon-service>` for an environment with no persistent Horizon process (see the Environment note above).

There is no separate scheduler container to restart. Per `docs/operations/dev-staging-environment.md` §9, the staging scheduler runs as a host-cron-invoked `docker compose exec <app-service> php artisan schedule:run` against the already-running `<app-service>` container, not a standing compose service — once `<app-service>` above is recreated against the rolled-back digest, the next cron-triggered `schedule:run` picks it up automatically.

## Step 4 — Confirm schema compatibility (§7 action 4)

Per the precondition check above: if the rolled-back artifact's code no longer expects a column/table the current (un-rolled-back) schema has, that's fine — expand-phase migrations are additive, so older code ignoring a newer column is safe. If the rolled-back artifact's code expects something the current schema does NOT have (this would only happen if a contract-phase migration already ran), STOP — this procedure does not cover reversing a contract migration; escalate for a decision on the schema itself, separate from the artifact rollback.

## Step 5 — Resume consumers, reprocess after idempotency review (§7 action 5)

```bash
docker compose -f <compose-file> exec <app-service> php artisan horizon:continue-supervisor <supervisor-name>
# (or `horizon:continue` if Step 2 used the unscoped pause)
```

Before resuming: confirm any job that was mid-processing when Step 2 paused the queue is safe to retry — per `docs/architecture/queue-and-outbox.md` §7's at-least-once delivery semantics, every real consumer in this codebase is already required to be idempotent on `event_id`/a domain idempotency key, so a resumed retry should be safe by construction; this step is a human sanity check on that assumption for the SPECIFIC incident, not a blanket skip.

## Step 6 — Reconcile payment/provider events received during the incident window (§7 action 6)

If the incident touched the payment path: cross-check `provider_events`/`payment_sessions` rows with timestamps inside the incident window against the payment provider's own dashboard/API for any event this system might have missed or double-processed during the rollback window. This is a manual reconciliation step — no automated tool for this exists in this codebase today.

## Step 7 — Record the incident (§7 action 7)

Record: the trigger that fired, the digest rolled back from and to, the time window, affected references (order/payment/renewal ids), and corrective action taken — in whatever incident record this project uses (see `docs/operations/runbooks.md`'s existing 13 incident-response sections for this codebase's established documentation pattern for an incident write-up, even though none of those 13 sections is this one).

## Verification after rollback

Re-run `deploy-production.md` Step 6's deployment checks (or the staging-equivalent) against the now-rolled-back environment — a rollback is itself a deploy and needs the same post-deploy health confirmation, not an assumption that reverting fixes everything.
