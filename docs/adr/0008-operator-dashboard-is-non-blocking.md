# ADR-0008: Operator Dashboard Is Non-Blocking

- **Status:** Accepted from RKS

## Decision

The cemetery operator dashboard is an additional input channel. Admin can complete availability verification manually if the operator does not respond. Quote issuance and payment opening remain admin actions.

## Consequences

- Product is resilient to low operator adoption.
- Admin fallback and phone-verification evidence are mandatory.
- Operator response/adoption must be measured.
