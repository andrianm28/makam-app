# Requirements — Renewal and Grave Registry

**Authority:** K31–K32 and Stakeholder Workflow MVP.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec and in other documents still points at the same requirement.

1. ~~THE SYSTEM SHALL implement the public renewal flow as six visible steps: city, TPU/TPS, grave search, fee, payment, and confirmation/invoice.~~ Superseded 2 Sep 2026 — see the `## Superseded` section below.
2. THE SYSTEM SHALL include the five MVP launch areas in city selection.
3. THE SYSTEM SHALL support fuzzy search by deceased name, block, and death date.
4. THE SYSTEM SHALL return search results in under 500 ms at 100,000 records.
5. WHEN a search returns an empty result THE SYSTEM SHALL provide an honest manual-entry or customer-service path where allowed.
6. THE SYSTEM SHALL display tariff amount, source, and last-update time.
7. THE SYSTEM SHALL NOT calculate a late fine without a written operator basis.
8. THE SYSTEM SHALL support online payment mode or an explicit manual fallback.
9. WHEN a renewal is confirmed THE SYSTEM SHALL show renewal reference, status, invoice state, and the resulting due date when available.
10. THE SYSTEM SHALL allow an admin/operator to mark an external renewal/payment with evidence.
11. THE SYSTEM SHALL NOT allow a duplicate renewal for the same grave period.
12. THE SYSTEM SHALL include deceased name, location, block, death date, due date, and heir contact in each grave record.
13. WHEN an import is submitted THE SYSTEM SHALL asynchronously validate up to 10,000 rows and report row-level errors.
14. THE SYSTEM SHALL support open, limited, and closed search access modes.
15. THE SYSTEM SHALL send exactly one reminder per grave per window.
16. WHILE the data gate is closed THE SYSTEM SHALL disable the search/reminder feature with an explanation.

## Superseded (2 Sep 2026)

AC1's "six visible steps" is superseded by a deliberate, project-owner-authorized step-count
reduction to three real steps (search, fee & payment, confirmation) — see
`docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md` for the full record, including
the explicit authorization to depart from the RKS-sourced step count this AC originally encoded.

Per `AGENTS.md`'s source-precedence order, this spec outranks the code — this note is that
approval, mirroring the shape `platform-identity-and-access/requirements.md`'s own
`## Superseded (22 Aug 2026)` section uses for its MFA-removal precedent.
