# Visitation Booking Contract

## Request

Cemetery, grave/plot reference where authorized, visit date/time, visitor count, contact, optional facilities, accessibility needs.

## Rules

- Only active when `visitation_mode=BOOKABLE`.
- Respect operating hours, capacity, blackout dates, and operator confirmation policy.
- Navigation output follows grave-data projection policy.
- Cancellation and no-show rules are configurable.
