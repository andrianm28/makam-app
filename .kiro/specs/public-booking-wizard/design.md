# Design — Public Booking Wizard

## Boundary with `booking-and-order-orchestration` (normative)

Both specs describe the nine steps and share eight acceptance criteria. To stop the same behaviour being built twice — or each assuming the other did it:

| This spec (presentation) owns | `booking-and-order-orchestration` (domain) owns |
|---|---|
| Step rendering, stepper, labels, progress | Product-type routing |
| Draft UI, autosave affordance, resume UX | Draft persistence, versioning, idempotency |
| Field components, client-side hints | Server-side step validation (authoritative) |
| Rendering quote lines and totals | Quote issue, versioning, immutability |
| Rendering payment mode and its states | Payment guard, gate evaluation, webhook effects |
| Upload UI and progress | Document adapter, quarantine, signed URLs |
| Step 9 layout and delivery indicators | Order state machine, notification dispatch |

Where an acceptance criterion appears in both, this spec covers **what the user sees**; the other covers **what the server enforces**. Resolves `docs/planning/kiro-specs-analysis.md` §5.4.

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
