# Runbook: Staging Backup and Restore (`makam_stg`) — v0.1

## Status

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

## BLOCKED ON OQ-4 — read this before doing anything else

Per `sprint-plan.md` §13, **OQ-4**: *"S3-compatible object storage — which
provider? ... Undecided — blocks S2-T7."* This is also called out explicitly
in `agent-execution-plan.md` §4, Batch 2.6: *"Blocked: Agent C cannot finish
without an object storage provider."*

**What is missing:**

1. A chosen S3-compatible object storage provider (any provider exposing an
   S3-compatible API — the destination is not required to be AWS; see
   `CLAUDE.md` in this repo: *"No AWS in this project... documented runtime
   is Docker containers on a single Ubuntu host."*).
2. A provisioned bucket on that provider, scoped to staging backups only
   (`dev-staging-environment.md` §4 requires object-storage isolation
   per environment even though this specific bucket is for backups, not
   application file uploads).
3. Real access credentials (access key / secret key) for that bucket, with
   write and delete permission scoped to the backup prefix.
4. An `age` recipient key pair generated for backup encryption, with the
   **public** key made available to this host and the **private** key kept
   **offline**, held by whoever is authorized to perform restores (see
   `backup-staging.sh` comments on why the private key must not live on the
   staging host).

**What this task could produce without that decision, and did:** a
parameterized, syntax-checked backup script
([`examples/backup-staging.sh`](examples/backup-staging.sh)) that implements
the pg_dump, encryption, checksum, upload, and retention logic against
placeholder environment variables, plus this restore procedure and evidence
template. Nothing provider-specific was chosen or invented, per the task
constraint that provider selection is a human/product decision (OQ-4), not
an agent decision.

**What a human needs to do to unblock this:**

1. Choose an S3-compatible provider (decision owner: Product, per
   `sprint-plan.md` §13 OQ-4 row and §10 "Not delegable to an agent at all").
2. Provision a bucket/prefix for staging backups.
3. Generate access credentials scoped to that bucket/prefix only (least
   privilege — write + delete on the backup prefix, not account-wide).
4. Generate an `age` key pair; distribute the public key to the host, keep
   the private key offline with the restore operator.
5. Populate `BACKUP_S3_ENDPOINT`, `BACKUP_S3_BUCKET`, `BACKUP_S3_ACCESS_KEY`,
   `BACKUP_S3_SECRET_KEY`, and `BACKUP_AGE_RECIPIENT` (see
   `backup-staging.sh` for the full variable list) via protected
   environment/secret injection — never inline in the script or in Git.
6. Schedule `backup-staging.sh` (cron or a systemd timer) for a daily run.
7. Only after a backup has actually landed in remote storage: execute §3–§4
   of this runbook and record real evidence. Until then, every checklist
   item below is `NOT TESTED`, not `PASS` — see
   [`AGENTS.md`](../../AGENTS.md): *"Never report `PASS` for a check that
   was not executed."*

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

1. **A backup actually exists in remote object storage.** Confirm via
   `aws --endpoint-url "$BACKUP_S3_ENDPOINT" s3 ls "s3://$BACKUP_S3_BUCKET/$BACKUP_S3_PREFIX/"`
   or the equivalent for the chosen provider. If OQ-4 is still open, this
   precondition cannot be met — stop and see the section above.
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
- No object storage provider was chosen, no bucket was provisioned, and no
  credential of any kind (real or placeholder-looking) was generated.
- The role-separation state of the live host was not verified (see previous
  section).
- `docs/operations/database-backup-and-recovery.md` was read but not edited.
