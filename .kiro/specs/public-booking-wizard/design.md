# Design — Public Booking Wizard

## State

`BookingDraft` stores `current_step`, `completed_steps`, `version`, data payload references, quote reference, and workflow branch.

## Routes

- `/pemesanan-makam`
- `/pemesanan-makam/baru`
- `/pemesanan-makam/draft/{draftId}`
- `/pemesanan-makam/konfirmasi/{orderReference}`

## Components

One shell with step-specific components. Server is authoritative for completion. Autosave uses optimistic version and idempotency key.

## Branching

- Standard/Makam Tumpang: nine-step commercial path.
- Urgent: Step 3 triggers operational gate and FuneralCase; minimum data can be prioritized, but missing later data remains visible as required follow-up.
- Pre-Need: interest/consultation path when payment gate closed; Step 8 explicitly states no payment is accepted.

## Failure behavior

Provider/payment/upload errors preserve draft and show retry/support options.
