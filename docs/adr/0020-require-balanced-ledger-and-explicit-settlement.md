# ADR-0020: Require Balanced Ledger and Explicit Settlement Model

## Status
Accepted with business gates — 23 July 2026

## Decision
K3 must provide immutable balanced postings or equivalent invariant-preserving journal. Refund, payable, payout, and reconciliation are explicit objects. Online payment/settlement remains gated until merchant, tax, fee, refund, payable, and reconciliation decisions are approved.

## Consequences
Financial correctness is testable; development cannot guess unresolved legal/accounting decisions.
