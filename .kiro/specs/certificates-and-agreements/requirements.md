# Requirements — Certificates and Agreements

**Status:** Proposed P1; supports private cemetery workflows.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec and in other documents still points at the same requirement.

1. THE SYSTEM SHALL represent each agreement/certificate as a domain record with a stable number, type, version, status, subject, issuer, effective date, and file reference.
2. WHEN acceptance or signature evidence is captured THE SYSTEM SHALL bind it to the actor and the exact document version.
3. THE SYSTEM SHALL determine certificate eligibility by rule, kept separate from order payment status.
4. WHEN a certificate/agreement is issued, revoked, or replaced THE SYSTEM SHALL require an authorized issuer role and SHALL record an audit entry.
5. WHEN a certificate/agreement is reissued or replaced THE SYSTEM SHALL preserve its earlier history.
6. WHEN a customer views delivery/issuance status THE SYSTEM SHALL display it without exposing restricted source documents.
7. THE SYSTEM SHALL enforce document-number uniqueness per issuer and type.
8. WHEN a manual external certificate is referenced THE SYSTEM SHALL record the reference without claiming platform issuance.
