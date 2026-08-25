# Requirements — Package and Service Bundles

**Authority:** K26; benchmark refinement B03.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec and in other documents still points at the same requirement.

1. THE SYSTEM SHALL define, for each package version, the included, optional, and excluded items, quantities, units, fulfillment owner, service area/window, and evidence.
2. THE SYSTEM SHALL NOT allow modification of a published package version.
3. WHEN a quote is generated THE SYSTEM SHALL reference the exact package, service, and price versions selected.
4. WHEN a customer selects an optional item THE SYSTEM SHALL add it as a separate accepted quote line.
5. WHEN a fulfillment substitution occurs THE SYSTEM SHALL apply the configured substitution rule and obtain customer approval where required.
6. WHEN a package is marked complete THE SYSTEM SHALL check fulfillment evidence for every required item.
7. THE SYSTEM SHALL support package items fulfilled by the cemetery, the platform, or a vendor.
8. THE SYSTEM SHALL present inclusions, exclusions, and additional charges clearly in the UI.
