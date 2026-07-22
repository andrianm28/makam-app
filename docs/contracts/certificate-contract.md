# Agreement and Certificate Contract

## Required fields

`document_number`, `type`, `subject`, `version`, `status`, `issuer`, `signer/acceptance evidence`, `effective_at`, `file_reference`, `supersedes_id`, and `audit_reference`.

## Commands

- create draft
- issue
- mark delivered
- replace
- revoke

Issue/revoke requires privileged permission and idempotency. Number uniqueness is scoped to issuing authority and type.
