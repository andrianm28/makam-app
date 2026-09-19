# Canonical Cemetery Facility Catalog — MVP

**Status:** approved 19 Sep 2026 through the PRD reconciliation grill
(`docs/product/prd-yiem-2026-09-18.md` §16, decisions Q14, Q24, Q30). Not yet
implemented: `cemeteries.facilities` is still a free-text JSON array (see the
column comment in `database/migrations/2026_07_26_190000_create_cemeteries_table.php`).
This file is the closed list that column and the MK-01 facility filter must
converge on.

## Why a closed list

The PRD (MK-01) makes the facility filter a Must. A filter over free-text
labels silently hides a cemetery whenever an admin spells a facility
differently, which is the opposite of what a bereaved family needs. Every
other public-facing catalog in this repo (`service-catalog.md`,
`marketplace-catalog.md`, `faq-catalog.md`) is a closed list for the same
reason.

## Facilities

| Code | Label | Filterable |
|---|---|---|
| PARKING | Parkir | yes |
| HEARSE_ACCESS | Akses Mobil Jenazah | yes |
| PRAYER_ROOM | Musala | yes |
| TOILET | Toilet | yes |
| TENT_AREA | Area Tenda | yes |
| WATER_SUPPLY | Sumber Air | yes |
| LIGHTING | Penerangan | yes |
| SECURITY | Penjaga / Keamanan | yes |
| WHEELCHAIR_ACCESS | Akses Kursi Roda | yes |
| WAITING_AREA | Area Tunggu | yes |

## Catalog rules

- Admin selects facilities per cemetery from this list; no free-text entry.
- Existing free-text labels are mapped to a code during migration; a label
  that maps to nothing is kept as display text only and does not filter.
- The public filter offers only codes marked filterable and applies them
  together with city, type, and the other MK-01 filters.
- A facility absent from the list is not "no", it is "unknown"; the detail
  page must not render an unknown facility as unavailable.
- Adding a code is a product change approval, not a deployment-time edit,
  the same rule `AGENTS.md` §Mandatory MVP UX applies to the other catalogs.
