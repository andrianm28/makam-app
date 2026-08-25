# Single-Host Production Decision Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record and propagate a real, explicit architectural decision the user made this session: production runs on the SAME shared `yiemvm` host as dev/staging, with self-managed PostgreSQL and self-hosted object storage — not separate infrastructure, not an external managed-Postgres provider, not an external S3-compatible storage provider. This reverses ADR-0027 items 7-9, supersedes ADR-0021, and updates every real downstream document that currently states or assumes the old position.

**Architecture:** Documentation only — no application code changes. A research pass this session confirmed the storage mechanism this decision needs already exists (`App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage`, real, implements the full `ObjectStorage` contract) and self-managed Postgres already runs today (dev/staging/beta all use it) — nothing to build, only to document honestly and consistently across ~9 real files.

**Tech Stack:** Markdown (ADRs, runbooks, planning docs) — this repo's own house conventions throughout.

**Spec:** No formal spec document. This plan implements a direct user decision, made via two explicit confirmations this session: (1) production uses the shared host instead of external managed-Postgres/object-storage providers; (2) this explicitly includes document storage (real KTP/KK/death-certificate uploads), accepting that this reopens the exact UU-PDP/object-storage risk `docs/adr/0035-beta-launch-accepted-risks.md` item 5 currently avoids by keeping beta document-free. Both decisions are recorded as this plan's own authority — see Global Constraints.

## Global Constraints

- **The decision, stated once here as the canonical language every task must reference identically** (do not paraphrase differently task to task): "Production will run on the existing shared `yiemvm` host (the same host as dev/staging), using self-managed PostgreSQL 18 and self-hosted object storage — not a separate production environment, not an external managed-PostgreSQL provider, not an external S3-compatible storage provider. This includes real restricted-document storage (KTP/KK/death certificates via `App\Platform\DocumentVault`), which reopens the UU-PDP/object-storage exposure `ADR-0035` item 5 currently avoids by keeping the live beta document-free — accepted explicitly, not silently, as part of this decision."
- **ADRs are historical records — never rewrite a past Decision/Consequences section's original text.** Every ADR touched by this plan (0021, 0035) gets a clearly-dated new section appended (matching this repo's own established convention — see ADR-0027's own "Host specification correction (23 Aug 2026)" section, added earlier this session, as the house style to copy exactly) or a short pointer note, never a silent in-place rewrite of what was originally decided.
- **`AGENTS.md` §Infrastructure-agent execution**: human review is mandatory for security/privacy/production-affecting changes. This plan is the documentation of a decision the user already explicitly made (twice, via direct confirmation this session) — it records the decision, it does not itself constitute the human review for any FUTURE action (actually provisioning storage, migrating real data, etc.) that builds on it.
- **Do not silently resolve OQ-7 (malware scanner for production).** ADR-0027 item 7 also forbids "always-on ClamAV" on this host; production document uploads need a real scanner, not the `MockScanner` dev/staging uses. This was NOT part of the user's two explicit confirmations this session (object storage + Postgres self-hosting; document-storage risk acceptance) — every task touching this topic must leave OQ-7 explicitly named as still-open, not assume the same "self-host everything" answer extends to it.
- **`docs/adr/0035-beta-launch-accepted-risks.md` item 5 describes TODAY's beta scope, not full production — do not edit its own text.** Item 5's "beta does not accept private documents... removes the highest-severity slice of UU PDP exposure for this launch" remains an accurate description of the CURRENT beta's own deliberate scope decision. This plan's decision is about the FUTURE production graduation, which does NOT inherit item 5's protection — record this as a forward-looking cross-reference (in the ADR-0027 amendment and via a short pointer in ADR-0035), not as an edit to item 5's own historical text.
- Follow this repo's evidence-citation and "prepared, not executed" conventions throughout — this plan produces decision records and updated runbooks, not a claim that anything has actually been provisioned or migrated.
- `bash ci/verify-docs.sh` must pass (13/13 gates) after every task.

## Context already established (do not re-derive)

- **This is not without precedent.** `ADR-0035` item 3 ("Single host, no high availability") already records that the LIVE BETA runs on this same shared host by the user's own prior explicit decision — "Host loss is a total outage... not automated failover... Mitigation: none beyond the backup/restore path... accepted as-is for the beta's scale and audience." This plan's decision extends that same established risk appetite from beta to full production.
- **`ADR-0021`** (full text, 11 lines): "Production uses managed PostgreSQL 18 with encryption, backups, PITR, monitoring, restore-to-new-instance capability, and required extensions... Higher infrastructure cost than self-hosted database, but materially lower data-loss and operational risk." This decision is now superseded.
- **`ADR-0035` item 2** ("No point-in-time recovery") already documents the BETA database's own self-managed-Postgres-no-PITR state as an accepted, *temporary* risk, with its own stated reversal clause: "**Reversal:** migrate to managed PostgreSQL with PITR before or shortly after launch if beta traction justifies it." This plan's decision directly supersedes that reversal trigger — production will NOT migrate to managed PostgreSQL; the self-managed state becomes permanent policy, not a temporary beta compromise.
- **`ADR-0035` item 5** ("Booking Step 7 document upload absent"): beta deliberately does not accept real KTP/KK/death-certificate uploads, specifically to avoid the object-storage dependency and the UU-PDP exposure. Full production graduation (this plan's scope) does NOT get the same protection — production will accept real document uploads to self-hosted storage. This is the single most consequential fact this plan records; every task touching ADR-0035 or the production topology must state it plainly.
- **The storage mechanism already exists, real, no new code needed**: `App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage` implements the full `ObjectStorage` contract, writing to `storage_path('app/private/documents')`, never under `public/`, never HTTP-reachable directly. Its own doc block currently scopes it to "the combined dev/staging host" — this plan's decision extends that scope to production too; no new adapter needs building.
- **`config/filesystems.php`** already has real `'local'` and `'s3'` disk drivers configured — the mechanism this decision reuses is already wired, only its documented production scope changes.
- **The real files this decision touches, with their exact current problematic text** (verified this session, cited per-task below): `docs/adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md` items 7-9 and Consequences; `docs/adr/0021-use-managed-postgresql-pitr.md` (full 11-line file); `docs/adr/0035-beta-launch-accepted-risks.md` items 2 and 5 (pointer notes only, per the Global Constraint above); `docs/operations/deployment.md` §3; `docs/architecture/overview.md` (lines ~31/272/274/306); `docs/operations/database-backup-and-recovery.md` (whole document's premise, especially its own line 88: "The Ubuntu 22.04 2/4 combined host does not provide production PITR guarantees" and line 95); `docs/operations/backup-and-restore-runbook.md` (built around choosing an external provider); `docs/testing/release-gates.md` lines 99, 114, and 118 (118 is currently CHECKED `[x]` and its truth INVERTS under this decision — verified directly this session); `docs/planning/sprint-plan.md` OQ-4 row (line ~191) and OQ-6 row (line ~1009), plus reference lines at ~164, 517, 574, 824, 941, 1088; `docs/operations/runbooks/deploy-production.md` (merged this session as PR #153 — needs the most rework, since it was written entirely around "no target exists yet").
- **`docs/operations/dev-staging-environment.md`'s "private S3-compatible buckets"/"external private object storage" language (lines ~55/187/197) describes DEV/STAGING's own existing, separate, sandboxed-external-storage convention — confirmed this session as likely NOT needing a change, since it's describing a different environment's pattern than the one this decision touches.** Task 3 below should confirm this directly rather than assume it, but the working assumption is: leave this file alone unless direct reading finds otherwise.

---

### Task 1: ADR-0027 amendment — the core decision record

**Files:**
- Modify: `docs/adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md`

**Interfaces:**
- Produces: the canonical, citable decision record every other task points back to. Every other task's own file should reference this ADR (and its new section) by name, not restate the decision's reasoning independently.

- [ ] **Step 1: Add a new, dated section after the existing "Host specification correction (23 Aug 2026)" section, before "## Context"**

Insert, verbatim (fill in nothing — this is the real text):

```markdown
## Production graduation — single-host decision (23 Aug 2026)

**This section reverses items 7-9 below and the "no local production-like HA/PITR" Negative consequence** — recorded here, not silently, per the user's own explicit decision this session (confirmed via two direct confirmations: first that production uses this host instead of external managed-Postgres/object-storage providers, second that this explicitly includes real document storage).

**Decision**: Production will run on this same shared `yiemvm` host — the same host as dev/staging — using self-managed PostgreSQL 18 and self-hosted object storage (`App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage`, already real, already implements the full `ObjectStorage` contract for the combined dev/staging host; this decision extends its scope to production too — no new code is needed). Not a separate production environment. Not an external managed-PostgreSQL provider (this decision supersedes `ADR-0021` — see that ADR's own new pointer note). Not an external S3-compatible storage provider.

**This is not without precedent**: `ADR-0035` item 3 already records that the live beta runs on this same shared host by the user's own prior explicit decision, accepted "as-is for the beta's scale and audience." This decision extends that same accepted risk appetite from beta to full production.

**The single most consequential fact this decision changes, stated plainly**: `ADR-0035` item 5 records that the current beta deliberately does NOT accept real KTP/KK/death-certificate document uploads, specifically to avoid the object-storage dependency and "the highest-severity slice of UU PDP exposure for this launch." Full production graduation does NOT get the same protection — production will accept real document uploads to self-hosted storage on this shared host. This is an explicit, accepted risk, not an oversight: real Indonesian citizens' national-ID-adjacent documents will live on the same host where dev/staging experiments run, under the same "development activity can affect [now: production too]" condition item 63 below already names for staging.

**New negative consequences, added to the existing Consequences section below**:
- No point-in-time recovery for production data — a bad migration, a bug, or an accidental delete can only be recovered to the last backup snapshot, not a specific moment. Backup strategy is `docs/operations/database-backup-and-recovery.md`'s own responsibility to define for this new reality (see that document's own update, this same plan).
- No high availability — a host failure is real production downtime with manual recovery (RTO measured in hours), not automated failover. Same shape as `ADR-0035` item 3's already-accepted beta risk, now extended to production.
- Development and staging activity can now affect real production data and real customer documents, not just staging — materially larger blast radius than the existing "development activity can affect staging" consequence.
- Capacity risk: this host (8 vCPU/31 GB) now carries dev + staging + real production traffic together. The existing "Capacity exit criteria" section (below) becomes immediately load-bearing, not aspirational — any of its named triggers (memory >80%, swap/OOM, degraded queue latency, sustained database pressure) is now a real production-risk signal, not just a staging-scale one.
- Real restricted-document storage (KTP/KK/death certificates) reopens the UU-PDP exposure `ADR-0035` item 5 deliberately avoided for the current beta — accepted explicitly per this section's own framing above, not silently.

**Explicitly NOT resolved by this decision — still open**: **OQ-7** (a real, fail-closed malware scanner for production document uploads — item 7 below's "always-on ClamAV... prohibited" line is NOT reversed by this decision; production still needs either a real scanner solution compatible with that prohibition, or a separate explicit decision to reverse it too, which the user has not made). Do not assume production document uploads are safe to accept for real until OQ-7 is resolved separately.
```

- [ ] **Step 2: Reverse items 7, 8, 9 in the numbered Decision list**

Find the current item 7 ("Production data/credentials, local permanent MinIO, and always-on ClamAV are prohibited."), item 8 ("The environment is not accepted as production or formal production-capacity evidence."), item 9 ("Production remains Ubuntu 24.04 LTS or managed equivalent...").

Replace item 7 with:
```markdown
7. ~~Production data/credentials, local permanent MinIO, and always-on ClamAV are prohibited.~~ **Superseded 23 Aug 2026** (see the "Production graduation — single-host decision" section above): production data/credentials and local permanent object storage (via `LocalFilesystemObjectStorage`, not MinIO specifically — this codebase never adopted MinIO) now DO run on this host, by explicit decision. The "always-on ClamAV... prohibited" clause is NOT reversed — OQ-7 (a real production malware scanner) remains a separate, still-open question.
```

Replace item 8 with:
```markdown
8. ~~The environment is not accepted as production or formal production-capacity evidence.~~ **Superseded 23 Aug 2026** (see the "Production graduation — single-host decision" section above): this host IS now production, by explicit decision — not merely staging with production-like traffic. It is still not accepted as formal production-**capacity** evidence in the performance-benchmarking sense (`docs/operations/performance-and-capacity.md`'s own framing survives this change) — a host being real production infrastructure is a different claim than it having been load-tested at production scale.
```

Replace item 9 with (minimal edit — keep it accurate, don't over-rewrite):
```markdown
9. Production remains Ubuntu 24.04 LTS or managed equivalent (the dev/staging host already runs this OS, per the correction note above — under the 23 Aug 2026 single-host decision, this is no longer a coincidence but the actual production OS).
```

- [ ] **Step 3: Add the new negative consequences to the existing Consequences section**

Find the `### Negative` list under `## Consequences`. Append the 5 new bullets from Step 1's "New negative consequences" block above (do not duplicate the prose — just the bullet list items, formatted to match the existing bullet style).

- [ ] **Step 4: Run doc gates**

```bash
bash ci/verify-docs.sh
```

Expected: `RESULT: ALL DOC GATES PASS`.

- [ ] **Step 5: Commit**

```bash
git add docs/adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md
git commit -m "docs(adr): record the single-host production decision, reversing ADR-0027 items 7-9"
```

---

### Task 2: Sibling ADR pointer notes (ADR-0021 superseded, ADR-0035 reversal-clause update)

**Files:**
- Modify: `docs/adr/0021-use-managed-postgresql-pitr.md`
- Modify: `docs/adr/0035-beta-launch-accepted-risks.md`

**Interfaces:**
- Consumes: Task 1's ADR-0027 amendment (references it by name — this task can run without Task 1 having landed first, since it only needs to cite "ADR-0027's single-host decision (23 Aug 2026)" by name, not its exact file content).

- [ ] **Step 1: Add a short superseded-pointer to ADR-0021**

Append, after the existing `## Consequences` section (do not touch the original Decision/Consequences text above it):

```markdown

## Status update (23 Aug 2026)

**Superseded by `docs/adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md`'s "Production graduation — single-host decision" section.** Production uses self-managed PostgreSQL on the shared dev/staging host instead of a managed provider with PITR — this ADR's original Decision (managed PostgreSQL 18 with PITR) is not the plan going forward. Left in place as the historical record of what was originally decided and why (materially lower data-loss/operational risk was the real tradeoff being weighed) — that reasoning remains true and is the real cost being accepted by the newer decision, not erased by it.
```

- [ ] **Step 2: Add a short pointer to ADR-0035 item 2's own text (below its existing "Reversal" line, not replacing it)**

Find item 2 ("No point-in-time recovery (deviates from ADR-0021)"), specifically its `**Reversal:** migrate to managed PostgreSQL with PITR before or shortly after launch if beta traction justifies it; no schema changes required, this is an infrastructure-only reversal.` line. Immediately after that line, add:

```markdown

**Update (23 Aug 2026):** this reversal trigger is superseded — `ADR-0027`'s "Production graduation — single-host decision" section makes self-managed PostgreSQL (no PITR) the permanent production policy, not a temporary beta compromise. This item's own mitigation (frequent encrypted dumps, tested restore) remains the real, ongoing backup strategy for production too, not just beta.
```

- [ ] **Step 3: Add a short forward-reference near ADR-0035 item 5 (do not edit item 5's own text — Global Constraint)**

Find item 5 ("Booking Step 7 document upload absent"). Immediately after its existing paragraph (which ends "...Step 7 shows an honest 'dokumen dikumpulkan oleh tim kami setelah pemesanan' state; collection happens offline."), add:

```markdown

**Forward reference (23 Aug 2026):** this scope decision is specific to the current beta launch, not a permanent architecture. `ADR-0027`'s "Production graduation — single-host decision" section records that full production graduation does NOT inherit this protection — production will accept real document uploads to self-hosted storage on the same shared host. That is a real, explicitly-accepted risk recorded there, not a silent reopening of what this item avoided.
```

- [ ] **Step 4: Run doc gates**

```bash
bash ci/verify-docs.sh
```

- [ ] **Step 5: Commit**

```bash
git add docs/adr/0021-use-managed-postgresql-pitr.md docs/adr/0035-beta-launch-accepted-risks.md
git commit -m "docs(adr): supersede ADR-0021, update ADR-0035's PITR-reversal and document-upload cross-references"
```

---

### Task 3: Production topology description updates

**Files:**
- Modify: `docs/operations/deployment.md`
- Modify: `docs/architecture/overview.md`
- Investigate (may not need a change, per the plan's own Context note): `docs/operations/dev-staging-environment.md`

**Interfaces:**
- Consumes: Task 1's decision language (cite `ADR-0027`'s single-host section by name).

- [ ] **Step 1: Rewrite `deployment.md` §3's topology block**

Read the real, current §3 in full first (already read this session: it currently shows `managed PostgreSQL 18` / `managed Redis 8.2` / `private S3-compatible object storage` in the topology diagram, plus "Production uses Ubuntu 24.04 LTS or managed equivalent"). Replace the managed-service lines with the real, self-managed equivalents, and add one sentence citing `ADR-0027`'s single-host section as the reason. Keep the rest of §3's structure (CDN/WAF, reverse proxy, PHP-FPM) — only the storage/database/OS lines change to reflect self-managed-on-shared-host instead of managed/separate.

- [ ] **Step 2: Update `docs/architecture/overview.md`'s references (lines ~31/272/274/306)**

Read each cited location in full first — confirm the exact current wording before editing (line numbers may have shifted since this session's research pass). Each "managed PostgreSQL 18 with PITR" / "private S3-compatible... storage" reference should be updated to reflect the real, self-managed/self-hosted production reality, citing `ADR-0027`'s single-host section.

- [ ] **Step 3: Investigate `dev-staging-environment.md` — confirm whether it needs a change**

Read the real, current text around lines ~55/187/197 (the "private S3-compatible buckets"/"external private object storage" references). Confirm these describe DEV/STAGING's own existing external-sandbox storage convention (a genuinely separate concern from production's new self-hosted pattern), per this plan's own Context note. If confirmed separate, make NO change to this file and note that confirmation in the task report. If direct reading finds these lines actually describe something this decision does affect, make the minimal correct edit and explain why the Context note's assumption was wrong.

- [ ] **Step 4: Run doc gates**

```bash
bash ci/verify-docs.sh
```

- [ ] **Step 5: Commit**

```bash
git add docs/operations/deployment.md docs/architecture/overview.md
# add docs/operations/dev-staging-environment.md too only if Step 3 found a real change needed
git commit -m "docs(architecture): update production topology description for the single-host decision"
```

---

### Task 4: Backup/PITR strategy documents

**Files:**
- Modify: `docs/operations/database-backup-and-recovery.md`
- Modify: `docs/operations/backup-and-restore-runbook.md`

**Interfaces:**
- Consumes: Task 1's decision (cite by name); `ADR-0035` item 2's existing mitigation (frequent encrypted `pg_dump` snapshots + tested restore) as the established, real backup-strategy precedent this task extends from beta to production — do not invent a different strategy, reuse the one already proven and documented for beta.

- [ ] **Step 1: Update `database-backup-and-recovery.md`'s production framing**

Read the full document first (9 sections: production database baseline, provisional recovery objectives, backup policy, restore testing, restore validation, failover/split-brain safety, migration connection, database security, non-production combined-host policy). Its own line 88 ("The Ubuntu 22.04 2/4 combined host does not provide production PITR guarantees") and line 95 ("Production recovery objectives... require managed PostgreSQL/PITR") both describe the OLD position and need updating.

Do not delete the document's real content describing what managed-PostgreSQL/PITR-based recovery WOULD look like (§1-2 may still be useful as an aspirational target if a future decision reverses this one) — instead, add a clearly-dated note near the top (mirroring the "Host specification correction" pattern) stating: production now uses the same self-managed Postgres as dev/staging, per `ADR-0027`'s single-host decision; §9 ("Non-production combined-host policy" — read its real current title/content first) needs its own scope correction since the host is no longer non-production; the real, current backup strategy for production is the same one `ADR-0035` item 2 already established for beta (frequent encrypted `pg_dump` snapshots, tested restore into a scratch database, backup not considered valid until restored) — cite that item directly rather than re-deriving a new strategy.

- [ ] **Step 2: Update `backup-and-restore-runbook.md`'s external-provider framing**

Read the full document first (`## BLOCKED ON OQ-4` section, Preconditions, Restore procedure, Evidence template — check the real current line numbers, they may have shifted). Every place this document is built around "choose an external S3-compatible provider first" needs updating to reflect self-hosted storage instead — the restore procedure's real content (whatever it actually does for restoring documents) may not need to change much if it was already written generically, but the framing/blocking-status language does. Update the `## Status` header to reflect that OQ-4 (for storage) is resolved (self-hosted) but note OQ-7 (malware scanner) remains genuinely unresolved and is NOT the same question.

- [ ] **Step 3: Run doc gates**

```bash
bash ci/verify-docs.sh
```

- [ ] **Step 4: Commit**

```bash
git add docs/operations/database-backup-and-recovery.md docs/operations/backup-and-restore-runbook.md
git commit -m "docs(ops): update backup/PITR strategy docs for self-managed production on the shared host"
```

---

### Task 5: Status-tracking documents (release-gates.md, sprint-plan.md)

**Files:**
- Modify: `docs/testing/release-gates.md`
- Modify: `docs/planning/sprint-plan.md`

**Interfaces:**
- Consumes: Task 1's decision (cite by name).

- [ ] **Step 1: Update `release-gates.md`'s 3 affected boxes**

Read all 3 boxes' real, current exact text first (confirmed this session: line ~99 "Managed PostgreSQL backup/PITR configured...", line ~114 "Remote staging backup and restore procedure passes...", line ~118 "The host is not recorded as production capacity/PITR/HA evidence..." — **this one is currently `[x]` CHECKED and its truth INVERTS under this decision, verify this carefully before editing**).

- Line ~99's box: the literal claim ("Managed PostgreSQL backup/PITR configured") is now permanently not the plan, not just temporarily blocked — reword to state this plainly (production uses self-managed Postgres per `ADR-0027`'s single-host decision; no PITR exists or will exist under this decision) rather than leaving "Blocked on OQ-4" language that implies it's still pending a provider choice.
- Line ~114's box: similarly, "remote staging backup" language may need adjusting if this box's scope was ever meant to extend to production — read carefully whether this box is staging-specific (in which case it may be unaffected) or was implicitly meant to cover production too.
- **Line ~118's box (currently checked)**: its literal claim was "the host is not recorded as production capacity/PITR/HA evidence" — this is now FALSE under the new decision (the host explicitly IS production). **Uncheck this box** (`[x]` → `[ ]`) and rewrite its evidence text to state plainly that this claim no longer holds — the host now IS production, by explicit decision, and carries production data with no PITR/HA, cited to `ADR-0027`'s single-host section. This is not a "fix the citation" edit like earlier task-review-loop findings this session — the box's entire premise inverted, so the whole evidence paragraph needs rewriting, not patching.

- [ ] **Step 2: Update `sprint-plan.md`'s OQ-4 and OQ-6 rows**

Read both rows' exact current text first (confirmed this session: OQ-4 at line ~191 says "Local MinIO on the 2/4 host is explicitly forbidden" — directly contradicted by this decision; OQ-6 at line ~1009 says "Undecided — blocks production planning"). Update both rows' "Answer/Decision" column to state the real resolution: OQ-4 → self-hosted via `LocalFilesystemObjectStorage`, per `ADR-0027`'s single-host decision (23 Aug 2026); OQ-6 → self-managed Postgres on the shared host, same decision, ADR-0021 superseded. Do not touch OQ-7's row (malware scanner) — it remains genuinely undecided, per this plan's own Global Constraint.

Also check the other reference lines this session's research found (~164, 517, 574, 824, 941, 1088) — read each in context and update only where the OLD "blocked/undecided" framing is what's stated; some of these may be historical narrative that shouldn't be rewritten (e.g., a past sprint's own retrospective text describing what was true AT THE TIME) — use judgment, and note in the task report which lines were left alone and why.

- [ ] **Step 3: Run doc gates**

```bash
bash ci/verify-docs.sh
```

- [ ] **Step 4: Commit**

```bash
git add docs/testing/release-gates.md docs/planning/sprint-plan.md
git commit -m "docs(testing,planning): resolve OQ-4/OQ-6 tracking and correct release-gates.md's inverted host-production box"
```

---

### Task 6: `deploy-production.md` rework

**Files:**
- Modify: `docs/operations/runbooks/deploy-production.md`

**Interfaces:**
- Consumes: Task 1's decision (cite by name); the real `docker-compose.dev-stg.yml` `APP_IMAGE` pattern already used throughout this file; `ADR-0035` item 3's real beta-stack compose naming precedent (`beta-web`/`beta-worker`/`beta-scheduler` containers, own database/role/secret, memory limits raised to accommodate a third application) as the established, real pattern to extend for a production tier — do not invent a different naming/provisioning convention.

This is the largest single-file rework in this plan — the runbook was written entirely around "no target exists yet," and that's no longer true.

- [ ] **Step 1: Rewrite the `## Status` section**

The three named blockers (OQ-4, OQ-6, "no production host exists") all resolve. Replace with real status: production target is the shared `yiemvm` host, per `ADR-0027`'s single-host decision (23 Aug 2026). This runbook is now genuinely closer to executable than before, but still "prepared, not executed" — no command in this document has actually been run; a real production compose file/services still need to be created (naming this as the concrete next step, not a vague blocker) before Step 3 onward can run for real. Name OQ-7 (malware scanner) explicitly as a still-separate, still-open item that does NOT block deploying the application itself but DOES block safely accepting real document uploads in production.

- [ ] **Step 2: Rewrite Preconditions 1-3**

These assumed external-provider/host decisions that are now moot (self-hosted resolves them). Replace with the real remaining preconditions: (1) a real production compose service definition exists, following `ADR-0035` item 3's real beta-stack pattern (own database/role/secret, distinct from dev/staging/beta, memory limits sized for a 4th concurrent application on this host — this runbook does not create that compose file, since that's real infra-provisioning work outside a "prepared, not executed" document's own scope, but it can now name the exact real pattern to follow); (2) `deployment.md` §4's config-isolation classes are provisioned with production-distinct values (this was already Precondition 4, keep it, renumber); (3) CI is green (already Precondition 5, keep it, renumber).

- [ ] **Step 3: Update Step 2's `[BLOCKED: ...]` marker**

The marker resolves. This step can now describe the real config file this needs (mirroring `.env.dev`/`.env.stg`'s real pattern from `docker-compose.dev-stg.yml`, extended to a `.env.production` or similar) — still cannot be drafted with real VALUES here (those are real secrets), but the marker text should no longer say "BLOCKED" on an undecided provider — it should say what real config file needs to exist and point at `ADR-0035` item 3's own beta-stack precedent for the pattern.

- [ ] **Step 4: Update Step 3's compose-file framing**

`<production-compose-file>` still doesn't exist as a literal file, but the reasoning for why has changed — it's not blocked on an infra-procurement decision anymore, it's the concrete next real engineering step (creating a `prod-web`/`prod-worker`/`prod-scheduler`-shaped service definition on the existing compose file, following the exact same pattern `ADR-0035` item 3 already used for `beta-web`/`beta-worker`/`beta-scheduler`). Update the explanatory text accordingly — still don't invent the file's real content here (that's a real, separate implementation task, not this documentation plan's job), but be accurate about why it doesn't exist yet.

- [ ] **Step 5: Update the `## Finding surfaced, not resolved` section**

The placeholders it names (`<production-domain>`, compose file path, app-service name) partially resolve — there's no longer a `<production-domain>` question in the same open-ended way (this is still the shared host's own domain question, likely `makam.co.id` itself once beta graduates, but that's a real, separate decision this plan doesn't make — leave `<production-domain>` as a genuine remaining placeholder, but remove the framing that implies NO target host exists at all).

- [ ] **Step 6: Run doc gates**

```bash
bash ci/verify-docs.sh
```

- [ ] **Step 7: Commit**

```bash
git add docs/operations/runbooks/deploy-production.md
git commit -m "docs(ops): rework deploy-production.md now that the target host is known (single-host decision)"
```

---

## Verification

| Task | Done when |
|---|---|
| 1 | ADR-0027 has the new dated section, items 7-9 are struck through and superseded (not deleted), Consequences has the 5 new bullets, `ci/verify-docs.sh` passes |
| 2 | ADR-0021 has a superseded pointer; ADR-0035 items 2 and 5 have their own pointer notes appended (original text untouched) |
| 3 | `deployment.md` §3 and `architecture/overview.md` describe self-managed/self-hosted production; `dev-staging-environment.md` either confirmed unaffected or correctly updated |
| 4 | Both backup/PITR docs describe the real, self-managed production backup strategy (reusing ADR-0035 item 2's established beta mitigation), with §9's non-production framing corrected |
| 5 | `release-gates.md`'s 3 boxes are accurate (including the inverted line-118 box genuinely unchecked with rewritten evidence); `sprint-plan.md`'s OQ-4/OQ-6 rows show real resolutions, OQ-7 untouched |
| 6 | `deploy-production.md` reflects the real known target host throughout, still honestly "prepared, not executed" where genuinely true, OQ-7 named as a separate remaining gap |

Final whole-branch review checks: does every task's cross-reference to "ADR-0027's single-host decision" actually resolve to real, consistent language (not 6 independently-worded paraphrases of the same decision)? Does anything task-scoped review might miss — e.g., does `release-gates.md`'s corrected line-118 box now contradict something ANOTHER box elsewhere in the same file still says? Is OQ-7 genuinely left alone everywhere, not accidentally resolved by an overeager task?

## Execution

Execute via `superpowers:subagent-driven-development` — fresh implementer subagent per task, task-scoped review, one final whole-branch review before PR. Standing execution mode for this session; do not ask the user to choose between subagent-driven and inline execution.
