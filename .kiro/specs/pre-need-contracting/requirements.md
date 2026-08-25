# Requirements — Pre-Need Contracting

**Status:** Interest flow allowed; paid flow gated by G-LEGAL-01.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec (`AC2`, `AC4`, `AC5` in `tasks.md`) and in other documents still points at the same requirement.

1. WHILE `G-LEGAL-01` is closed THE SYSTEM SHALL allow users to register interest, request consultation, and receive non-binding information only.
2. THE SYSTEM SHALL require approved product/legal/accounting configuration before paid activation.
3. THE SYSTEM SHALL support a flow covering package/plot proposal, optional reservation, quote, agreement version, payment schedule, settlement, certificate eligibility, and future activation/claim.
4. WHEN an agreement is displayed THE SYSTEM SHALL show price guarantee/substitution, cancellation/refund, transferability, term, included services, and responsible entity.
5. WHEN a customer accepts an agreement THE SYSTEM SHALL bind the acceptance to the exact agreement and quote versions.
6. THE SYSTEM SHALL make payment schedule and delinquency behavior explicit and idempotent.
7. THE SYSTEM SHALL issue a certificate only when eligibility rules are satisfied.
8. WHEN future activation/claim occurs THE SYSTEM SHALL link it to a new At-Need FuneralCase without losing original contract history.

## Negative criteria

- No payment session, binding reservation, or certificate while gate closed.
- No reuse of care subscription lifecycle.
