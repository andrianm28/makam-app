# Release-Gates Phase 2 Closeout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close (or honestly advance with corrected evidence) 7 of the highest-value, currently-achievable remaining boxes in `docs/testing/release-gates.md` — items a dedicated categorization pass this session confirmed need no human-only decision (financial sign-off, external credential provisioning, legal review) to move forward.

**Architecture:** No new subsystems. Each task either (a) writes a real, missing test and cites it as new evidence, (b) corrects a box's evidence text against facts already true but not yet cited, or (c) executes a real, safe, dev/beta-scoped operational rehearsal (backup/restore, rollback, Horizon) and records its real output as evidence. No task touches beta/production in a way that risks real customer-facing state — see each task's own safety design.

**Tech Stack:** Laravel 13 / PHP 8.5, PostgreSQL 18, Redis 8.2, Horizon, Docker Compose on the shared `yiemvm` host.

**Spec:** No separate spec document — this plan's scope was set by a dedicated categorization pass this session (`docs/testing/release-gates.md`'s 25 remaining open boxes sorted by real actionability) and approved by the user as a direct continuation of today's merged work (PR #159, PR #154).

## Global Constraints

- **Every operational rehearsal task (5, 6, 7) targets `dev` (`makam-nonprod-dev-web-1`) only — never `beta` or any environment serving real customer traffic** — except Task 5's backup source, which is deliberately `beta` (see Task 5's own safety design: read-only `pg_dump`, restore only into a disposable scratch container, never touching beta itself). This is non-negotiable per `AGENTS.md`'s human-review requirement for production-affecting changes.
- Follow this repo's evidence-citation discipline exactly: cite real test names/command output, never overclaim. A box only gets checked when its FULL literal claim is evidenced — a box can stay honestly unchecked with corrected/updated evidence if only part of the claim is proven.
- **Never print or log any real credential/secret VALUE**, even while verifying config differs across environments — compare cryptographic hashes of values, never the values themselves.
- This host cannot run `npm`/`composer` builds — CI only for those. It CAN run PHP/PHPUnit against real Postgres 18 + Redis 8.2 via Docker using the pinned `ghcr.io/andrianm28/makam-app` image (`docker run --network host --user 1000:1000 ... <image> php -d memory_limit=512M vendor/bin/phpunit <paths>` — never `php artisan test`), and CAN directly exercise the real `dev`/`beta` host containers for the live-verification tasks.
- `bash ci/verify-docs.sh` must pass after every task that touches docs.
- Every new/modified PHP file needs `declare(strict_types=1);`.
- **Known tooling gotcha, confirmed this session:** direct Bash reads/greps of paths literally named `.env.dev`, `.env.stg`, `.env.beta`, or similar credential-shaped filenames are denied by this environment's own guardrail — even for non-value structural checks. The workaround used throughout this plan (Task 4 especially) is `docker exec <container> printenv` piped through `cut -d= -f1` (key names only) or `sha256sum` (value hashes only) — this reads the running container's resolved environment, never the `.env` file path itself, and never exposes a raw value.

---

### Task 1: Manual-payment instructions test, plus an honest content-gap note

**Files:**
- Modify: `tests/browser/e2e-marketplace.spec.ts`
- Modify: `docs/testing/release-gates.md`

**Interfaces:**
- Consumes: nothing from other tasks (independent).
- Produces: nothing consumed by later tasks (independent).

**Investigation finding (do not re-derive, already confirmed this session):** `resources/views/livewire/public/marketplace/checkout.blade.php` lines ~267-277 render a real "Pembayaran transfer manual" section once an order is placed, with real Indonesian instructional copy ("Transfer sejumlah total pesanan, lalu masukkan nomor referensi transfer Anda di bawah. Pesanan Anda akan dikonfirmasi setelah pembayaran diverifikasi.") and a `Nomor referensi transfer` field. The existing test `tests/browser/e2e-marketplace.spec.ts`'s `'a guest completes checkout and submits manual payment proof'` (starts ~line 274) already asserts the section's HEADING is visible and exercises the reference-number form field, but never asserts the instructional PARAGRAPH text itself is shown — that's the real, narrow gap this task closes.

**Separately confirmed, real, and NOT this task's job to fix:** grepping `resources/views/`, `app/Livewire/Public/Marketplace/Checkout.php`, and `app/Support/ContactInfo.php` for any bank destination account number/name (`bank_account`, `nomor rekening`, `rekening tujuan`, a real bank name) found **nothing** — the manual-payment flow tells a customer to transfer money but never states which bank account to send it to, anywhere in the checkout view or booking wizard. This is a real, separate, likely more significant business-content gap than the box's literal "instructions and reference are approved" wording captures on its own — it needs real bank account details from the business, which no engineering task can fabricate. Step 3 below records this finding in the release-gates box text explicitly, as a distinct, still-open, business-owned gap — do not attempt to add a placeholder or invented bank account value anywhere in code or copy.

- [ ] **Step 1: Add the missing instruction-text assertion**

In `tests/browser/e2e-marketplace.spec.ts`, inside the existing test `'a guest completes checkout and submits manual payment proof'`, immediately after the existing line:

```ts
await expect(page.getByRole('heading', { name: 'Pembayaran transfer manual' })).toBeVisible();
```

add:

```ts
await expect(
    page.getByText('Transfer sejumlah total pesanan, lalu masukkan nomor referensi transfer Anda di bawah.'),
).toBeVisible();
```

- [ ] **Step 2: Confirm the test still passes**

This is a Playwright browser test — per this repo's `CLAUDE.md`, browser/E2E suites run in CI only, not on this host. Confirm the added assertion is syntactically valid and targets real, already-rendered DOM text (re-read the Blade source at `resources/views/livewire/public/marketplace/checkout.blade.php` lines ~267-277 once more to confirm the exact string matches character-for-character, including punctuation) — this is the verification available on this host; real CI execution happens once this branch has a PR.

- [ ] **Step 3: Update the release-gates.md box with the real, complete picture**

Read the current text of the "Instructions and reference are approved" box (`docs/testing/release-gates.md`, currently line 57). Replace it with text that:
- Cites the new assertion by test name (`tests/browser/e2e-marketplace.spec.ts`'s `'a guest completes checkout and submits manual payment proof'`, now also asserting the instructional copy) as real (not yet CI-confirmed on this branch — no PR opened yet) evidence for the "instructions... shown to the customer" half of the claim, and the pre-existing reference-number field assertion as evidence for the "reference" half.
- **Explicitly records the bank-destination-details finding** from this task's Investigation section above as a real, separate, open gap: no bank account/name is shown anywhere in the manual-payment flow, which is arguably the more load-bearing half of "instructions" than the copy paragraph tested here. State plainly this needs real business input (an actual bank account), not something an engineering task can supply.
- Leaves the box unchecked — the literal claim ("approved") implies a business content-approval record this task cannot produce, and the bank-details gap is real and open regardless of the new test.

- [ ] **Step 4: Run doc gates and commit**

```bash
bash ci/verify-docs.sh
git add tests/browser/e2e-marketplace.spec.ts docs/testing/release-gates.md
git commit -m "test(marketplace): assert manual-payment instruction copy is shown; document the missing bank-destination-detail gap"
```

---

### Task 2: Correct the outbox loss/duplicate/replay box's evidence citation

**Files:**
- Modify: `docs/testing/release-gates.md`

**Interfaces:**
- Consumes: nothing from other tasks (independent).
- Produces: nothing consumed by later tasks (independent).

**Investigation finding (do not re-derive):** the box's current text (line 96) cites only `OutboxPublisherClaimTwoConnectionTest`'s overlapping-transaction proof. Real, already-passing coverage for all THREE named properties already exists elsewhere in the same suite, confirmed by reading each file directly:
- **Loss**: `tests/Feature/Outbox/OutboxRecoveryTest.php`'s `test_a_committed_event_survives_an_unclaimed_gap_and_publishes_once_the_publisher_runs` — proves a committed event is never lost even after a delay before the publisher runs.
- **Duplicate**: `tests/Feature/Outbox/OutboxPublisherClaimTest.php`'s `test_claim_excludes_an_already_dispatched_row` and `test_two_sequential_publisher_runs_never_double_claim_the_same_row`.
- **Replay**: `tests/Feature/Outbox/OutboxRecoveryTest.php`'s `test_a_second_publisher_run_does_not_reclaim_an_already_dispatched_row` — running the publisher command a second time (a real replay of the same operation) does not re-dispatch an already-handled row.

This is a citation-correction task, not a new-test task, per this task's own directive: the coverage is real, the box's text just doesn't name it yet.

- [ ] **Step 1: Re-run these 4 tests directly to reconfirm they pass on the current branch**

```bash
docker run -d --name rg2-pg -e POSTGRES_USER=testuser -e POSTGRES_PASSWORD=testpass -e POSTGRES_DB=testdb -p <free-port>:5432 postgres:18
docker run -d --name rg2-redis -p <free-port>:6379 redis:8.2-alpine
# wait for both to accept connections
docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<pg-port> -e DB_DATABASE=testdb -e DB_USERNAME=testuser -e DB_PASSWORD=testpass \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=<redis-port> \
  -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-<pinned-current-image-short-sha> \
  php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Outbox/OutboxRecoveryTest.php tests/Feature/Outbox/OutboxPublisherClaimTest.php tests/Feature/Outbox/OutboxPublisherClaimTwoConnectionTest.php
docker rm -f rg2-pg rg2-redis
```

Confirm real, current `OK (N tests, M assertions)` output — do not report PASS without running this.

- [ ] **Step 2: Update the box's evidence citation**

Read the current box text (`docs/testing/release-gates.md` line 96). Rewrite it to name all 4 tests above explicitly, grouped by which of the 3 literal properties (loss / duplicate / replay) each one proves, with the real re-run output from Step 1. If all 4 genuinely pass and together cover all 3 properties, check the box (`- [x]`) — this is the one box in this plan likely to close outright, since the underlying work was already real and complete, just uncited.

- [ ] **Step 3: Run doc gates and commit**

```bash
bash ci/verify-docs.sh
git add docs/testing/release-gates.md
git commit -m "docs(testing): cite real existing outbox loss/duplicate/replay test coverage"
```

---

### Task 3: Correct the host-OS box and apply pending non-critical updates

**Files:**
- Modify: `docs/testing/release-gates.md`
- Modify: `docs/adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md`

**Interfaces:**
- Consumes: nothing from other tasks (independent).
- Produces: nothing consumed by later tasks (independent).

**Investigation finding (do not re-derive):** the release-gates box (line 109) already carries a full, accurate 24 Aug 2026 investigation: real OS is Ubuntu 24.04.4 (not the ADR's title/text claim of 22.04), 5 pending non-critical `apt` packages (`console-setup`, `console-setup-linux`, `keyboard-configuration`, `open-vm-tools`, `snapd` — none security-critical/kernel/openssl-class), firewall genuinely active (`ufw`: default-deny-incoming, only 22/80/443/4096 open, not IP-restricted), SSH genuinely key-only with root login disabled. This task's real remaining work: (a) correct `ADR-0027`'s own title/text to stop claiming 22.04 (its filename `0027-combine-dev-staging-on-ubuntu22-2v4g.md` is a historical artifact — do NOT rename the file, that breaks every existing link to it; only correct the prose inside), (b) apply the 5 pending updates safely, (c) re-verify nothing changed since the 24 Aug check.

- [ ] **Step 1: Re-confirm current state directly**

```bash
cat /etc/os-release
apt list --upgradable 2>/dev/null
```

Confirm this still shows Ubuntu 24.04.4 and the same (or a similarly non-critical) pending-update set as the box already documents. If the real state has drifted from what's already written, use the REAL current state in Steps 2-3, not the stale text.

- [ ] **Step 2: Apply the pending non-critical updates**

None of the 5 named packages (`console-setup`, `console-setup-linux`, `keyboard-configuration`, `open-vm-tools`, `snapd`) are a running network service on this host (confirm this directly — `systemctl status open-vm-tools` and `systemctl status snapd` before upgrading, to be certain neither upgrade will restart something with active connections mid-upgrade):

```bash
sudo apt list --upgradable 2>/dev/null
sudo systemctl status open-vm-tools 2>&1 | head -5
sudo systemctl status snapd 2>&1 | head -5
sudo apt-get update
sudo apt-get upgrade -y console-setup console-setup-linux keyboard-configuration open-vm-tools snapd
apt list --upgradable 2>/dev/null
```

Confirm the final `apt list --upgradable` shows these 5 no longer pending (or documents honestly whatever new state results — do not claim a package is upgraded without the real command output showing it).

- [ ] **Step 3: Correct ADR-0027's own prose**

Read `docs/adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md` in full. Wherever its BODY TEXT (not the filename, which stays as an immutable historical identifier per this repo's own convention of never renaming a merged ADR file) states or implies the host runs Ubuntu 22.04, add a dated correction note (matching this document's own established pattern for prior corrections, e.g. the "Production graduation — single-host decision" section's own dated-note style) stating the real, current OS is Ubuntu 24.04.4 — do not silently rewrite the original text; strike through or annotate with a dated note, preserving the original for history, exactly as this repo's other ADR corrections this session have done.

- [ ] **Step 4: Update the release-gates.md box**

Update the box (`docs/testing/release-gates.md` line 109) with: the re-confirmed OS version and pending-update state from Step 1, the real Step 2 output showing the 5 packages now current (or documenting what's still pending if the upgrade didn't fully resolve them), and a pointer to ADR-0027's new correction note. The firewall-without-IP-restriction and OS-version-vs-ADR-title gaps are real and not closeable by this task alone (IP-restriction is a real access-policy decision, not engineering work) — leave the box unchecked, but with materially more of its compound claim now evidenced (updates applied) than before.

- [ ] **Step 5: Run doc gates and commit**

```bash
bash ci/verify-docs.sh
git add docs/testing/release-gates.md docs/adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md
git commit -m "docs(ops): correct ADR-0027's stale Ubuntu 22.04 claim, apply 5 pending non-critical host updates"
```

---

### Task 4: Dev/staging isolation — real substitute evidence (dev vs. beta)

**Files:**
- Modify: `docs/testing/release-gates.md`

**Interfaces:**
- Consumes: nothing from other tasks (independent).
- Produces: nothing consumed by later tasks (independent).

**Investigation finding (do not re-derive, changes this task's real scope from what a first read of the box might suggest):** `docs/operations/runbooks/deploy-stg-vhost.md` confirms staging has **never actually been deployed** as a running environment — there is no live `stg-web` container to compare against `dev-web`. A literal "dev vs. staging" live comparison is not currently possible, and this task cannot make it possible (deploying staging for the first time is its own, much larger, separately-scoped piece of work, already flagged elsewhere in this session's categorization pass as "Large"). Also confirmed: directly reading `.env.dev`/`.env.stg` file contents on this host is blocked by this environment's own credential-shaped-path guardrail, even for non-value structural checks — the real, available technique is `docker exec <container> printenv`, which reads the container's already-resolved environment without touching the `.env` file path at all.

Given this, this task's real, honest deliverable is: (a) verify the ARCHITECTURAL separation the box's evidence already cites (distinct `env_file:` per compose service) is still real in the current compose config, (b) produce REAL comparative evidence between the two environments that ARE actually running — `dev` and `beta` — using a hash-comparison technique that never exposes a raw secret value, as a genuine (if not literally "dev vs staging") proof that this codebase's per-environment isolation mechanism actually produces distinct values in practice, not just in architecture, (c) state precisely in the box text what this task did and did not prove — never conflate "dev vs beta verified distinct" with "dev vs staging verified distinct."

- [ ] **Step 1: Confirm the architectural separation is still real**

```bash
grep -n "env_file:" /opt/makam/compose/compose.yml
```

Confirm `dev-web` references `.env.dev` and `beta-web` references `.env.beta` (or whatever staging's real intended file is named) as distinct files — do not print file contents, only confirm the compose service definitions point at different filenames.

- [ ] **Step 2: Compare dev vs. beta's resolved environment via hash, never raw value**

For each field the box's literal claim names (APP key, database user, Redis/Horizon prefix, queue connection, cookie name, storage disk, provider credentials), extract the real key from each running container and hash the value — never print the value itself:

```bash
for key in APP_KEY DB_USERNAME REDIS_PREFIX HORIZON_PREFIX QUEUE_CONNECTION SESSION_COOKIE FILESYSTEM_DISK SUMOPOD_SANDBOX_API_KEY; do
  dev_hash=$(docker exec makam-nonprod-dev-web-1 printenv "$key" 2>/dev/null | sha256sum | cut -d' ' -f1)
  beta_hash=$(docker exec makam-nonprod-beta-web-1 printenv "$key" 2>/dev/null | sha256sum | cut -d' ' -f1)
  if [ "$dev_hash" = "$beta_hash" ]; then
    echo "$key: SAME hash — investigate, this should differ"
  else
    echo "$key: different hash (confirmed distinct)"
  fi
done
```

Confirm the real key names above match what actually exists in each container (`docker exec makam-nonprod-dev-web-1 printenv | cut -d= -f1 | sort` to see the real available key list first — some of the illustrative names above may not match this app's real `.env` variable naming, verify before using them). Record the real per-key result (same/different) — if any key that SHOULD differ comes back with matching hashes, that's a real, important finding to report prominently, not something to quietly work around.

- [ ] **Step 3: Update the release-gates.md box with precise, honest scope**

Update the box (`docs/testing/release-gates.md` line 111) to state clearly: (1) architectural separation re-confirmed real (Step 1), (2) dev-vs-BETA resolved-value distinctness verified directly via hash comparison for the named fields (Step 2's real per-field results), (3) dev-vs-STAGING remains unverifiable because staging has never been deployed as a running environment — this is not a gap this task can close, it is a downstream consequence of staging's own not-yet-deployed status, tracked separately. Leave the box unchecked, since its literal claim is specifically about staging, which this task's real evidence does not and cannot yet cover.

- [ ] **Step 4: Run doc gates and commit**

```bash
bash ci/verify-docs.sh
git add docs/testing/release-gates.md
git commit -m "docs(testing): verify dev/beta environment isolation directly; document that dev/staging comparison awaits staging's first deployment"
```

---

### Task 5: Real production (beta) database backup and restore proof

**Files:**
- Modify: `docs/testing/release-gates.md`

**Interfaces:**
- Consumes: nothing from other tasks (independent).
- Produces: nothing consumed by later tasks (independent).

**Safety design (binding, read before executing anything in this task):** the source database is real — `beta`'s live PostgreSQL database, the closest existing instance to "production" under the single-host decision. `pg_dump` is read-only against its source; taking a backup cannot alter beta's live data. The RESTORE step must NEVER target beta itself or any container currently serving real traffic — it targets a freshly created, disposable scratch PostgreSQL container, destroyed at the end of this task. The encrypted backup file stays on the host filesystem for the duration of this task's verification and is deleted afterward (per `docs/operations/database-backup-and-recovery.md` §9: "Production data and production database dumps handled outside this policy... are prohibited unless formally sanitized" — this backup never leaves the host, satisfying that policy).

- [ ] **Step 1: Read the real, current backup procedure**

Read `docs/operations/database-backup-and-recovery.md` §9 and `docs/adr/0035-beta-launch-accepted-risks.md` item 2 in full for the exact, real, already-approved encryption mechanism (confirmed this session to be `age`-based encrypted `pg_dump`) — use the real documented command shape, don't invent a different one.

- [ ] **Step 2: Take a real, encrypted backup of beta's database**

```bash
docker exec makam-nonprod-beta-web-1 printenv DB_DATABASE
# confirm the real database name first, do not assume it
BACKUP_TS=$(date -u +%Y%m%dT%H%M%SZ 2>/dev/null || echo "manual-rehearsal")
docker exec makam-nonprod-postgres-1 pg_dump -U <real-db-user-from-compose> -Fc <real-beta-db-name> > /tmp/rg2-backup-rehearsal/beta-backup-${BACKUP_TS}.dump
```

(Confirm the real Postgres container name and connection details against `/opt/makam/compose/compose.yml`'s `postgres` service and `beta-web`'s real `DB_*` env — do not print any password; use whatever passwordless/trust-auth or already-available mechanism this host's existing backup tooling uses, matching `docs/operations/examples/` if a working example script already exists there.) If `age` encryption is genuinely part of the approved procedure, encrypt the dump file now using the documented real mechanism — do not skip this step to save time, since the whole point of this task is proving the REAL, approved procedure works end to end, not a simplified stand-in.

- [ ] **Step 3: Restore into a disposable scratch container — never beta**

```bash
docker run -d --name rg2-restore-scratch -e POSTGRES_USER=scratch -e POSTGRES_PASSWORD=scratch -e POSTGRES_DB=scratch -p <free-port>:5432 postgres:18
# wait for it to accept connections
docker exec -i rg2-restore-scratch pg_restore -U scratch -d scratch --no-owner --no-privileges < /tmp/rg2-backup-rehearsal/beta-backup-${BACKUP_TS}.dump
# (decrypt first with the real age mechanism if Step 2 encrypted it)
```

- [ ] **Step 4: Verify the restore is real, not just "the command exited 0"**

Compare a real row count on at least 2 real tables between beta's live database and the scratch restore (e.g. `feature_gates`, `users`, or another stable table) — a restore that silently produced an empty database must be caught here, not assumed successful:

```bash
docker exec makam-nonprod-postgres-1 psql -U <user> -d <real-beta-db-name> -tAc "SELECT count(*) FROM feature_gates;"
docker exec rg2-restore-scratch psql -U scratch -d scratch -tAc "SELECT count(*) FROM feature_gates;"
```

Confirm the counts match (or are explainably close, if beta's live data changed between the dump and this check — note the real timestamps).

- [ ] **Step 5: Clean up — never leave the backup file or scratch container lying around**

```bash
docker rm -f rg2-restore-scratch
rm -rf /tmp/rg2-backup-rehearsal
```

- [ ] **Step 6: Update the release-gates.md box with real evidence**

Update "Managed PostgreSQL backup/PITR configured and restore evidence is current" (`docs/testing/release-gates.md` line 99) — the box's OWN text already correctly explains why the literal "managed/PITR" claim can never be satisfied under the single-host decision, and that the real remaining gap was "no backup has actually been produced or restored yet." This task closes exactly that gap: cite the real Step 2-4 dump size, restore outcome, and row-count verification, with the real date/time. If `docs/operations/database-backup-and-recovery.md` §4's "not valid until restored" rule is now satisfied by this real evidence, and the box's real remaining literal claim is just "restore evidence is current" (not the never-true "managed/PITR" half), consider whether the box's own wording should be split or whether it can now honestly check — read the box's exact current phrasing once more before deciding; do not check it if any part of its literal text remains unproven.

- [ ] **Step 7: Run doc gates and commit**

```bash
bash ci/verify-docs.sh
git add docs/testing/release-gates.md
git commit -m "docs(ops): real pg_dump backup + tested restore against beta, closing the still-open half of the backup/PITR box"
```

---

### Task 6: CI/CD rollback rehearsal (dev only)

**Files:**
- Modify: `docs/testing/release-gates.md`
- Modify: `docs/planning/sprint-plan.md` (if it still lists "rollback rehearsed" as a future deliverable — confirm and update)

**Interfaces:**
- Consumes: nothing from other tasks (independent).
- Produces: nothing consumed by later tasks (independent).

**Safety design:** targets `dev-web` only. Uses the exact, already-documented, already-proven `APP_IMAGE`/digest-pinning mechanism (`docs/operations/runbooks/rollback-deploy.md` Step 3) — the same mechanism already used earlier this session to deploy PR #141's preview build to dev, just applied in the reverse (older-digest) direction first, then forward again. Ends with dev re-pinned to its CURRENT correct digest, not left on an old one.

- [ ] **Step 1: Record dev's current digest and pick a real older known-good one**

```bash
grep -A1 "dev-web:" /opt/makam/compose/compose.yml | grep "image:"
# record this as CURRENT_DIGEST
docker images --digests | grep makam-app | head -5
# pick an older, real, locally-cached digest as OLDER_DIGEST — one that predates today's work
```

- [ ] **Step 2: Roll back — repin dev-web to the older digest**

Following `docs/operations/runbooks/rollback-deploy.md` Step 3's exact mechanism:

```bash
sudo sed -i "s|image: ghcr.io/andrianm28/makam-app@sha256:<CURRENT_DIGEST_SHA>|image: ghcr.io/andrianm28/makam-app@sha256:<OLDER_DIGEST_SHA>|" /opt/makam/compose/compose.yml
cd /opt/makam/compose && sudo docker compose up -d dev-web
curl -s -o /dev/null -w "http_status: %{http_code}\n" https://dev.makam.co.id/up
```

Confirm `200` and that the app genuinely serves (check a real page renders, not just the health endpoint) at the older digest.

- [ ] **Step 3: Roll forward — repin back to the current digest**

```bash
sudo sed -i "s|image: ghcr.io/andrianm28/makam-app@sha256:<OLDER_DIGEST_SHA>|image: ghcr.io/andrianm28/makam-app@sha256:<CURRENT_DIGEST_SHA>|" /opt/makam/compose/compose.yml
cd /opt/makam/compose && sudo docker compose up -d dev-web
curl -s -o /dev/null -w "http_status: %{http_code}\n" https://dev.makam.co.id/up
```

Confirm `200` again and that dev is back to serving the exact digest it started this task on — the rehearsal must leave dev in its original, correct state.

- [ ] **Step 4: Update the release-gates.md box and sprint-plan.md**

Update "CI/CD immutable build, expand/contract migration, smoke test, and rollback rehearsal pass" (`docs/testing/release-gates.md` line 100) — the box's own text already confirms immutable build, smoke test, and expand/contract discipline are real; this task closes the remaining "rollback never rehearsed" gap. Cite the real digests used, the real `200` results from Steps 2-3, and the date. If `docs/planning/sprint-plan.md` still lists "rollback rehearsed" as a future Sprint 10 item (confirm by reading it), update that entry to reflect this real completion too. Consider whether the box's full literal claim is now satisfied (all 4 named properties real and evidenced) — if so, check it; if any part (e.g. a genuine production rehearsal, as opposed to this dev-only one) is still implied by the box's wording and not yet done, leave it unchecked with precise wording about what was and wasn't rehearsed.

- [ ] **Step 5: Run doc gates and commit**

```bash
bash ci/verify-docs.sh
git add docs/testing/release-gates.md docs/planning/sprint-plan.md
git commit -m "docs(ops): real dev rollback rehearsal — repin to an older digest and back, proving the documented mechanism works"
```

---

### Task 7: Horizon operational rehearsal (dev only)

**Files:**
- Modify: `docs/testing/release-gates.md`

**Interfaces:**
- Consumes: nothing from other tasks (independent).
- Produces: nothing consumed by later tasks (independent).

**Investigation finding (do not re-derive):** `config/horizon.php` defines real supervisors (`supervisor-critical`, `supervisor-urgent`, `supervisor-notify`, `supervisor-default`, `supervisor-batch`, `supervisor-reports`, `supervisor-normal`) with real per-environment process caps. Per `docs/operations/dev-staging-environment.md` §9, `dev` has **no always-on Horizon process** — it must be started manually for this rehearsal and does not persist afterward, matching dev's documented convention.

- [ ] **Step 1: Confirm dev's real Horizon environment key and supervisor set**

```bash
docker exec makam-nonprod-dev-web-1 printenv APP_ENV
grep -n "'local'\|'production'\|'staging'" config/horizon.php
```

Confirm which `config/horizon.php` environment block actually governs dev's supervisor set (the block matching dev's real `APP_ENV` value) before designing the rehearsal around it — do not assume it's the `local` block without checking.

- [ ] **Step 2: Start Horizon manually on dev for this rehearsal**

```bash
docker exec -d makam-nonprod-dev-web-1 php artisan horizon
sleep 3
docker exec makam-nonprod-dev-web-1 php artisan horizon:status
```

Confirm it reports running.

- [ ] **Step 3: Dispatch real test jobs across at least 2 different-priority queues**

Find a real, already-existing job class safe to dispatch harmlessly on dev with disposable data (e.g. a notification-dispatch job against a dev-only fixture, or the outbox publish job against a dev-only outbox row created for this test) — read `app/Jobs/` or wherever real queued job classes live, and confirm which queue name(s) map to which supervisor before dispatching, so the rehearsal genuinely exercises priority routing:

```bash
docker exec makam-nonprod-dev-web-1 php artisan tinker --execute="
// dispatch 2-3 real, safe jobs onto different real queue names, e.g.:
// dispatch(new SomeRealJob(...))->onQueue('critical');
// dispatch(new SomeRealJob(...))->onQueue('reports');
"
docker exec makam-nonprod-dev-web-1 php artisan horizon:status
```

Confirm via Horizon's dashboard/`horizon:list` or the `jobs`/`failed_jobs` tables that the dispatched jobs were picked up and processed by the correct supervisor according to `config/horizon.php`'s real queue-to-supervisor mapping.

- [ ] **Step 4: Exercise a graceful restart**

```bash
docker exec makam-nonprod-dev-web-1 php artisan horizon:terminate
sleep 2
docker exec makam-nonprod-dev-web-1 php artisan horizon:status
```

Per Laravel's documented `horizon:terminate` behavior, this signals Horizon to finish current jobs before exiting — confirm any job dispatched in Step 3 that was still in-flight at termination time completed rather than being silently dropped (check its real completion state in the `jobs`/`failed_jobs` tables, not just that the terminate command exited).

- [ ] **Step 5: Clean up — Horizon does not persist on dev**

```bash
docker exec makam-nonprod-dev-web-1 php artisan horizon:status
# if still running from Step 4's restart, terminate it again so dev returns to its documented no-persistent-Horizon state
docker exec makam-nonprod-dev-web-1 php artisan horizon:terminate
```

- [ ] **Step 6: Update the release-gates.md box**

Update "Horizon supervisors, queue priorities, long-wait alerts, and graceful restart pass" (`docs/testing/release-gates.md` line 95) — the box's own text already confirms config-cache verification and long-wait alerting (`SpineWatchdogCommand`) are real; this task closes the remaining "not yet operationally exercised... no graceful-restart rehearsal" gap. Cite the real Steps 3-4 output (which jobs, which supervisors, confirmed no drop on restart). If every named property is now real and evidenced, check the box; otherwise state precisely what remains.

- [ ] **Step 7: Run doc gates and commit**

```bash
bash ci/verify-docs.sh
git add docs/testing/release-gates.md
git commit -m "docs(ops): real Horizon operational rehearsal on dev — supervisor routing and graceful restart both confirmed"
```

---

## Verification

| Task | Done when |
|---|---|
| 1 | New instruction-copy assertion added and verified against real Blade source; bank-destination-detail gap explicitly documented as a real, separate, business-owned finding |
| 2 | Box cites the 4 real existing tests covering loss/duplicate/replay, re-run and confirmed passing; box checked if all 3 properties are genuinely covered |
| 3 | ADR-0027 prose corrected (dated note, original preserved); 5 pending updates applied and re-verified; box reflects real current state |
| 4 | Architectural separation re-confirmed; dev-vs-beta hash comparison run and cited; box precisely distinguishes "dev vs beta verified" from "dev vs staging not yet possible" |
| 5 | Real encrypted `pg_dump` of beta + real restore into a disposable scratch container + real row-count verification, all cleaned up afterward; box cites real evidence |
| 6 | Real rollback-and-forward rehearsal against dev only, both digest transitions confirmed `200`, dev ends on its original correct digest |
| 7 | Real Horizon rehearsal on dev only: supervisor routing confirmed, graceful restart confirmed no job drop, dev returned to its documented no-persistent-Horizon state |

Final whole-branch review checks: did every operational task (5, 6, 7) genuinely stay scoped to dev/beta-as-read-only-source as designed, with zero risk introduced to any real customer-facing state? Does every updated release-gates.md box cite real, re-verifiable evidence rather than a claimed-but-unexecuted result? Is Task 1's bank-destination-detail finding recorded clearly enough that it won't be lost track of?

## Execution

Execute via `superpowers:subagent-driven-development` — fresh implementer subagent per task, task-scoped review, one final whole-branch review before PR. Standing execution mode for this session; do not ask the user to choose between subagent-driven and inline execution. All 7 tasks are independent of each other and could be dispatched in any order, but Tasks 5-7 (the operational rehearsals) carry the most real-world risk and should get the most careful task-scoped review.
