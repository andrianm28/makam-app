# Design — Certificates and Agreements

Entities: `agreements`, `agreement_versions`, `agreement_acceptances`, `certificates`, `certificate_events`, `document_deliveries`.

## Table ownership (normative)

**This spec OWNS `agreements`, `agreement_versions`, and `agreement_acceptances`** — schema, migrations, and lifecycle. `pre-need-contracting` consumes them and must not define or migrate them. Resolves the duplicate-ownership conflict recorded in `docs/planning/kiro-specs-analysis.md` §5.1a.

State machines follow domain docs. File generation/signature provider is an adapter. Idempotency keys protect issue/delivery commands.
