# Design — Public Home and Navigation

## Components

- `HomePage`
- `PrimaryServiceCards`
- `PublicHeader`
- `MobileNavigation`
- `UrgentServiceBanner`
- `CustomerServiceCTA`
- `PublicFooter`
- `WakafTanahPage` (static, AC12 — Privasi pattern)
- `TentangKamiPage` (static, AC13 — Privasi pattern; unpublished and unlinked until the legal identity exists)

## Data

Menu labels and routes are configuration-backed but protected as required MVP entries. Feature gate affects destination state, not menu existence.

The hero carries one primary and three secondary calls-to-action (AC10); the secondary set is fixed content, not a menu entry, so it neither adds a service card nor changes AC1's order. The customer-service element sits below the hero (AC11).

## Accessibility

Semantic navigation, visible focus, skip link, proper headings, alt text, touch targets, and no color-only status.
