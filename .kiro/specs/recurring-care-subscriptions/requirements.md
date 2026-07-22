# Requirements — Recurring Care Subscriptions

**Authority:** K33. Fulfillment behavior is detailed in `grave-care-fulfillment`.

## Acceptance criteria

1. Supports monthly, quarterly, six-month, and annual care plans.
2. One subscription cycle creates at most one invoice.
3. Payment link/auto-charge follows active provider gate.
4. Paid subscription/cycle status changes only from validated webhook.
5. Each paid/eligible cycle creates at most one care work order.
6. Billing, work scheduling, completion evidence, complaint, and make-good are separate states.
7. Cancellation, pause, failed payment, grace/dunning, and price change policies must be explicitly configured before use.
8. Raw payment instrument is never stored.
