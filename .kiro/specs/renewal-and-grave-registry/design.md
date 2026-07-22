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
  access_mode
  source
  source_updated_at

grave_import_batches
grave_import_rows
grave_import_errors
renewals
renewal_quotes
renewal_external_markings
reminder_deliveries
```

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
