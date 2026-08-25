#!/usr/bin/env bash
#
# backup-staging.sh — encrypted daily logical backup of makam_stg to remote
# S3-compatible object storage.
#
# STATUS: PREPARED, NOT EXECUTED. Produced for Sprint 2 Batch 2.6 Agent C
# (docs/planning/agent-execution-plan.md §4, human gate G5). Per
# sprint-plan.md S2-T7 / OQ-4, this task is BLOCKED: no S3-compatible object
# storage provider has been chosen, so BACKUP_S3_* below have no real values
# to point at. The preparing agent has not run this script, has no
# credentials, and has not touched the live database. See the "BLOCKED ON
# OQ-4" section of ../backup-and-restore-runbook.md before treating this as
# runnable.
#
# Scope: makam_stg ONLY. dev-staging-environment.md §12 requires backups for
# staging, not makam_dev — development data is disposable by default.
#
# Canonical policy this script implements (read these, not restated here):
#   docs/operations/database-backup-and-recovery.md §4 (a backup is not
#     considered valid until restored), §9 (non-production combined-host
#     policy: daily encrypted logical backup, remote object storage, >= 7
#     days retention)
#   docs/operations/dev-staging-environment.md §12
#
# Every value under "S3-COMPATIBLE OBJECT STORAGE (OQ-4 — placeholders only)"
# below is a PLACEHOLDER. Do not put real endpoints, keys, or bucket names in
# this file or in Git. Populate them via environment variables (a systemd
# EnvironmentFile, an .env consumed by the invoking cron/systemd-timer unit,
# or equivalent secret injection) once a provider is chosen.
#
# Secret handling for the database password follows the pattern already
# wired in docs/operations/examples/docker-compose.dev-stg.yml: the
# makam_stg_db_password secret is mounted into the postgres container at
# /run/secrets/makam_stg_db_password. This script never reads that file from
# the host — it execs into the postgres container (which already has the
# secret mounted) via `docker compose exec` and reads it there, so the
# plaintext password never touches this script's own environment or logs.
#
# Encryption: age (https://age-encryption.org), not gpg.
#   - age does asymmetric (recipient public-key) encryption with no keyring,
#     agent, or trust database to manage — a good fit for an unattended cron
#     job on a resource-constrained host (dev-staging-environment.md §1: 2
#     vCPU / 4 GB).
#   - The encryption key (BACKUP_AGE_RECIPIENT, a public key) is safe to keep
#     on this host. The matching PRIVATE key must NOT live on this host —
#     keep it offline/with the human restore operator, so a compromise of
#     the staging host alone cannot decrypt existing backups. gpg symmetric
#     encryption would require a passphrase to be readable by this
#     unattended script, which is a materially worse exposure; gpg
#     public-key mode works too but drags in keyring/trust-database state
#     this host doesn't need. age was chosen for the smaller, simpler
#     unattended-operation footprint.
#
# Retention: this script deletes remote backups older than
# BACKUP_RETENTION_DAYS (default 7, and the script refuses to run with a
# value below 7 — database-backup-and-recovery.md §9). It always keeps at
# least MIN_BACKUPS_TO_KEEP most-recent backups regardless of age, as a
# guard against a misconfigured/clock-skewed retention window silently
# deleting every backup.
#
# Checksum: a sha256sum is computed over the ENCRYPTED backup file and
# uploaded alongside it as a `.sha256` sidecar, so integrity can be verified
# before decrypt/restore without ever needing the private key.
#
# This script is READ-ONLY with respect to makam_stg (pg_dump only). It does
# not restore, migrate, or mutate the source database. It does not run
# `docker compose exec` on anything other than the read-only pg_dump
# invocation below.

set -euo pipefail

# -----------------------------------------------------------------------------
# CONFIGURATION — environment variables. Every one of these can be overridden
# by exporting it before invocation (or via an EnvironmentFile). Defaults are
# safe for the documented dev/staging topology; nothing here is a secret.
# -----------------------------------------------------------------------------

# Where the deployed compose stack lives on the combined dev/staging host.
# docs/operations/runbooks/deploy-stg-vhost.md references
# /opt/makam/compose/compose.yml as the deployed (non-example) compose file;
# this default follows that same convention. Override if the real deployed
# path differs.
COMPOSE_FILE="${COMPOSE_FILE:-/opt/makam/compose/compose.yml}"
COMPOSE_CMD="${COMPOSE_CMD:-docker compose}"
POSTGRES_SERVICE="${POSTGRES_SERVICE:-postgres}"

# Staging database identity — matches docs/operations/examples/postgres-init/01-create-databases.sh
STG_DB_NAME="${STG_DB_NAME:-makam_stg}"
STG_DB_USER="${STG_DB_USER:-makam_stg_user}"
# Path INSIDE the postgres container (not on the host) — see
# docker-compose.dev-stg.yml `secrets:` — the makam_stg_db_password secret is
# mounted there for the postgres service already.
STG_DB_PASSWORD_FILE_IN_CONTAINER="${STG_DB_PASSWORD_FILE_IN_CONTAINER:-/run/secrets/makam_stg_db_password}"

# Local staging area for the encrypted file before/during upload. Files here
# are deleted after a confirmed successful remote upload — this directory is
# NOT the backup of record (dev-staging-environment.md: "Local Docker
# volumes are not backups"; the same reasoning applies to any local-disk
# staging copy on this 2 vCPU / 4 GB host).
BACKUP_LOCAL_STAGING_DIR="${BACKUP_LOCAL_STAGING_DIR:-/var/backups/makam-stg}"
BACKUP_LOG_FILE="${BACKUP_LOG_FILE:-${BACKUP_LOCAL_STAGING_DIR}/backup.log}"

# Retention. database-backup-and-recovery.md §9: ">= 7 days".
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-7}"
# Safety floor: never let retention pruning remove every backup, regardless
# of BACKUP_RETENTION_DAYS or host clock issues.
MIN_BACKUPS_TO_KEEP="${MIN_BACKUPS_TO_KEEP:-1}"

# Encryption recipient (age public key, e.g. "age1...."). PLACEHOLDER —
# populate with a real recipient once one is generated. The matching private
# key must be stored OFFLINE by the restore operator, never on this host.
BACKUP_AGE_RECIPIENT="${BACKUP_AGE_RECIPIENT:-CHANGE_ME_AGE_PUBLIC_KEY}"

# ---- S3-COMPATIBLE OBJECT STORAGE (OQ-4 — placeholders only) --------------
# None of these have real values. sprint-plan.md OQ-4: "S3-compatible object
# storage — which provider? ... Undecided — blocks S2-T7." This script is
# written generically against any S3-compatible endpoint (via `aws s3
# --endpoint-url`) so it can target whichever provider is chosen without
# further rewrite — only these environment variables need to change.
BACKUP_S3_ENDPOINT="${BACKUP_S3_ENDPOINT:-CHANGE_ME_S3_ENDPOINT}"
BACKUP_S3_BUCKET="${BACKUP_S3_BUCKET:-CHANGE_ME_S3_BUCKET}"
BACKUP_S3_PREFIX="${BACKUP_S3_PREFIX:-postgres/makam_stg}"
BACKUP_S3_ACCESS_KEY="${BACKUP_S3_ACCESS_KEY:-CHANGE_ME_S3_ACCESS_KEY}"
BACKUP_S3_SECRET_KEY="${BACKUP_S3_SECRET_KEY:-CHANGE_ME_S3_SECRET_KEY}"
BACKUP_S3_REGION="${BACKUP_S3_REGION:-auto}"
# Path-style vs virtual-hosted-style addressing is provider-dependent and
# has NOT been verified against any real provider (none is chosen — OQ-4).
# NOT TESTED: once a provider is picked, confirm whether
# `aws configure set default.s3.addressing_style path` (or the provider's
# documented equivalent) is required for `aws s3` against its endpoint, and
# update this script rather than guessing.
BACKUP_S3_ADDRESSING_STYLE="${BACKUP_S3_ADDRESSING_STYLE:-path}"

# -----------------------------------------------------------------------------
# PRE-FLIGHT VALIDATION
# -----------------------------------------------------------------------------

log() { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1"; }
die() { log "FATAL: $1"; exit 1; }

is_placeholder() {
  case "$1" in
    CHANGE_ME*|"") return 0 ;;
    *) return 1 ;;
  esac
}

check_blocked_on_oq4() {
  local blocked=0
  for var in BACKUP_S3_ENDPOINT BACKUP_S3_BUCKET BACKUP_S3_ACCESS_KEY BACKUP_S3_SECRET_KEY BACKUP_AGE_RECIPIENT; do
    if is_placeholder "${!var}"; then
      log "BLOCKED ON OQ-4: $var is still a placeholder. See docs/operations/backup-and-restore-runbook.md."
      blocked=1
    fi
  done
  if [ "$blocked" -eq 1 ]; then
    die "one or more required destination/encryption variables are unset. Refusing to run — see the BLOCKED ON OQ-4 section of the runbook for exactly what a human must provide."
  fi
}

check_dependencies() {
  local missing=0
  for cmd in "$COMPOSE_CMD" age sha256sum aws date mktemp; do
    # COMPOSE_CMD may be "docker compose" (two words); check the first word.
    local bin="${cmd%% *}"
    if ! command -v "$bin" >/dev/null 2>&1; then
      log "MISSING DEPENDENCY: $bin"
      missing=1
    fi
  done
  [ "$missing" -eq 0 ] || die "required tooling is missing on this host. Install it before running this script."
}

# The `2>/dev/null` on the -lt test below silences a non-numeric value's
# syntax error but a bash `[ ... ]` syntax error also makes the test exit
# non-zero, which the `if` reads as false — so a garbage BACKUP_RETENTION_DAYS
# would silently skip this floor check entirely and fail later, more
# confusingly, inside the retention date-math. Validate the format first so
# that failure mode is impossible.
case "$BACKUP_RETENTION_DAYS" in
  ''|*[!0-9]*) die "BACKUP_RETENTION_DAYS=$BACKUP_RETENTION_DAYS is not a non-negative integer." ;;
esac
if [ "$BACKUP_RETENTION_DAYS" -lt 7 ]; then
  die "BACKUP_RETENTION_DAYS=$BACKUP_RETENTION_DAYS is below the 7-day floor required by database-backup-and-recovery.md §9."
fi

check_dependencies
check_blocked_on_oq4

[ -f "$COMPOSE_FILE" ] || die "COMPOSE_FILE ($COMPOSE_FILE) does not exist on this host."

mkdir -p "$BACKUP_LOCAL_STAGING_DIR"
chmod 0700 "$BACKUP_LOCAL_STAGING_DIR"

# -----------------------------------------------------------------------------
# BACKUP
# -----------------------------------------------------------------------------

TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BASENAME="makam_stg_${TIMESTAMP}.dump.age"
CHECKSUM_NAME="${BASENAME}.sha256"

TMP_DUMP="$(mktemp "${BACKUP_LOCAL_STAGING_DIR}/.tmp.${BASENAME}.XXXXXX")"
trap 'rm -f "$TMP_DUMP"' EXIT

log "Starting pg_dump of ${STG_DB_NAME} (custom format) via ${POSTGRES_SERVICE}, encrypting with age as it streams..."

# pg_dump runs INSIDE the postgres container, where the makam_stg_db_password
# secret is already mounted (docker-compose.dev-stg.yml). The password is
# read and used entirely inside the container/subshell; it is never exported
# into this script's environment, printed, or logged.
#
# Custom format (-Fc) is used: it is pg_dump's own compressed archive format,
# supports selective/parallel pg_restore, and (per pg_dump defaults) includes
# the CREATE EXTENSION statements for pg_trgm/unaccent, which the restore
# evidence template requires verifying.
#
# `set -o pipefail` (already active via `set -euo pipefail`) ensures a
# pg_dump failure inside the pipe aborts this script instead of producing a
# truncated-but-"successful" encrypted file.
(
  cd "$(dirname "$COMPOSE_FILE")"
  $COMPOSE_CMD -f "$COMPOSE_FILE" exec -T "$POSTGRES_SERVICE" sh -c \
    "PGPASSWORD=\"\$(cat '${STG_DB_PASSWORD_FILE_IN_CONTAINER}')\" pg_dump -h 127.0.0.1 -U '${STG_DB_USER}' -d '${STG_DB_NAME}' -Fc"
) | age --recipient "$BACKUP_AGE_RECIPIENT" --output "$TMP_DUMP" \
  || die "pg_dump | age pipeline failed — no partial backup will be uploaded."

[ -s "$TMP_DUMP" ] || die "encrypted backup file is empty — refusing to upload."

FINAL_DUMP="${BACKUP_LOCAL_STAGING_DIR}/${BASENAME}"
mv "$TMP_DUMP" "$FINAL_DUMP"
trap - EXIT

# Checksum recorded alongside the backup file, computed over the ENCRYPTED
# artifact so it can be verified before decryption.
( cd "$BACKUP_LOCAL_STAGING_DIR" && sha256sum "$BASENAME" > "$CHECKSUM_NAME" )
log "Backup encrypted and checksummed: ${BASENAME}"

# -----------------------------------------------------------------------------
# UPLOAD
# -----------------------------------------------------------------------------

export AWS_ACCESS_KEY_ID="$BACKUP_S3_ACCESS_KEY"
export AWS_SECRET_ACCESS_KEY="$BACKUP_S3_SECRET_KEY"
export AWS_DEFAULT_REGION="$BACKUP_S3_REGION"

aws configure set default.s3.addressing_style "$BACKUP_S3_ADDRESSING_STYLE" >/dev/null 2>&1 || true

S3_DEST="s3://${BACKUP_S3_BUCKET}/${BACKUP_S3_PREFIX}"

log "Uploading ${BASENAME} and its checksum to ${S3_DEST}/ ..."
aws --endpoint-url "$BACKUP_S3_ENDPOINT" s3 cp \
  "${BACKUP_LOCAL_STAGING_DIR}/${BASENAME}" "${S3_DEST}/${BASENAME}" \
  || die "upload of ${BASENAME} failed."
aws --endpoint-url "$BACKUP_S3_ENDPOINT" s3 cp \
  "${BACKUP_LOCAL_STAGING_DIR}/${CHECKSUM_NAME}" "${S3_DEST}/${CHECKSUM_NAME}" \
  || die "upload of ${CHECKSUM_NAME} failed (backup itself uploaded — remote is now missing its checksum sidecar; investigate before relying on this backup)."

# Verify the object is actually present remotely before treating this run as
# successful and deleting the local copy.
if ! aws --endpoint-url "$BACKUP_S3_ENDPOINT" s3 ls "${S3_DEST}/${BASENAME}" >/dev/null 2>&1; then
  die "post-upload verification failed: ${BASENAME} not found at ${S3_DEST}/ after upload."
fi

log "Upload verified: ${S3_DEST}/${BASENAME}"
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) SUCCESS backup=${BASENAME} sha256_sidecar=${CHECKSUM_NAME} remote=${S3_DEST}/${BASENAME}" >> "$BACKUP_LOG_FILE"

# Remote object storage is the backup of record (dev-staging-environment.md
# §12); do not accumulate encrypted dumps on the host's limited local disk
# once the remote copy is confirmed present.
rm -f "${BACKUP_LOCAL_STAGING_DIR}/${BASENAME}" "${BACKUP_LOCAL_STAGING_DIR}/${CHECKSUM_NAME}"

# -----------------------------------------------------------------------------
# RETENTION (>= 7 days, database-backup-and-recovery.md §9)
# -----------------------------------------------------------------------------

log "Applying retention: deleting backups older than ${BACKUP_RETENTION_DAYS} days (keeping at least ${MIN_BACKUPS_TO_KEEP})..."

CUTOFF_EPOCH="$(date -u -d "-${BACKUP_RETENTION_DAYS} days" +%s)"

# List backup objects (not their .sha256 sidecars) under the prefix, oldest
# first, and derive each object's age from the timestamp encoded in its own
# filename (makam_stg_YYYYmmddTHHMMSSZ.dump.age) rather than S3 LastModified,
# so retention is correct even if an object was re-uploaded/copied.
mapfile -t REMOTE_OBJECTS < <(
  aws --endpoint-url "$BACKUP_S3_ENDPOINT" s3 ls "${S3_DEST}/" 2>/dev/null \
    | awk '{print $4}' \
    | grep -E '^makam_stg_[0-9]{8}T[0-9]{6}Z\.dump\.age$' \
    | sort
)

TOTAL="${#REMOTE_OBJECTS[@]}"
DELETABLE=()
for name in "${REMOTE_OBJECTS[@]}"; do
  ts="$(printf '%s' "$name" | sed -E 's/^makam_stg_([0-9]{8}T[0-9]{6}Z)\.dump\.age$/\1/')"
  obj_epoch="$(date -u -d "${ts:0:8} ${ts:9:2}:${ts:11:2}:${ts:13:2}" +%s 2>/dev/null || echo "")"
  [ -n "$obj_epoch" ] || { log "WARNING: could not parse timestamp from $name — skipping retention check for it."; continue; }
  if [ "$obj_epoch" -lt "$CUTOFF_EPOCH" ]; then
    DELETABLE+=("$name")
  fi
done

KEEP_COUNT=$(( TOTAL - ${#DELETABLE[@]} ))
if [ "$KEEP_COUNT" -lt "$MIN_BACKUPS_TO_KEEP" ]; then
  EXCESS=$(( MIN_BACKUPS_TO_KEEP - KEEP_COUNT ))
  log "Retention would leave fewer than MIN_BACKUPS_TO_KEEP=${MIN_BACKUPS_TO_KEEP}; sparing the ${EXCESS} most recent otherwise-deletable backup(s)."
  # DELETABLE is oldest-first; drop the newest $EXCESS entries from deletion.
  DELETABLE=("${DELETABLE[@]:0:$(( ${#DELETABLE[@]} - EXCESS ))}")
fi

for name in "${DELETABLE[@]}"; do
  log "Deleting expired backup: ${name} (and its .sha256 sidecar)"
  aws --endpoint-url "$BACKUP_S3_ENDPOINT" s3 rm "${S3_DEST}/${name}" || log "WARNING: failed to delete ${name}"
  aws --endpoint-url "$BACKUP_S3_ENDPOINT" s3 rm "${S3_DEST}/${name}.sha256" || log "WARNING: failed to delete ${name}.sha256"
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) RETENTION_DELETE backup=${name}" >> "$BACKUP_LOG_FILE"
done

log "Done. ${#DELETABLE[@]} expired backup(s) removed, $(( TOTAL - ${#DELETABLE[@]} )) retained."
