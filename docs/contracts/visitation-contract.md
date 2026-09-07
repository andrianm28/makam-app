# Visitation Booking Contract

## Request

Cemetery, visit date, visitor count, contact (phone + optional email), optional facility requests, accessibility needs. Matches the shipped `visitation_bookings` table (`database/migrations/2026_08_16_110020_create_visitation_bookings_table.php`) and `App\Domain\Visitation\Actions\RequestVisitation`.

## Rules

- Only active when `visitation_mode=BOOKABLE`.
- Respect operating hours, capacity, blackout dates, and operator confirmation policy.
- Cancellation and no-show rules are set by the operator per booking (confirm/cancel/no-show transitions in `VisitationBookingsResource`); there is no separate configurable cancellation-policy document.

## Deferred, not currently broken (CONTRACT-06, 07 Sep 2026)

The `visitation_bookings` table stores no grave/plot reference, and there is
no navigation/wayfinding projection anywhere in `app/`. This means kiro
`visitation-booking` AC4 ("THE SYSTEM SHALL make grave/plot navigation follow
data-access policy") is **not implemented** — a request carries no plot
reference and a confirmed booking outputs no navigation data at all.

This is a deliberate scope cut, not a regression: `docs/domain/
traceability-matrix.md`'s VISIT-01…VISIT-04 rows never cited AC4 as covered.
The grave/plot reference column and a `NavigationProjection` (or equivalent)
should be built **only when `G-VISIT-01` opens**, per the audit finding —
do not add them speculatively ahead of that gate.
