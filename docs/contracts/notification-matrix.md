# Notification Matrix — MVP

## Channel legend

- `IN_APP`: platform/admin/vendor panel notification.
- `EMAIL`: baseline customer/admin channel.
- `WA`: WhatsApp only when BSP/template gate active.
- `MANUAL`: phone/manual contact task when required.

## Matrix

| Event | Customer | Admin platform | Pengelola TPU/TPS | Vendor |
|---|---|---|---|---|
| Booking draft created | none | none | none | none |
| Booking submitted | EMAIL/WA | IN_APP | IN_APP/EMAIL for selected location | none |
| Availability requested | optional status | IN_APP | IN_APP/EMAIL/WA | none |
| Availability confirmed/rejected | EMAIL/WA | IN_APP | IN_APP | none |
| Quote issued | EMAIL/WA | IN_APP | optional | none |
| Quote accepted | confirmation | IN_APP | optional | none |
| Payment opened | EMAIL/WA | IN_APP | none | none |
| Payment received | EMAIL/WA + invoice | IN_APP | IN_APP for related order | Vendor when allocated |
| Payment failed/exception | EMAIL/WA with recovery | IN_APP exception | none | none |
| Order processing | EMAIL/WA based on material change | IN_APP | IN_APP | Assigned vendor |
| Order completed | EMAIL/WA | IN_APP | IN_APP | IN_APP |
| Marketplace order submitted | EMAIL/WA | IN_APP | none | IN_APP/EMAIL |
| Vendor accepted/rejected | EMAIL/WA | IN_APP | none | IN_APP |
| Vendor evidence uploaded | EMAIL/WA | optional | none | IN_APP |
| Renewal submitted | EMAIL/WA | IN_APP | IN_APP/EMAIL | none |
| Renewal paid/verified | EMAIL/WA + invoice | IN_APP | IN_APP | none |
| Reminder due | EMAIL/WA | optional | optional | none |

## Delivery rules

1. Notification outbox is durable and idempotent.
2. Channel failure never changes business state.
3. WhatsApp failure falls back to email/in-app when configured.
4. UI must not claim a message was delivered unless provider or internal delivery state confirms it.
5. Restricted documents are never attached to email/WhatsApp; use authenticated or expiring links.
6. Notification includes reference, current status, next action, and support contact.
7. Admin/operator/vendor recipients are scoped to the relevant record and entity.
