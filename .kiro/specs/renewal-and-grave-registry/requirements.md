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
17. WHEN a renewal reaches its confirmation step THE SYSTEM SHALL capture the heir contact's explicit consent to receive due-date reminders and store it with the contact. WHEN an operator import is submitted THE SYSTEM SHALL require a per-row consent flag; a row without consent SHALL yield no reminders.
18. THE SYSTEM SHALL schedule AC15's reminders only for grave records with a known `due_date` and a consenting heir contact, using the window set recorded in `docs/contracts/notification-matrix.md` under `Reminder due`. THE SYSTEM SHALL NOT ask an heir to self-declare a due date for reminder purposes.
19. WHEN a grave record's `due_date` becomes known after one or more reminder windows have already passed THE SYSTEM SHALL dispatch only the nearest window still in the future and SHALL NOT back-fill missed windows.
20. WHEN a renewal is confirmed THE SYSTEM SHALL issue a confirmation and a receipt for every renewal. THE SYSTEM SHALL issue a renewal letter as a versioned Certificate only WHILE the cemetery's `certificate_mode` is not `NONE`.

## Superseded (2 Sep 2026)

AC1's "six visible steps" is superseded by a deliberate, project-owner-authorized step-count
reduction to three real steps (search, fee & payment, confirmation) — see
`docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md` for the full record, including
the explicit authorization to depart from the RKS-sourced step count this AC originally encoded.

Per `AGENTS.md`'s source-precedence order, this spec outranks the code — this note is that
approval, mirroring the shape `platform-identity-and-access/requirements.md`'s own
`## Superseded (22 Aug 2026)` section uses for its MFA-removal precedent.

## Amended (19 Sep 2026)

Acceptance criteria above the original count were added from the YIEM PRD
reconciliation (`docs/product/prd-yiem-2026-09-18.md` §16, decisions Q7, Q16, Q17, Q18, Q38, Q39). The PRD is a
stakeholder document subordinate to `docs/product/mvp-scope.md`; these
criteria are the repo-side approval of the decisions it records, in the
same shape `renewal-and-grave-registry/requirements.md`'s `## Superseded`
section uses. Existing numbering is untouched.
