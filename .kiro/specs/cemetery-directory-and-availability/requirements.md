# Requirements — Cemetery Directory and Availability

**Authority:** K23–K24 and Stakeholder Workflow MVP Step 1–2.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec (`AC4`, `AC6`, `AC8` in `tasks.md`) and in other documents still points at the same requirement.

1. THE SYSTEM SHALL include Jakarta, Bogor, Depok, Tangerang, and Bekasi in the initial launch city/regency list.
2. WHEN a user applies a city/regency filter THE SYSTEM SHALL filter published TPU/TPS by that city/regency.
3. WHEN a user views a cemetery card or detail THE SYSTEM SHALL display type, name, photo, address, Google Maps/navigation URL, facilities, attributed price range, and availability.
4. THE SYSTEM SHALL maintain an explicit versioned capability profile for every cemetery. WHEN a capability profile is missing THE SYSTEM SHALL use safe defaults.
5. THE SYSTEM SHALL present default availability as indicative/package-class and visibly labeled `Perlu konfirmasi`.
6. THE SYSTEM SHALL present Makam Tumpang availability explicitly at the location/package/class level.
7. THE SYSTEM SHALL NOT enable `SPECIFIC_PLOT` unless an authoritative registry, freshness evidence, and a reservation contract are present.
8. WHILE the plot data source is missing or stale THE SYSTEM SHALL disable plot reservation and fall back to a request-confirmation path where allowed.
9. THE SYSTEM SHALL scope operator updates to the operator's assigned cemetery and SHALL audit them.
10. THE SYSTEM SHALL allow an admin to manage cities, cemetery content, facilities, prices, and capabilities without deployment.
11. THE SYSTEM SHALL provide an external navigation link from coordinates/address via Google Maps. WHEN the map provider fails THE SYSTEM SHALL NOT block viewing of the textual address.
12. THE SYSTEM SHALL render only active capabilities in the public UI.

## Negative criteria

- No guaranteed availability from indicative data.
- No hidden omission of a required MVP city.
- No direct purchase from stale/non-authoritative inventory.
- No public exposure of restricted plot data.
