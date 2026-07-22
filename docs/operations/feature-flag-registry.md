# Feature Flag Registry — v0.2

| Flag | Default | Owner | Prerequisite |
|---|---:|---|---|
| `feature.urgent_booking` | Off | Operations/Product | G-OPS-01 |
| `feature.preneed_interest` | On | Product | Approved interest flow |
| `feature.preneed_payment` | Off | Legal/Finance | G-LEGAL-01 |
| `feature.funeral_protection` | Off | Legal/Product | G-PROTECTION-01 |
| `feature.land_marketplace` | Off | Legal/Product | G-LAND-01 |
| `feature.online_payment` | Off | Finance/Engineering | G-PAY-01 |
| `feature.plot_inventory` | Off per cemetery | Data owner | G-CAP-01/G-PLOT-01 |
| `feature.plot_reservation` | Off per cemetery | Operator/Engineering | G-PLOT-01 |
| `feature.direct_plot_purchase` | Off | Legal/Finance | G-DIRECT-01 |
| `feature.platform_certificate` | Off per issuer | Legal/Operations | G-CERT-01 |
| `feature.visitation_booking` | Off per cemetery | Operator | G-VISIT-01 |
| `feature.memorial_public` | Off | Privacy/Product | G-MEM-01 |
| `feature.memorial_qr` | Off | Privacy/Product | G-MEM-01 |
| `feature.vendor_auto_payout` | Off | Finance | G-PAYOUT-01 |
| `feature.subscription_tokenization` | Off | Finance/Security | G-TOKEN-01 |
| `feature.whatsapp` | Off | Operations | G-WA-01 |
| `feature.grave_search` | Off | Data owner | G-DATA-01 |
| `feature.grave_reminders` | Off | Data owner/Operations | G-DATA-01 |

Flags may be global, environment, cemetery, issuer, area, or time-window scoped. Every change requires requester, approver, evidence, scope, timestamp, previous/new value, and rollback reason. UI hiding is insufficient; domain Actions must enforce flags and capability profiles.
