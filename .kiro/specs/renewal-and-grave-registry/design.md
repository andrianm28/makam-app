# Design — Renewal and Grave Registry

## Search

PostgreSQL `pg_trgm` proposed for normalized deceased name. Composite filters use cemetery, block, and death date. Search query must apply access policy before returning fields.

## Data

```text
grave_records
  deceased_name
  deceased_name_normalized
  cemetery_id
  block
  death_date
  due_date
  heir_contact_encrypted/reference
  heir_reminder_consent_at   -- AC17, added 19 Sep 2026; NULL = no reminders
  access_mode
  source
  source_updated_at

grave_import_batches
grave_import_rows
grave_import_errors
renewals
renewal_quotes
renewal_external_markings
reminder_deliveries(grave_record_id, due_date, window, dispatched_at)
  -- AC15/AC18/AC19: unique (grave_record_id, due_date, window); window set per
  -- notification-matrix.md `Reminder due` policy; nearest future window only
```

`grave_import_rows` carries a consent flag per row (AC17); import validation rejects a missing flag as a row-level error, it does not default it to consent.

## Import

- Upload is private.
- Queue validates schema, duplicates, cemetery references, dates, and required fields.
- Each row has success/error result.
- Import is resumable/idempotent by batch and row key.

## Duplicate prevention

Unique business key:

```text
grave_record_id + target_due_period
```

External marking and online renewal share the same uniqueness domain.

## Privacy

Public results must only return fields allowed by configured access mode. Contact details must never appear in public search by default.
