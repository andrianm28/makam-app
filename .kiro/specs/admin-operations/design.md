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
