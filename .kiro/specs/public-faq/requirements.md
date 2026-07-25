# Requirements — Public FAQ

**Authority:** Stakeholder Workflow MVP — FAQ.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec (`AC4`, `AC6`, `AC8` in `tasks.md`) and in other documents still points at the same requirement.

1. THE SYSTEM SHALL make the public FAQ reachable from the homepage and from global navigation.
2. THE SYSTEM SHALL present the six FAQ categories defined in `faq-catalog.md`.
3. WHEN a user selects a category filter or submits a search query THE SYSTEM SHALL filter or search FAQ articles by title, summary, and body accordingly.
4. WHEN a user views an FAQ article detail THE SYSTEM SHALL display its updated date, related articles, and a customer-service call-to-action.
5. THE SYSTEM SHALL allow an authorized admin to create, preview, publish, unpublish, reorder, and version FAQ articles.
6. THE SYSTEM SHALL NOT display an unpublished article in any public view or public search result.
7. WHILE a payment-related or Urgent-related feature gate is active THE SYSTEM SHALL reflect only approved operational information in FAQ content — never an unsupported claim about availability, price, or SLA.
8. WHEN a search returns no results THE SYSTEM SHALL show related categories and a customer-service path instead of a bare empty state.
9. THE SYSTEM SHALL render the FAQ responsively, accessibly (design-system.md §7), and indexable where appropriate.
