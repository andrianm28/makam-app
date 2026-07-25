# Tasks — Package and Service Bundles

- [ ] Add package/version/item schema.
- [ ] Build admin editor with publish workflow.
- [ ] Implement quote expansion and price snapshots.
- [ ] Implement substitution and evidence rules.
- [ ] Add inclusion/exclusion and version regression tests.

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
