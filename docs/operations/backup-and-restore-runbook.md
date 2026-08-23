# Runbook: Staging Backup and Restore (`makam_stg`) — v0.2

## Status

**OQ-4 (storage provider) is resolved as of 23 Aug 2026 — self-hosted, not an external
S3-compatible provider — per [`ADR-0027`](../adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md)'s
"Production graduation — single-host decision" section. See the section below for what that
does and does not change.** This does **not** mean a backup has been produced or a restore
executed — that remains true regardless, see "What was explicitly NOT done" at the end of this
document. **OQ-7** (a real, fail-closed malware scanner for production document uploads) is a
separate, still-unresolved question that this resolution does not touch — see the section below.

**Prepared, not executed.** This runbook and its companion script,
[`examples/backup-staging.sh`](examples/backup-staging.sh), were produced for
Sprint 2 Batch 2.6 Agent C
([`docs/planning/agent-execution-plan.md`](../planning/agent-execution-plan.md)
§4, human gate **G5**), implementing `sprint-plan.md` **S2-T7**. No command
in this document has been run by the preparing agent. `pg_dump` has not been
invoked, no backup exists, and no restore has been executed. The live
database has not been touched.

This document is the restore **procedure**. It is not, itself, restore
**evidence** — evidence only exists once a human has actually run the
procedure and filled in the template in §4.

## OQ-4 resolved (23 Aug 2026) — self-hosted storage, not external S3

Per `sprint-plan.md` §13, **OQ-4** originally read: *"S3-compatible object
storage — which provider? ... Undecided — blocks S2-T7."* Per
[`ADR-0027`](../adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md)'s
"Production graduation — single-host decision" section (23 Aug 2026), **OQ-4
is now resolved**: the answer is not a chosen external S3-compatible
provider — it is that no external provider is used at all. Storage is
self-hosted on the same shared `yiemvm` host, not an external endpoint.

**Resolved answer, stated plainly:** there is no S3-compatible bucket,
endpoint, or access-key/secret-key pair to provision for this runbook's own
backup destination, because the destination is not going to be an external
S3-compatible service. This is a different resolution than "OQ-4 was decided
in favor of provider X" — the decision was to not use an external provider
at all.

**What this changes, and what it does not:**

- The original "What is missing" list below (items 1–3: choosing a provider,
  provisioning a bucket, generating S3 access credentials) is now moot —
  struck through here as the historical record of what this runbook assumed
  before the decision, not a live checklist:
  1. ~~A chosen S3-compatible object storage provider.~~
  2. ~~A provisioned bucket on that provider, scoped to staging backups only.~~
  3. ~~Real access credentials (access key / secret key) for that bucket.~~
- Item 4 (an `age` recipient key pair for backup encryption, public key on
  the host, private key held offline by the restore operator) is **not**
  moot — encryption remains required regardless of where the backup lands;
  self-hosted storage is not automatically safe storage. This still needs to
  be generated and has not been.
- [`examples/backup-staging.sh`](examples/backup-staging.sh) still targets
  `BACKUP_S3_ENDPOINT` / `BACKUP_S3_BUCKET` / `BACKUP_S3_ACCESS_KEY` /
  `BACKUP_S3_SECRET_KEY` (an S3-compatible upload). It has not been rewritten
  for a self-hosted destination. Updating that script is real, necessary
  follow-up work, but is out of scope for this documentation task — Task 4 of
  `docs/superpowers/plans/2026-08-23-single-host-production-decision.md` owns
  only this file and `database-backup-and-recovery.md`.
- A concrete self-hosted backup destination — a path/volume on the host, its
  retention/rotation policy, and whether it is the same location the
  application's `LocalFilesystemObjectStorage` uses for document uploads or a
  dedicated, separate directory/volume reserved for backups — has not been
  decided or provisioned. This document does not invent one; that is a real
  decision for whoever updates `backup-staging.sh` next.
- No backup has actually been produced anywhere, and no restore has been
  executed. Resolving OQ-4 does not change this — see "What was explicitly
  NOT done" at the end of this document, which remains accurate. Every
  checklist item in §4 below is `NOT TESTED`, not `PASS`, until a human
  actually runs the procedure — see [`AGENTS.md`](../../AGENTS.md): *"Never
  report `PASS` for a check that was not executed."*

**OQ-7 is a separate, still-open question — not resolved by this.**
`ADR-0027`'s single-host decision explicitly does NOT reverse the
"always-on ClamAV... prohibited" clause; a real, fail-closed malware scanner
for production document uploads remains undecided (**OQ-7**). This runbook
was never blocked on OQ-7 (it covers PostgreSQL backup/restore, not
document-upload scanning) and OQ-4's resolution does not touch it — do not
read "storage is resolved" as implying production document uploads are safe
to accept for real.

**What a human still needs to do, now that OQ-4 itself is resolved:**

1. Decide the concrete self-hosted backup destination (path/volume,
   retention policy, dedicated vs. shared with document-upload storage).
2. Update `examples/backup-staging.sh` to target that destination instead of
   `BACKUP_S3_*` variables.
3. Generate an `age` key pair; distribute the public key to the host, keep
   the private key offline with the restore operator.
4. Schedule the updated `backup-staging.sh` (cron or a systemd timer) for a
   daily run.
5. Only after a backup has actually landed at the real destination: execute
   §3–§4 of this runbook and record real evidence.

## Scope

Restore procedure for `makam_stg` logical backups produced by
[`examples/backup-staging.sh`](examples/backup-staging.sh). Governed by, and
reproducing only the evidence **fields** from — not restating the prose
of — the canonical policy document:

- [`docs/operations/database-backup-and-recovery.md`](database-backup-and-recovery.md)
  §4 (a backup is not valid until restored), §5 (restore validation
  checklist), §9 (non-production combined-host policy)
- [`docs/operations/dev-staging-environment.md`](dev-staging-environment.md)
  §12 (backup and recovery for the combined host)
- [`docs/planning/sprint-plan.md`](../planning/sprint-plan.md) S2-T7 task
  detail and OQ-4 (§13)

This runbook does **not** restate `database-backup-and-recovery.md`'s
policy prose. If this document and the canonical file ever appear to
disagree, the canonical file governs — fix this file, don't work around it.

## Preconditions — do not proceed until all are true

1. **A backup actually exists at its self-hosted destination.** The
   `aws --endpoint-url ... s3 ls ...` command previously shown here assumed
   an external S3-compatible provider, which is no longer the plan (see the
   OQ-4 section above). The real verification command depends on the
   concrete self-hosted destination `backup-staging.sh` is updated to write
   to — not yet decided or implemented. Until that follow-up lands, this
   precondition cannot actually be verified — stop and see the section above.
2. **The `.sha256` sidecar for the backup you intend to restore is present**
   alongside it.
3. **The `age` private key is available to the operator performing the
   restore**, obtained through its offline channel — not from this host.
4. **A restore target exists that is not the live `makam_stg` database.**
   Never restore over live staging data. Use a separate, disposable database
   on the same PostgreSQL cluster (e.g. `makam_stg_restore_verify`) or a
   throwaway PostgreSQL instance. `database-backup-and-recovery.md` §1
   requires "restore to a separate instance" for production; the same
   principle scaled to the combined host means restoring to a separate
   database, not overwriting `makam_stg`.
5. **Human gate G5 sign-off to proceed** — this is a human-executed
   procedure per `agent-execution-plan.md` §4; the preparing agent does not
   run it.

## Restore procedure

**Storage-destination note (23 Aug 2026):** steps 1–2 below describe
locating and downloading a backup from an S3-compatible bucket — written
against the old, unresolved-provider assumption. Per the OQ-4 resolution
above, the real destination is self-hosted storage on this host, once
`examples/backup-staging.sh` is updated to target it (not yet done — see
above); steps 1–2's exact commands are retained as illustrative until that
lands. Steps 3–9 (checksum verification, decryption, restore-to-scratch-
database, evidence capture, cleanup) do not depend on the storage backend
and remain accurate as written.

1. **Identify the backup to restore.** List objects under
   `s3://$BACKUP_S3_BUCKET/$BACKUP_S3_PREFIX/`, pick the target
   `makam_stg_<timestamp>.dump.age` file, and note its exact name and the
   time the restore starts (needed for the evidence template's duration
   field).
2. **Download the backup and its checksum sidecar** to the restore
   workstation/host — not necessarily the staging host itself.
3. **Verify integrity before decrypting:**
   ```bash
   sha256sum -c makam_stg_<timestamp>.dump.age.sha256
   ```
   Stop and escalate if this fails — do not attempt to restore a backup that
   fails checksum verification.
4. **Decrypt with the offline `age` private key:**
   ```bash
   age --decrypt --identity /path/to/offline-private-key.txt \
     --output makam_stg_<timestamp>.dump \
     makam_stg_<timestamp>.dump.age
   ```
5. **Create the disposable restore-target database** on the PostgreSQL
   cluster (do not reuse `makam_stg`):
   ```bash
   psql -U postgres_admin -c "CREATE DATABASE makam_stg_restore_verify OWNER makam_stg_user;"
   ```
6. **Restore the custom-format dump:**
   ```bash
   pg_restore --no-owner -d makam_stg_restore_verify makam_stg_<timestamp>.dump
   ```
   (`--no-owner` avoids failing on role differences between the dump's
   source and the restore target; adjust if role separation, per the open
   item in §5 below, changes what's appropriate.)
7. **Record the evidence fields in §4 of this document** — this is the step
   that makes the restore count as validated per
   `database-backup-and-recovery.md` §4.
8. **Drop the disposable database** once evidence has been captured and
   signed off:
   ```bash
   psql -U postgres_admin -c "DROP DATABASE makam_stg_restore_verify;"
   ```
9. **Securely delete the decrypted plaintext dump** (`makam_stg_<timestamp>.dump`)
   from the restore workstation once validation is complete — the decrypted
   form should not persist longer than the validation window.

## Evidence template

Fill in every field for each restore test performed. The field set below
reproduces the required evidence categories from
`database-backup-and-recovery.md` §4 ("source backup, restore target,
duration, row/invariant checks, application smoke test, and sign-off") and
the detailed checklist in §5 — see that document for the full definition of
each check; this table is the fill-in record, not a restatement of the
policy.

| Field | Value |
|---|---|
| Source backup filename | |
| Source backup SHA-256 | |
| Checksum verified before decrypt? (Y/N) | |
| Restore target (database/instance name) | |
| Restore started (UTC) | |
| Restore completed (UTC) | |
| Duration | |
| Database version confirmed matches baseline (§5.1) | |
| `pg_trgm` extension present (§5.1) | |
| `unaccent` extension present (§5.1) | |
| Migration state verified — matches expected migration batch (§5.2) | |
| Row counts recorded per table and match expectation (§5.3) | |
| Critical foreign keys verified intact (§5.3) | |
| Financial balanced-batch invariants and unique keys checked (§5.4) | |
| No duplicate active reservation / renewal period / reminder window / billing cycle (§5.5) | |
| Authentication smoke test passed (§5.6) | |
| Booking smoke test passed (§5.6) | |
| Payment-reference smoke test passed (§5.6) | |
| Renewal smoke test passed (§5.6) | |
| File-reference smoke test passed (§5.6) | |
| Queue/outbox replay behavior confirmed before reconnecting providers (§5.7) | |
| Overall outcome | `PASS` / `FAIL` / `BLOCKED` — no fourth option, per `AGENTS.md` |
| Operator (name/role) | |
| Sign-off date (UTC) | |
| Notes / anomalies | |

## Open item surfaced, not resolved by this task

See also the self-hosted backup-destination follow-up named in the "OQ-4
resolved" section above (a concrete path/volume, retention policy, and
`backup-staging.sh` rewrite) — a separate, second open item this task
surfaces but does not resolve.

`database-backup-and-recovery.md` §8 requires separate application and
migration PostgreSQL roles. `sprint-plan.md` finding **N-1** states this was
"fixed in the S1-T3 DDL" and the Sprint 1 status table marks S1-T3 `✅ done`
on the actual host — but the checked-in example,
[`examples/postgres-init/01-create-databases.sh`](examples/postgres-init/01-create-databases.sh),
still shows a single `makam_stg_user` role used for everything, and no
distinct backup/read-only role is documented anywhere in this repo. Per the
task brief for this runbook, `backup-staging.sh` was written against the
single `makam_stg_user` / `makam_stg_db_password` pattern that **is**
documented in `docker-compose.dev-stg.yml`, since that is the only role
pattern visible in files this task is scoped to read. If the actual deployed
host now has a separate least-privilege role suitable for backups (or a
dedicated migration role that should be used instead), `STG_DB_USER` and
`STG_DB_PASSWORD_FILE_IN_CONTAINER` in `backup-staging.sh` should be updated
to match — this was not verified and is not fixed here, because it falls
outside this task's owned files and would require confirming live host
state this agent has no access to.

## What was explicitly NOT done

- No `pg_dump`, `age`, or `aws s3` command was executed.
- No backup exists yet, anywhere.
- No restore was performed; every field in the evidence template above is
  blank.
- No S3-compatible object storage provider was chosen — OQ-4 is instead
  resolved as self-hosted, not external, per the section above. No
  self-hosted backup destination was provisioned, and no credential or `age`
  key of any kind (real or placeholder-looking) was generated.
- The role-separation state of the live host was not verified (see previous
  section).
- **Update (23 Aug 2026):** `docs/operations/database-backup-and-recovery.md`
  was read but not edited *by the agent that originally prepared this
  runbook*; it has since been updated in a later, separate task (Task 4 of
  `docs/superpowers/plans/2026-08-23-single-host-production-decision.md`) to
  correct its production-backup framing for the same single-host decision
  this section describes.
