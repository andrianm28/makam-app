# Benchmark Validation — Indonesia, July 2026

## Status

**Research input, not contractual requirement.** Reviewed 23 July 2026.

## Sources

- Al Azhar Memorial Garden — https://alazharmemorialgarden.com/
- Al Azhar ordering flow — https://pemakaman.co.id/cara-pemesanan-makam/
- Kamboja article on San Diego Hills At-Need/Pre-Need — https://kamboja.co.id/tips/kuburan-mewah-san-diego-hills/
- Makamia — https://makamia.id/

## Observed public capabilities

### Al Azhar Memorial Garden / Pemakaman.co.id

Public pages describe 24-hour funeral service, multiple plot types, plot numbers, routine maintenance, visitation booking, certificates of land-use utilization, At-Need and Pre-Need ordering, installment/payment choices, customer account links, quote/invoice/receipt, and certificate issuance after settlement.

### Kamboja / San Diego Hills article

The page distinguishes At-Need from Pre-Need and describes cash/settled-at-use expectations for At-Need versus installment possibility for Pre-Need, with marketing-assisted order forms, payment, and later certificate delivery.

### Makamia

The public site presents booking, grave search, visual map, QR grave, memorial digital, land marketplace, service vendors, and cemetery registration/management.

## Validated product conclusions

1. Indonesian demand supports At-Need and Pre-Need as distinct journeys.
2. Private cemetery operators may have plot-level identifiers, maps, and certificates.
3. Funeral fulfillment requires human coordination, not only order status.
4. Post-burial experiences such as visitation, navigation, care, memorial, and QR are plausible roadmap modules.
5. A platform serving multiple operators needs heterogeneous capability configuration.
6. Marketplace land and paid Pre-Need introduce legal and financial risks beyond ordinary e-commerce.

## Design response

- Keep package/class confirmation as default.
- Add optional authoritative plot inventory and reservation.
- Add FuneralCase and case manager workflow.
- Add versioned agreement/certificate model.
- Separate product types and accounting lifecycles.
- Add optional visitation/memorial/QR modules behind policy gates.
- Begin marketplace with single-vendor fulfillment before multi-vendor complexity.

## Evidence limitations

Public websites do not establish actual transaction volume, operational quality, internal architecture, legal compliance, or whether every displayed feature is fully operational. The package therefore uses these sources only to validate market patterns and identify design options.
