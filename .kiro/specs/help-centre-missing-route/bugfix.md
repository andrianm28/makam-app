# Bugfix — `/bantuan` had no route

**Found:** 8 Aug 2026, during a full-repository analysis (not a bug report — no user ever reached this, since the site is pre-launch on `dev.makam.co.id`).
**Fixed in:** commit `97dfbbf` (Batch 0, Sprint 4).
**Kind:** standalone bugfix spec, per [`kiro-bugfix-spec`](../../../.claude/skills/kiro-bugfix-spec/SKILL.md) — not nested under a feature spec, because no feature spec owns the destination screen (PUB-060). That ownership question is separate and still open; see `docs/planning/sprint-plan.md`'s finding ledger and `docs/domain/traceability-matrix.md` §E.

## Reproduction

`<x-mk.header>`'s `bantuanHref` prop defaults to `/bantuan` and renders a persistent "Bantuan" action on **every page**, both the mobile and desktop bars. Seven further views link the same path: `layouts/app.blade.php`'s footer, both FAQ views, both legal views, the coming-soon stub, and the homepage. `routes/web.php` had no route by that name.

## Current behaviour (defect)

WHEN a user follows the persistent "Bantuan" link rendered on any page THEN the system returns HTTP 404, because no route matching `/bantuan` was registered.

## Expected behaviour (correct)

WHEN a user follows the "Bantuan" link on any page THE SYSTEM SHALL return HTTP 200 and render the customer-service escape hatch (screen PUB-060): the channels and operating hours from `App\Support\ContactInfo`, the emergency disclaimer required by `design-system.md` §6.10, and links onward to `/faq` and `/`.

WHEN the page renders THE SYSTEM SHALL NOT claim a phone number, email, response time, SLA, or 24-hour availability that no document in this repository defines. `App\Support\ContactInfo`'s constants are placeholders for the public dev host, so the page SHALL state that plainly.

## Unchanged behaviour (regression prevention)

- WHEN a user visits `/privasi` or `/syarat-ketentuan` THE SYSTEM SHALL CONTINUE TO render the real legal pages introduced in commit `1e196bf`, and both SHALL CONTINUE TO link `/bantuan` from their existing `href="/bantuan"` markup.
- WHEN a user visits `/pemesanan-makam`, `/marketplace`, or `/perpanjangan` THE SYSTEM SHALL CONTINUE TO return HTTP 200 (the "Segera Hadir" stub), never a 404.
- THE SYSTEM SHALL CONTINUE TO read contact and company data exclusively from `App\Support\ContactInfo` and `App\Support\CompanyInfo` — no call site restates a phone number, email, hours string, or legal-entity name.
- `G-OPS-01` (Urgent/At-Need acceptance) SHALL CONTINUE TO be seeded closed, and this screen SHALL NOT extend an availability or SLA claim to Urgent handling on its own authority.

## Constraint on the fix

Additive only. `routes/web.php`, `resources/views/components/mk/header.blade.php`, and every other file that already links `/bantuan` correctly were left untouched — the destination was missing, not the links.
