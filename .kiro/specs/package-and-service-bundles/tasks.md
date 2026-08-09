# Tasks — Package and Service Bundles

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [x] Add package/version/item schema. _Requirements: 1, 7_ — done 26 Jul 2026 (Sprint 4 S4-T1, master-data batch): `service_packages`/`service_package_versions`/`service_package_items` schema + lifecycle Actions (`DefineServicePackage`, `PublishServicePackageVersion`, `ReviseServicePackageVersion`), CI green
- [ ] Build admin editor with publish workflow. _Requirements: 2_ — **partial**: the publish-workflow backend (Actions above, with AC2 immutability enforced at the model layer) is done and tested; the admin Filament editor UI is not built (out of S4-T1's master-data scope)
- [ ] Implement quote expansion and price snapshots. _Requirements: 3, 4_ — **partial**: the price-*versioning* mechanism is done (`price_versions` schema, `RecordServiceDefinitionPriceVersion`, tested). There is no price *snapshot*: nothing copies a price into a durable quote-held reference, and until the 09 Aug 2026 ServiceCatalog retrofit added an append-only guard, `PriceVersion` did not enforce the immutability a snapshot depends on. Quote *expansion* (turning a package into real order/quote line items) is not built — that belongs to the booking wizard/orchestration work (S4-T4/S4-T5 onward)
- [ ] Implement substitution and evidence rules. _Requirements: 5, 6_ — **partial**: the *authoring* half is done 26 Jul 2026 (`SubstitutionPolicy`/`EvidenceRequirement` schema, written by `DefineServicePackage` and copied by `ReviseServicePackageVersion`, asserted in `ServicePackageLifecycleTest`). The *enforcement* half is **not built**: nothing applies a substitution, obtains customer approval, or sets an item `pending` (AC5), and no completion Action exists anywhere in this repo — `app/Domain/VendorFulfillment/` holds only `.gitkeep` files (AC6). Owner of the enforcement half: `funeral-marketplace-and-vendor-portal` (AC7/AC12, vendor status + work evidence) for vendor-fulfilled items, `grave-care-fulfillment` (AC3, work-order evidence requirements) for grave-care items. Corrected 09 Aug 2026 by the ServiceCatalog Superpowers retrofit.
- [x] Add inclusion/exclusion and version regression tests. _Requirements: 1, 2_ — done 26 Jul 2026 (`ServicePackageLifecycleTest`, `ServicePackageVersionImmutabilityTest`), coverage extended 09 Aug 2026 by the ServiceCatalog Superpowers retrofit (zero-item publish, revise copy fidelity including evidence requirements, transaction rollback, historical price stability, AC1's `service_area`). **AC8 removed from this line:** AC8 is a UI presentation requirement and this module owns no Blade, Livewire, or Filament file — its traceability belongs with the unchecked design-system tasks below, not with a checked backend-test line.

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

AC8 is a design requirement in disguise: *"UI clearly presents inclusions, exclusions, and additional charges."* Ambiguity here becomes a billing dispute.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Package card (public, Step 4/5) | `<x-mk.card>` §3.3 | `--radius-lg`, `--shadow-sm`, `--mk-border-subtle` |
| **Included items** | list + check icon | `--color-success-600` icon + text label — **never colour alone** (§7.5) |
| **Excluded items** | list + x icon | `--mk-text-muted` + explicit "Tidak termasuk" label; muted, not `danger` — an exclusion is not an error |
| **Optional items** | `<x-mk.field>` checkbox §3.2 | 20 px box in a **44 px** row; AC4 — becomes a separate accepted quote line |
| Additional charge | table row §3.5 | `text-right tabular-nums`, `--font-mono`; distinct from base price |
| Fulfillment owner (AC7) | `<x-mk.badge intent=neutral>` §3.6 | platform / cemetery operator / vendor — required by `service-catalog.md` |
| Version label | `<x-mk.badge intent=neutral>` §3.6 | published version immutable (AC2) |
| Draft vs published (admin) | `<x-mk.badge>` §3.6 | draft `neutral`, published `success`, **never** draft-as-success |
| Substitution notice (AC5) | `<x-mk.alert intent=info>` §3.8 | customer approval required where configured; `--mk-intent-info-*` |
| Admin editor | Filament forms §8.3 | inherits tokens; `--mk-border-interactive` |

### Required UI states

All ten states apply — design-system.md **§6**. This spec has no dedicated screen-inventory ID; its UI appears within **PUB-013 / PUB-014** (booking Steps 4–5) and **ADM-020** (admin catalogue).

| Surface | State notes |
|---|---|
| Package selection (PUB-013/014) | loading §6.1 skeleton cards · empty §6.2 ("Belum ada paket untuk lokasi ini" + alternative) |
| Unavailable package item | `neutral` on `--color-neutral-50` with reason + alternative — **not `danger`** |
| Quote expansion (PUB-014) | validation §6.3 if an optional item conflicts; **price change requires explicit reconfirmation and a new version** |
| Substitution | `pending` §6.7 while awaiting customer approval; `info` alert when applied |
| Admin editor (ADM-020) | loading · validation §6.3 inline + summary · publish `pending` → `success` §6.8 quiet |
| authorization | §6.4 — explanatory state for unpublished/out-of-scope package |
| provider unavailable | §6.5 — price-source failure must not silently show a stale price; state the source and time |
| duplicate/retry-safe | §6.6 — republishing the same version must be idempotent, never create a second version |
| support | §6.10 |
| responsive | §4.3 — inclusion/exclusion lists must stay legible at 320 px; do not use side-by-side comparison tables on mobile |

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Present inclusions/exclusions/additional charges with icon + text, never colour alone (AC8, §7.5).
- [ ] Render fulfillment owner as an explicit badge on every item (AC7).
- [ ] Style exclusions as muted, not `danger`.
- [ ] Implement all ten required states per the table above.
- [ ] Verify mobile legibility of inclusion/exclusion lists at 320 px; no comparison tables on mobile.
- [ ] Confirm the Filament package editor inherits tokens (§8.3).
