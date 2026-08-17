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

> **Note (16 Aug 2026, P4 verification):** the three P4 rows above were checked against the shipped code (`docs/design-system-and-planning` @ `66951bf`, PRs #81–#84). `feature.memorial_public` and `feature.memorial_qr` default `Off` with prerequisite `G-MEM-01` — matching `ModeResolver::memorialMode()` → `MemorialMode::fromGateOpen($gates->isOpen('G-MEM-01'))`: while the gate is closed the `/m/{token}` resolve is fail-closed (uniform not-visible state, no token lookup), and family/admin surfaces are not gated by it. `feature.visitation_booking` defaults `Off per cemetery` with prerequisite `G-VISIT-01` — the shipped public mode authority is the per-cemetery `cemetery_capability_profiles.visitation_mode` column (`NONE | INFORMATION_ONLY | BOOKABLE`, default `NONE`), read server-side via `PublicCapabilityProjection`; **no code reads the `G-VISIT-01` gate** — it remains the registry-level prerequisite behind opening bookable mode, enforced by configuration, not by an `isOpen()` call.

> **Note (16 Aug 2026, P5a verification):** the two Pre-Need rows were checked against the shipped code (`docs/design-system-and-planning` @ `6fc19dd`, PRs #86/#88/#89/#90) and match the shipped fail-closed behavior. `feature.preneed_payment` defaults `Off` with prerequisite `G-LEGAL-01` — the paid Pre-Need flow is **built and gated, not absent**: all seven paid-flow actions exist and each begins with `ModeResolver::preNeedMode()` → `PreNeedMode::fromGateOpen($gates->isOpen('G-LEGAL-01'))`; while the gate is closed every paid action throws the uniform `PreNeedGateClosedException` (no state change, the attempt audited `PRENEED_GATE_DENIED`), and the admin case resource surfaces the honest 'Belum dapat diaktifkan' denial. `feature.preneed_interest` defaults `On` with prerequisite "Approved interest flow" — the interest and consultation flows are **never gated by `G-LEGAL-01`**: `/preneed` renders `PreNeedMode::InterestOnly`'s non-dismissible info banner while the gate is closed, and registration + consultation always work (the seed migration at `database/migrations/2026_07_26_120400_seed_feature_gate_registry.php` keeps only `preneed_interest` on by default, matching the registry's "Default" column). `assumptions-and-gates.md` §2's `G-LEGAL-01` row ("Behavior before active: Interest/consultation only") is likewise consistent.
