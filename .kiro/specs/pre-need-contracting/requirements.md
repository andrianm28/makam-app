# Requirements — Pre-Need Contracting

**Status:** Interest flow allowed; paid flow gated by G-LEGAL-01.

## Acceptance criteria

1. While gate closed, users can register interest, request consultation, and receive non-binding information only.
2. Paid activation requires approved product/legal/accounting configuration.
3. Flow supports package/plot proposal, optional reservation, quote, agreement version, payment schedule, settlement, certificate eligibility, and future activation/claim.
4. Agreement displays price guarantee/substitution, cancellation/refund, transferability, term, included services, and responsible entity.
5. Customer acceptance is bound to exact agreement and quote versions.
6. Payment schedule and delinquency behavior are explicit and idempotent.
7. Certificate issuance occurs only when eligibility rules are satisfied.
8. Future activation/claim links to a new At-Need FuneralCase without losing original contract history.

## Negative criteria

- No payment session, binding reservation, or certificate while gate closed.
- No reuse of care subscription lifecycle.
