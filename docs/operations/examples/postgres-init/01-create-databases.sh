#!/usr/bin/env bash
set -euo pipefail

DEV_PASSWORD="$(cat /run/secrets/makam_dev_db_password)"
STG_PASSWORD="$(cat /run/secrets/makam_stg_db_password)"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" \
  --set=dev_password="$DEV_PASSWORD" \
  --set=stg_password="$STG_PASSWORD" <<'EOSQL'
CREATE ROLE makam_dev_user LOGIN PASSWORD :'dev_password';
CREATE ROLE makam_stg_user LOGIN PASSWORD :'stg_password';
CREATE DATABASE makam_dev OWNER makam_dev_user;
CREATE DATABASE makam_stg OWNER makam_stg_user;
EOSQL

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname makam_dev <<'EOSQL'
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
EOSQL

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname makam_stg <<'EOSQL'
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
EOSQL
