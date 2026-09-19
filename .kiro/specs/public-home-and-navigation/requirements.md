# Requirements — Public Home and Navigation

**Authority:** Stakeholder Workflow MVP — Home Page.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec and in other documents still points at the same requirement.

1. THE SYSTEM SHALL display four primary menus, in this exact order: Pemesanan Makam, Layanan Pemakaman, Perpanjangan Makam, FAQ.
2. THE SYSTEM SHALL make all four menus publicly reachable without login.
3. THE SYSTEM SHALL present Pemesanan Makam as the primary call-to-action.
4. THE SYSTEM SHALL use the same labels in desktop and mobile navigation.
5. THE SYSTEM SHALL include a customer-service call-to-action and a truthful Urgent-availability indicator on the homepage.
6. WHILE a service is feature-gated THE SYSTEM SHALL display an explanatory state instead of a dead link or a generic 404.
7. THE SYSTEM SHALL make public routes follow `docs/product/information-architecture.md`.
8. THE SYSTEM SHALL make navigation keyboard accessible and responsive.
9. WHEN a user views or clicks a menu THE SYSTEM SHALL record an analytics impression or click event without sensitive data.
10. THE SYSTEM SHALL present in the hero exactly one primary call-to-action (AC3, labelled Cari Makam) and the secondary calls-to-action Perpanjang Makam, Layanan Pemakaman, and Wakaf Tanah. AC1's four primary menus are unchanged; Wakaf Tanah SHALL NOT be a service card.
11. THE SYSTEM SHALL render AC5's customer-service call-to-action as a persistent element directly below the hero, not as a competing hero call-to-action.
12. THE SYSTEM SHALL provide a static Wakaf Tanah information page: purpose of the endowment, general requirements, the six-step process presented as information, and the help-centre contact channel. THE SYSTEM SHALL NOT present a form, accept an upload, or register interest through this page.
13. THE SYSTEM SHALL provide a static Tentang Kami page carrying the operating foundation's legal identity (legal entity name, address, legal basis). WHILE that identity has not been supplied THE SYSTEM SHALL NOT publish the page nor link to it, and SHALL NOT substitute generic text.
14. THE SYSTEM SHALL serve the supporting navigation from existing routes: Daftar Lokasi Makam from the cemetery directory, Hubungi Kami from the help centre, FAQ, Masuk, and Akun Saya from their existing pages. THE SYSTEM SHALL NOT create new pages for those entries.

## Negative criteria

- No fifth service card, and no swap of FAQ for Wakaf Tanah.
- No land document or interest form behind the Wakaf Tanah page.
- No Tentang Kami page without the foundation's legal identity.

## Amended (19 Sep 2026)

Acceptance criteria above the original count were added from the YIEM PRD
reconciliation (`docs/product/prd-yiem-2026-09-18.md` §16, decisions Q2, Q12, Q13, Q19, Q25, Q26, Q36, Q37). The PRD is a
stakeholder document subordinate to `docs/product/mvp-scope.md`; these
criteria are the repo-side approval of the decisions it records, in the
same shape `renewal-and-grave-registry/requirements.md`'s `## Superseded`
section uses. Existing numbering is untouched.
AC12 and AC13 are static pages on the `/privasi` and `/syarat-ketentuan` pattern (`App\Livewire\Public\Legal`). Their routes are added to `docs/product/information-architecture.md` when registered, not before: that file is regenerated from the real route table (its 07 Sep 2026 note), so listing an unregistered route would reintroduce the drift it corrected.
