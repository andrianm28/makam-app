# Product Type Model

## Rule

Similar payment frequency does not make products equivalent.

| Type | Trigger | Primary obligation | Key risk |
|---|---|---|---|
| `AT_NEED_SERVICE_ORDER` | Death/immediate need | Deliver coordinated service now | Urgency and fulfillment failure |
| `PRE_NEED_PLOT_PURCHASE` | Future preparation | Preserve right/service over long horizon | Liability, price, reserve, cancellation |
| `FUNERAL_PROTECTION_MEMBERSHIP` | Periodic membership/protection | Pay benefit/provide service upon covered event | Insurance/protection regulation |
| `CARE_SUBSCRIPTION` | Recurring care | Perform scheduled grave care | Billing vs fulfillment mismatch |
| `MARKETPLACE_PRODUCT_ORDER` | Purchase | Deliver item/service | Vendor, refund, quality |
| `RENEWAL_ORDER` | Expiring right/permit | Renew recorded period | Tariff authority and duplicate channel |

## Initial scope

- At-Need: proposed R1.
- Care subscription and renewal: RKS scope, gated by payment/data.
- Paid Pre-Need: legal/financial gate.
- Funeral protection: not covered by RKS; separate discovery and legal analysis required.
