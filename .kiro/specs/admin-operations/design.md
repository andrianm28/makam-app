# Design — Admin Operations

## Admin panel boundaries

Filament Resources provide forms/tables only. Domain mutations are delegated to Actions/Services that enforce policies, transactions, state guards, and audit.

## Sensitive actions

- Issue/revise quote.
- Open payment.
- Mark external renewal.
- Record manual vendor payout.
- Access documents.
- Change tariff source.
- Change feature gate.

Each action requires reason where appropriate and emits an audit event.

## Reporting

Reports use read models/materialized queries if needed. Financial totals must reconcile to shared journal references, not derive only from mutable order status.

AC12's two measures (added 19 Sep 2026) are read-only queries over `booking_drafts.created_at`, `order_status_events` (confirmation transition), and invoice issued/paid timestamps. No event table, no target column: targets are a stakeholder decision still open in `docs/product/prd-yiem-2026-09-18.md` §13.
