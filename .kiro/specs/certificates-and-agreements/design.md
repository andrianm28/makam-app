# Design — Certificates and Agreements

Entities: `agreements`, `agreement_versions`, `agreement_acceptances`, `certificates`, `certificate_events`, `document_deliveries`.

State machines follow domain docs. File generation/signature provider is an adapter. Idempotency keys protect issue/delivery commands.
