# ADR-0035: Public Beta Launch — Accepted Risks and Deviations

## Status

Proposed. Requires human review before merge — this ADR documents deviations from
[ADR-0021](0021-use-managed-postgresql-pitr.md), [ADR-0018](0018-use-horizon-and-priority-queues.md),
[ADR-0022](0022-standardize-production-observability.md), [ADR-0026](0026-performance-gates-before-scaling-complexity.md)
and [ADR-0027](0027-combine-dev-staging-on-ubuntu22-2v4g.md) for a production-affecting launch, which
`AGENTS.md` §Infrastructure-agent execution names explicitly as requiring human sign-off before merge, not
just green CI.

## Context

[`docs/superpowers/plans/2026-08-18-public-beta-release.md`](../superpowers/plans/2026-08-18-public-beta-release.md)
records the user's 18 Aug 2026 decision to take Makam.co.id to a public soft launch at `makam.co.id` on the
existing non-production host, rather than standing up new production infrastructure first. That plan's Phase 1
(lanes A, B, C1, D3, D7, E, F2, F3 — async spine, demo-data purge tooling, settings-driven identity, public
rate limits, report-only CSP, the spine watchdog, the footer contrast fix, and Indonesian locale strings), the
payment-step sandbox warning and site-wide beta banner (Lane C4), and admin-manageable NIB/legal-review-status
settings have shipped as PRs #96–#107, merged into `docs/design-system-and-planning`.

On 19 Aug 2026 the user made three further explicit decisions, opting out of three of the plan's own
recommended (not required) hardening steps, recorded as items 9–10 below and as an update to item 3's status:
skip real legal review before launch, deploy the beta stack on this same host in its own containers (Lane D1 —
now built, see item 3), and skip MFA enrolment on beta admin accounts entirely.

Per the plan's own "Recorded dissent" section, two of the choices behind this launch carry risk the plan's
author flagged as worth reconsidering rather than silently proceeding past: sandbox payments in front of real
customers, and (separately, already mitigated by Lane B's design) publishing fabricated prices against real
government cemeteries. The user's decision stands; this ADR is where that dissent and every other accepted
deviation gets recorded formally, per this repository's established pattern (ADR-0031 §Context) of raising a
conflict once and then proceeding on the record when the user reaffirms it.

Per `AGENTS.md` §Infrastructure-agent execution, this document is prepared by an AI agent; it explicitly
requires human review and explicit sign-off before merge, and before any of the launch steps it enables are
executed against the live host.

## Decision

The public beta at `makam.co.id` launches on the existing non-production host, accepting the following
deviations from otherwise-binding ADRs and standards, each with its stated mitigation and reversal path.

### 1. Sandbox payments visible to real customers (deviates from `AGENTS.md` §Domain and financial
invariants' documented alternative: "Closed online-payment gate uses manual fallback in Step 8")

A real customer can complete a booking for a real burial and "pay" through the SumoPod **sandbox**, which
moves no real money; the order is then marked paid. `G-PAY-01` (online payment) stays **open** per the user's
explicit decision, rather than closed with the documented manual-fallback alternative.

**Mitigations:**
- Unmissable payment-step labelling — **built** (PR #106): an urgent-intent warning on the "Bayar Sekarang"
  card itself, before any redirect to the sandbox, stating plainly no real transaction occurs. A second,
  calmer-intent site-wide banner (PR #107, Lane C4) reinforces the same point on every page.
- Daily reconciliation of orders marked paid against actual settlement — **process still not built; owner
  named 20 Aug 2026.** Ira is the named daily payment-reconciliation owner, per the user's explicit decision
  recorded during this ADR's closeout pass. The reconciliation *process itself* (a scripted or tooled daily
  check against `docs/domain/traceability-matrix.md`-class evidence) remains unbuilt — naming an owner
  unblocks launch per this item's own prior condition, but does not substitute for the process existing.
- Reversing this is a one-row gate change (`G-PAY-01` → closed), no code.

**Status as of 20 Aug 2026:** the reconciliation owner gap that blocked launch is closed (Ira, named above).
The reconciliation process itself is still not built and should be tracked as its own follow-up, not
reopened as a launch blocker now that ownership exists to run a manual check daily in its absence.

### 2. No point-in-time recovery (deviates from [ADR-0021](0021-use-managed-postgresql-pitr.md))

The beta database is a self-managed PostgreSQL container on the existing host, not managed PostgreSQL with
PITR. Backups (Lane D2, not yet built) would be periodic `pg_dump` snapshots — up to the backup interval's
worth of real orders lost on volume failure.

**Mitigation:** frequent (4–6 hourly, per the plan) encrypted dumps with a documented, *tested* restore into a
scratch database — a backup is not considered valid until restored, per
`docs/operations/database-backup-and-recovery.md` §4.

**Reversal:** migrate to managed PostgreSQL with PITR before or shortly after launch if beta traction
justifies it; no schema changes required, this is an infrastructure-only reversal.

### 3. Single host, no high availability (deviates from the production topology implied by [ADR-0027](0027-combine-dev-staging-on-ubuntu22-2v4g.md)'s "not accepted as production" framing)

**Status: built, 19 Aug 2026.** Per the user's explicit decision, the beta stack runs on the SAME host as
dev/stg — `beta-web`/`beta-worker`/`beta-scheduler` containers, own `makam_beta` database/role/secret (never
reusing `makam_dev`'s credentials), postgres/redis memory limits raised (2g/512m) to accommodate a third
application. Pinned to the image digest CI built for `docs/design-system-and-planning` AFTER PR #107 merged
(sha-fca46caffb26, CI run 32223062075) — NOT the same digest `dev-web` runs, which predates PRs #96–#107 and
was deliberately not reused (see `compose.yml`'s own comment on `beta-web` for why). Migrations applied,
example/demo data purged (`example-data:purge --force`), `/health/ready` and `/up` both verified reachable on
`127.0.0.1:8083`.

Host loss is a total outage with an RTO measured in hours (manual redeploy from the last GHCR image and
restored backup), not automated failover.

**Mitigation:** none beyond the backup/restore path in item 2 — this is accepted as-is for the beta's scale
and audience (soft-launched, not promoted).

### 4. No capacity/load evidence (deviates from [ADR-0026](0026-performance-gates-before-scaling-complexity.md) and `AGENTS.md`'s own requirement for capacity evidence pre-production)

No load test has been run against the beta stack's actual container resource limits (Lane D1's raised
memory/CPU allocations).

**Mitigation:** the spine watchdog (Lane E3, shipped in PR #98) and `/health/ready` (Lane E1, shipped in
PR #98) give early signal if the stack degrades under real traffic; soft launch (not promoted) keeps initial
load low by design.

### 5. Booking Step 7 document upload absent (per MVP §2 exception, Lane D1 scope decision)

Beta does not accept private documents (KTP/KK/death certificates) at booking time. This was a deliberate
scope decision (plan §"Scope decisions that shorten the path", D1), not an oversight — it removes the
object-storage dependency for uploads and the highest-severity slice of UU PDP exposure for this launch.
Step 7 shows an honest "dokumen dikumpulkan oleh tim kami setelah pemesanan" state; collection happens
offline.

### 6. UU PDP compliance is minimum-viable, not audited

No DPIA, no formal consent register, no tested breach drill exists for this launch. Item 5 above (collecting
no KTP/KK/death certificates through the platform) removes more real risk than any policy document would, but
controller obligations under PP 71/2019 attach on **collection**, not on payment — "no real money moves"
(item 1) does not shrink this legal surface.

**Mitigation:** none beyond item 5's scope reduction. A DPIA and consent register are out of scope for this
launch and should be revisited before any broader promotion beyond soft launch.

### 7. `docs/testing/release-gates.md`'s 60 release gates are not individually verified

The ~2,754-test automated suite passing (confirmed green on `docs/design-system-and-planning` as of this ADR)
is not the same claim as all 60 documented release gates having been walked. Phase 2 F1 (scripted manual UAT
on the beta host) is the mechanism intended to close this gap and has **still not run** — the beta host now
exists (item 3), which removes the blocker, but the scripted walkthrough itself is a separate, not-yet-done
step.

### 8. Postgres and Redis share a host with the public, unauthenticated dev environment ([ADR-0031](0031-make-dev-environment-public.md))

`dev.makam.co.id`'s existing nginx access log already shows automated scanner probes for `.env`, `.git`,
backup files, and SSH keys against this host. The beta stack adds a second public-facing surface to the same
host and the same shared Postgres/Redis instances (isolated by database/credentials, not by host).

**Mitigation:** beta gets its own database, user, and secret file — **built** (19 Aug 2026): `makam_beta`
database, `makam_beta_user` role, `secrets/makam_beta_db_password.txt` (uid 999, mode 0400), never reusing
`makam_dev`'s credentials. Lane D6 (broader credential hygiene — e.g. rotating anything else touched by the
dev admin password's git-history exposure) remains not built.

### 9. No real legal review before launch (deviates from Lane C2's own plan text and from H3, which named this the human decision gating de-DRAFT'ing the legal pages)

Per the user's explicit 19 Aug 2026 decision, beta launches without waiting for a lawyer to review `/privasi`
and `/syarat-ketentuan`. The admin-editable mechanism for closing this gap once a real review happens exists
(PR #105, `App\Support\LegalReviewStatus`) — an operator can enter a review confirmation via the Site Settings
page and the draft disclaimer disappears immediately, no deploy required — but no review has occurred, and
none is planned before launch. Both pages continue to show, honestly, "Dokumen ini adalah draf awal dan akan
diperbarui setelah tinjauan hukum resmi."

**Mitigation:** the honest draft disclaimer itself — customers are told plainly the legal text is not final,
rather than presented with unreviewed text as if it were binding.

**Reversal:** none needed — this is a state the platform can remain in indefinitely; enter a real review note
via Site Settings whenever a review eventually happens.

### 10. No MFA enforcement on beta admin accounts (opts out of Lane D4's recommended, non-required hardening step)

Per the user's explicit 19 Aug 2026 decision, beta admin accounts will not be enrolled in MFA. This required
no code change: `App\Http\Middleware\EnforceMfaChallenge` only ever challenges an actor whose `MfaEnrolment`
is already confirmed (`ActorContext::MFA_STATE_ENROLLED`) — an actor who never enrols is never touched by it.
The plan's Lane D4 recommended enrolling MFA on every beta admin account as a hardening step for money-route
access; that recommendation is explicitly declined here, not overridden by any config flag (none exists —
enrolment is the only lever).

**Mitigation:** none. Beta admin panel access is single-factor (password only) for the duration of this
decision.

**Reversal:** cheap and fully backward-compatible — MFA enrolment is a self-service, per-account action any
admin can take at any time without a deploy or a config change; this ADR item simply records that none have
been asked to.

## What this ADR does not decide

This ADR records the risk acceptance already made for the items above; it does not itself authorize executing
the remaining unbuilt work (Lane C2–C3, D2, D6, F1). Lane C4 and D1 are now built (items 1, 3, 9, 10 above);
D4's throttle/route-hardening code portion also remains unbuilt, separate from its now-explicitly-declined
MFA-enrolment portion (item 10). Per `AGENTS.md` §Infrastructure-agent execution, DNS changes, firewall
changes, and the apex cutover each require their own explicit human review and sign-off at execution time,
not blanket pre-authorization via this document — that separate sign-off is a fact of record, not something
this document is inferring: **the cutover to `makam.co.id` has already happened.**
[`docs/superpowers/plans/2026-08-19-homepage-visual-refresh.md`](../superpowers/plans/2026-08-19-homepage-visual-refresh.md)
records it as done by 19 Aug 2026; re-verified directly on 20 Aug 2026 during this ADR's closeout pass —
`makam.co.id` resolves to this host's public IP and `https://makam.co.id/` returns `200`. This document's
item 3 (single host, no HA) and item 8 (shared host with the public dev environment) describe the live state
those users are now served from, not a future plan.

### 11. Customer authentication (`/masuk`, `/daftar`, `/lupa-password`, `/akun`) shipped after this ADR's
last update, not covered by items 1–10 above

PRs #112–#114 (merged 20 Aug 2026, after this ADR's 19 Aug content was last written) added self-service
login, registration, password reset, and an authenticated `/akun` account area (draft resume, order list,
two gate-closed sub-pages) — a genuinely new, security-relevant surface: credential storage, session/remember
cookies, and per-user data scoping (`order_parties.user_id`, `#[Scope] forUser`) that none of items 1–10
above were written against. Its absence from the original risk list is a stated fact, not a silent gap.

**Assessment, not a new deviation:** this surface was built and reviewed under this repository's normal
Superpowers task-review + whole-branch-review bar (not fast-tracked), and its own traceability rows
(`docs/domain/traceability-matrix.md` §E, `AKUN-01`–`AKUN-08`, added 20 Aug 2026 alongside this update) cite
tests for the no-enumeration login/register/reset behaviour, remember-token rotation on reset, and
per-user data scoping — the same bar items 1–10 hold sandbox payments and the money routes to. No new
accepted-risk item is being opened here; this addendum exists so a reader of this ADR is not left assuming
`/akun` was reviewed under items 1–10 when it postdates them.

## Consequences

### Positive

- Every deviation from an established ADR is now written down in one place, with its specific mitigation and
  reversal path, rather than being an implicit consequence of "use the existing host" discovered later during
  an incident.
- Reversal paths are cheap for every item except 6 (UU PDP posture), which is a genuine, accepted-not-mitigated
  gap for the duration of the beta.

### Negative

- Items 1, 2, 4, 6, 7, 9 and 10 are real, stacked risk for a bereavement-sector product handling real customer
  bookings, even with the mitigations listed. Item 1's payment labelling is now built and its owner named
  (20 Aug 2026); the reconciliation process itself is still not built and remains a real, accepted gap.
- Items 9 and 10 stack specifically on the admin/legal surface: unreviewed legal text stays live indefinitely,
  and the admin panel protecting money-route actions (payment reversals, marketplace order payout marking) has
  no second factor for the duration of the beta. Neither has a mitigation beyond honest labelling (item 9) or
  none at all (item 10) — both are accepted risk, not risk believed to be small.

## Reversal

Each item's reversal path is stated inline above. None of items 1–10 create a data migration or schema
dependency that would block reversing them independently of one another. Items 9 and 10 are the cheapest to
reverse of all ten — a Site Settings field and a self-service MFA enrolment, respectively, neither needing a
deploy.
