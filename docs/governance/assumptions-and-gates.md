# Assumptions, Dependencies, Gates, and Open Questions

## 1. External foundations

K1 identity, K2 roles/scopes, K3 journal, K4 payment, K5 reconciliation, K6 files, K7 notifications, and K8 append-only audit remain external contracts.

## 2. Gate registry

| Gate ID | Capability | Type | Activation evidence | Behavior before active |
|---|---|---|---|---|
| G-LEGAL-01 | Paid Pre-Need plot purchase | Legal/financial | Legal position, reserve/liability, contract, cancellation, accounting | Interest/consultation only |
| G-PROTECTION-01 | Funeral protection membership | Structural/regulatory | Separate legal/product design | Not available |
| G-LAND-01 | Land/plot marketplace or rights transfer | Legal | Title/right verification, operator approval, escrow/dispute/tax | Not available |
| G-OPS-01 | Urgent/At-Need acceptance | Operational | Hours, capacity, owner, SLA, escalation, drill | Disabled by area/time |
| G-CAP-01 | Cemetery capability profile | Governance | Operator/data owner approval and evidence | Safe defaults |
| G-PLOT-01 | Specific plot inventory/reservation | Data/integration | Authoritative source, freshness, lock, reconciliation, fallback | Package/class confirmation |
| G-DIRECT-01 | Direct plot purchase | Structural | G-PLOT-01 + legal/price/payment/certificate model | Request/hold only |
| G-PAY-01 | Online payment | Activation | Shared money path and merchant active | Manual coordination |
| G-PAYOUT-01 | Automated vendor payout | Activation | Provider + reconciliation + security | Manual transfer/proof |
| G-TOKEN-01 | Card-on-file | Activation | Provider tokenization and security review | Payment links |
| G-WA-01 | WhatsApp | Activation | BSP/templates | Email/in-app fallback |
| G-DATA-01 | Grave search/reminder | Data/privacy | Dataset quality, owner, access policy | Disabled with explanation |
| G-MEM-01 | Public memorial/QR | Privacy/moderation | Consent model, field projection, moderation, abuse process | Private/off |
| G-VISIT-01 | Bookable visitation | Operational | Capacity, hours, facilities, confirmation | Information-only/off |
| G-CERT-01 | Platform-issued certificates | Legal/operational | Template authority, numbering, signer, issuance/revocation SOP | Manual reference only |
| G-RATE-01 | Renewal tariff/fine | Structural | Attributed effective source and written rule | No invented fine |
| G-EXT-01 | Outside-system renewal marking | Structural/integration | SOP or integration | Manual marking |

## 3. Feature flags

```text
feature.urgent_booking
feature.preneed_interest
feature.preneed_payment
feature.funeral_protection
feature.land_marketplace
feature.online_payment
feature.vendor_auto_payout
feature.subscription_tokenization
feature.whatsapp
feature.grave_search
feature.grave_reminders
feature.plot_inventory
feature.plot_reservation
feature.direct_plot_purchase
feature.visitation_booking
feature.memorial_public
feature.memorial_qr
feature.platform_certificate
```

Flags require server-side checks, owner, evidence, date, and rollback.

## 4. Design assumptions

- Laravel modular monolith is proposed, not mandated by RKS.
- Cemetery capability is configured explicitly per cemetery.
- Package/class request confirmation is the safest default.
- Authoritative plot inventory is optional and must support atomic reservation.
- At-Need is handled by FuneralCase; Pre-Need by separate domain.
- Customer order, vendor work order, payment, and certificate have independent lifecycles.
- Initial marketplace rollout is single-vendor per checkout/order before multi-vendor.

## 5. Open decisions

1. Which cemeteries are expected to support plot-level inventory and who owns the source?
2. What is the reservation TTL and behavior if payment is in progress at expiry?
3. Who can issue/sign/revoke certificates and what numbering authority applies?
4. What minimum data may be public in grave search, map, memorial, and QR?
5. What constitutes consent and who may manage a deceased person's memorial?
6. What is the Urgent first-response, confirmation, and fulfillment SLA per area?
7. Is Pre-Need a plot-use right, service contract, or another legal structure?
8. Merchant of record, refund, chargeback, fees, tax, and vendor settlement?
9. Does any proposed land marketplace involve transfer of ownership/right, and is it permitted?
10. What service bundle substitutions and cancellation policies apply?
11. What document retention applies to identity, agreements, certificates, work evidence, and memorial content?
12. What RTO/RPO/SLO and capacity targets apply?

## 6. Change control

Promoting a benchmark extension into committed scope requires stakeholder approval, updated requirement/spec, ADR where irreversible, security/privacy review, test/release gate, rollout/rollback plan, and budget/schedule impact.
