# Design — Pre-Need Contracting

## Aggregate

`pre_need_cases`, `pre_need_proposals`, `agreements`, `agreement_versions`, `payment_schedules`, `installments`, `activation_claims`, and certificate references.

## States

`INTEREST`, `CONSULTING`, `PROPOSED`, `RESERVED`, `CONTRACT_PENDING`, `ACTIVE_PAYMENT`, `SETTLED`, `CERTIFIED`, `ACTIVATED`, `CANCELLED`, `DEFAULTED` — final approval required before implementation.

All paid states are unreachable while gate closed.
