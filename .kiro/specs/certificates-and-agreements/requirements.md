# Requirements — Certificates and Agreements

**Status:** Proposed P1; supports private cemetery workflows.

## Acceptance criteria

1. Agreement/certificate is a domain record with stable number, type, version, status, subject, issuer, effective date, and file reference.
2. Acceptance/signature evidence binds actor to exact version.
3. Certificate eligibility is rule-based and separate from order payment status.
4. Issue/revoke/replace requires authorized issuer role and audit.
5. Reissue/replacement preserves earlier history.
6. Customer can view delivery/issuance status without exposing restricted source documents.
7. Number uniqueness is enforced per issuer/type.
8. Manual external certificate can be referenced without claiming platform issuance.
