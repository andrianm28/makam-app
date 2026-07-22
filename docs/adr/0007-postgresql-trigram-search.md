# ADR-0007: PostgreSQL Trigram Search for Grave Names

- **Status:** Proposed

## Decision

Use normalized names plus PostgreSQL `pg_trgm` indexes for fuzzy deceased-name search, combined with structured cemetery/block/date filters.

## Drivers

- RKS target below 500 ms at 100.000 records.
- Avoid introducing a separate search cluster prematurely.

## Consequences

Benchmark with production-like Indonesian names and typo patterns is mandatory. Revisit when scale or ranking needs exceed relational search capability.
