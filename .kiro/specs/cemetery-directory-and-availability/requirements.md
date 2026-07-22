# Requirements — Cemetery Directory and Availability

**Authority:** K23–K24 and Stakeholder Workflow MVP Step 1–2.

## Acceptance criteria

1. Initial launch city/regency list contains Jakarta, Bogor, Depok, Tangerang, and Bekasi.
2. Platform filters published TPU/TPS by city/regency.
3. Cemetery card/detail displays type, name, photo, address, Google Maps/navigation URL, facilities, attributed price range, and availability.
4. Every cemetery has an explicit versioned capability profile; missing profile uses safe defaults.
5. Default availability is indicative/package-class and visibly says `Perlu konfirmasi`.
6. Makam Tumpang availability is explicit at location/package/class level.
7. `SPECIFIC_PLOT` can be enabled only with authoritative registry, freshness evidence, and reservation contract.
8. Missing/stale source disables plot reservation and falls back to request confirmation where allowed.
9. Operator updates are scoped to assigned cemetery and audited.
10. Admin can manage cities, cemetery content, facilities, prices, and capabilities without deployment.
11. Google Maps behavior provides an external navigation link from coordinates/address; map-provider failure does not block viewing textual address.
12. Public UI renders only active capabilities.

## Negative criteria

- No guaranteed availability from indicative data.
- No hidden omission of a required MVP city.
- No direct purchase from stale/non-authoritative inventory.
- No public exposure of restricted plot data.
