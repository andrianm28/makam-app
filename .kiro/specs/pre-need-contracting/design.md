# Design — Pre-Need Contracting

## Aggregate

`pre_need_cases`, `pre_need_proposals`, `payment_schedules`, `installments`, `activation_claims`, plus **references to** agreement and certificate records.

## Table ownership (normative)

`agreements`, `agreement_versions`, and `agreement_acceptances` are **owned by `certificates-and-agreements`**. This spec references them by key and must not define or migrate them. Same for `certificates`. Resolves the duplicate-ownership conflict in `docs/planning/kiro-specs-analysis.md` §5.1a.

## States

`INTEREST`, `CONSULTING`, `PROPOSED`, `RESERVED`, `CONTRACT_PENDING`, `ACTIVE_PAYMENT`, `SETTLED`, `CERTIFIED`, `ACTIVATED`, `CANCELLED`, `DEFAULTED` — final approval required before implementation.

All paid states are unreachable while gate closed.
