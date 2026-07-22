# Design — Visitation Booking

Entities: `visit_schedules`, `visit_capacity_slots`, `visit_bookings`, `visit_facility_requests`, `visit_events`.

Use unique customer/cemetery/time/idempotency guard. Notification is asynchronous with fallback. Navigation is a projection service, not raw registry exposure.
