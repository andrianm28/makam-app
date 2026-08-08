# Tasks — Cemetery Directory and Availability

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [x] Add capability profile schema and validation. _Requirements: 4_ — done 26 Jul 2026 (Sprint 4 S4-T1, master-data batch): `cemetery_capability_profiles` schema + closed-list validation for all six modes, CI green
- [x] Add safe-default resolver and server-side checks. _Requirements: 4, 12_ — done 08 Aug 2026 (agent team, S4-T6), CI run [`31248602859`](https://github.com/andrianm28/makam-app/actions/runs/31248602859) at commit `a150a3b`. Both halves are now real: the AC4 safe-default resolver `App\Domain\CemeteryCapability\Actions\ResolveCemeteryCapabilityProfile` landed in S4-T1, and S4-T6 closed the AC12 half with `App\Livewire\Public\Directory\Support\PublicCapabilityProjection` — a four-key allowlist expressed as *class structure* (no property, accessor, or array key for `registry_mode`/`certificate_mode` exists at all), so there is no `->toArray()` or stray `{{ $profile->registry_mode }}` path by which a restricted mode can reach a view. Evidence: `tests/Feature/Livewire/Public/Directory/PublicCapabilityProjectionTest.php` — 6 methods, including a `ReflectionClass` walk over the projection's own properties and a re-parse of `docs/contracts/openapi.yaml`'s `PublicCapabilityProfile` schema so a contract change cannot silently diverge
- [x] Update public directory projection by capability. _Requirements: 2, 3, 5, 12_ — done 08 Aug 2026 (agent team, S4-T6), CI run `31248602859` at commit `a150a3b`: `/cemeteries` (`CemeteryDirectoryIndex`) and `/cemeteries/{cemeterySlug}` (`CemeteryDetail`), reading through `App\Domain\CemeteryDirectory\CemeteryPublicQuery` → `CemeteryPresenter` → `PublicCapabilityProjection`, with availability resolved through the single `CemeteryAvailabilityIntent` helper. **Naming caveat:** at CI run `31248602859` this read path was `App\Livewire\Public\Directory\Support\CemeteryDirectoryQuery`; it was merged into the shared domain-level `CemeteryPublicQuery` immediately afterwards so the directory and renewal journeys stop maintaining two competing published-cemetery queries. **That merge is uncommitted and has not yet been through CI** as of this edit — the `[x]` above is earned by run `31248602859`, and the merge needs its own green run. Evidence: `CemeteryDirectoryIndexRouteTest` (16 methods), `CemeteryDetailRouteTest` (17), `CemeteryAvailabilityIntentTest` (10). **Route-vocabulary note:** the shipped paths are `/cemeteries…`, matching the noun `openapi.yaml` already uses for `GET /cemeteries`, **not** `information-architecture.md`'s Indonesian route tree. Surfaced here rather than quietly re-pointed — reconciling IA §1 with the shipped URIs is a documentation-ownership decision for whoever owns `information-architecture.md`
- [ ] Add optional plot source adapter interface. _Requirements: 6, 7_ — not started; deliberately out of S4-T1's *and* S4-T6's scope (owned by the separate, not-yet-built `plot-inventory-and-reservation` spec — see that spec and S4-T1's own commit history for the boundary reasoning). S4-T6 consumes `MapMode` read-only via `PublicCapabilityProjection::hasPublicPlotMap()`; it adds no adapter
- [ ] Add stale-source monitoring and fallback. _Requirements: 8_ — not started. **Do not read S4-T6's §6.5 work as this task**: `CemeteryDirectoryIndexRouteTest::test_the_page_survives_the_cemeteries_table_being_unreadable` and `CemeteryDetailRouteTest::test_a_package_read_failure_is_reported_separately_from_an_empty_package_list` cover a *directory read* failure. AC8 is about a **plot data source** being missing or stale, which requires the adapter above to exist first; no freshness timestamp, monitor, or reservation-disabling path is implemented (08 Aug 2026)
- [ ] Add cross-cemetery authorization and capability-combination tests. _Requirements: 9_ — **partial**: closed-list/safe-default combination tests done (S4-T1); S4-T6 added the public read-side negative tests (`test_a_draft_cemetery_never_appears_in_the_public_directory`, `test_the_draft_and_unknown_slug_responses_are_indistinguishable`, `test_restricted_modes_do_not_leak_even_when_set_to_their_strongest_values`), all CI-green in run `31248602859`. **The AC9 half is still absent**: AC9 scopes *operator updates* to the operator's assigned cemetery and requires them audited. No write-side capability Action exists — `app/Domain/CemeteryCapability/Actions/` contains only `ResolveCemeteryCapabilityProfile` (verified by directory listing, 08 Aug 2026) — so there is nothing yet to scope or audit. That write path belongs to `admin-operations` and was **deliberately not** in this batch
- [ ] Benchmark directory and map queries. _Requirements: 2, 3, 11_ — not started. A query path now exists (`CemeteryPublicQuery`), so this is no longer blocked, but **no benchmark was written and none ran**: there is no timing assertion anywhere under `tests/Feature/Livewire/Public/Directory/`. NOT TESTED, not passing

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Cemetery card | `<x-mk.card as=a interactive>` §3.3 cemetery variant | `--radius-lg`, `--shadow-sm`, `--mk-border-subtle`, hover `--color-primary-300` + `--shadow-md` |
| Type badge (TPU/TPS) | `<x-mk.badge intent=neutral>` §3.6 | `--mk-intent-neutral-fg/bg/border` |
| Availability badge | `<x-mk.badge>` §3.6 + §3.7 | see the availability mapping below |
| `Perlu konfirmasi` label | `<x-mk.badge intent=neutral>` | **`--mk-intent-neutral-*` — never `success`.** An indicative price/availability styled as success is a false promise (design-system.md §2.3 DO) |
| Price range + source | meta text | `--text-sm`, `--mk-text-muted`; source attribution is mandatory (AC3) |
| Facilities list | inline tags | `--mk-intent-neutral-*`, `--radius-sm` |
| Google Maps link | `<x-mk.button variant=secondary>` §3.1 | external navigation; AC11 — map-provider failure must not hide the textual address |
| Filter controls | `<x-mk.field>` §3.2 | `--mk-border-interactive`, `--mk-control-h-md`, 44 px targets |
| Card grid | layout §4.3 | 1 col → `--breakpoint-md` 2 → `--breakpoint-xl` 3; `--mk-gutter` |

### Availability → visual intent (normative)

Resolve through the single `StatusIntent` helper (design-system.md §3.7). Components must not switch on capability strings.

| Availability state | Intent | Rationale |
|---|---|---|
| Indicative package/class (default, AC5) | `neutral` + `Perlu konfirmasi` | Not a guarantee — AC negative criteria forbid implying one |
| Confirmed available | `success` | Only with authoritative evidence |
| Awaiting operator confirmation | `pending` | §6.7 — never styled as success |
| Unavailable (capacity/closed) | `neutral` | Not an error |
| `SPECIFIC_PLOT` active (AC7) | `info` | Gated capability, only with authoritative registry |
| Stale/degraded source (AC8) | `pending` + fallback notice | §6.5 — reservation disabled, request path retained |

### Required UI states

All ten states apply — design-system.md **§6**.

| Screen | State notes |
|---|---|
| PUB-010 city list | loading §6.1 · empty "no city" §6.2 — **never silently omit a required MVP city** (negative criteria) |
| PUB-011 list | loading skeleton cards (reserve exact heights, CLS < 0.1) · **no-result** §6.2 with `Reset filter` |
| PUB-011 detail | error §6.3 — capability resolution failure falls back to safe defaults (AC4), not a blank page |
| authorization | §6.4 — restricted plot data must never reach a public projection (negative criteria); explanatory page for gated capability, not a 404 |
| provider unavailable | §6.5 — stale plot source → disable reservation, keep package/class request path, state the reason |
| pending | §6.7 — awaiting operator availability confirmation |
| success | §6.8 — quiet |
| support | §6.10 |
| responsive | §4.3 — 320 / 360 / 768 / 1024 / 1280 px |

### Tasks

Ticked 08 Aug 2026 against the shipped S4-T6 code and CI run `31248602859` (commit `a150a3b`), not against the batch's self-report.

- [x] Reference tokens for all colour/spacing/type; zero hardcoded values. — done 08 Aug 2026 (agent team), CI run `31248602859`, job **Docs and design gates**. This is machine-enforced, not eyeballed: `ci/verify-docs.sh` scans `resources/` and `app/` for hardcoded hex/px/ms/shadow values and Tailwind arbitrary values, and it passed with both directory views in the tree
- [x] Build the cemetery card with all AC3 fields; availability badge via `StatusIntent` only. — done 08 Aug 2026 (agent team), CI run `31248602859`. Evidence: `CemeteryDirectoryIndexRouteTest::test_a_card_shows_every_ac3_field` and `CemeteryDetailRouteTest::test_the_detail_page_shows_every_ac3_field` (both enumerate the AC3 field list, they do not spot-check one field); `CemeteryAvailabilityIntentTest::test_every_intent_this_resolver_can_emit_is_a_canonical_status_intent` holds the "resolve through the one helper" half
- [x] Ensure indicative availability renders `neutral` + `Perlu konfirmasi`, never `success`. — done 08 Aug 2026 (agent team), CI run `31248602859`. Evidence is stated as an *absence*, which is the assertion that actually protects the rule: `test_no_success_intent_badge_renders_while_every_cemetery_is_indicative`, `test_an_available_package_does_not_render_as_success_while_the_cemetery_is_indicative`, and the data-provided `test_no_indicative_mode_can_ever_produce_a_success_intent`
- [ ] Implement all ten required states per the table above. — **partial**, 7 of 10. Implemented and CI-green: §6.1 loading (skeleton with reserved heights, index), §6.2 empty (index no-result; detail no-packages), §6.3 validation error (unknown `?city=`/`?type=` stated, list still rendered), §6.4 authorization failure (draft slug 404s indistinguishably from an unknown one; "plot layout is not public for this cemetery" explanation instead of a blank), §6.5 provider unavailable (directory read failure; package read failure reported separately from an empty package list), §6.7 pending, §6.10 support. **Not implemented:** §6.6 duplicate/retry-safe, §6.8 success, §6.9 gated fallback banner — defensible on a read-only browse surface with no mutation, no gate, and no success outcome, but recorded as absent rather than counted as done
- [x] Verify only active capabilities render (AC12) and no restricted field reaches the public projection. — done 08 Aug 2026 (agent team), CI run `31248602859`. Evidence: `PublicCapabilityProjectionTest` (structural — the projection has no property for a restricted mode) plus the rendered-output checks `test_restricted_capability_modes_never_reach_the_rendered_directory`, `test_restricted_capability_modes_never_reach_the_rendered_detail_page`, and `test_restricted_modes_do_not_leak_even_when_set_to_their_strongest_values`
- [ ] Verify accessibility (§7): 44 px targets, focus ring, no colour-only availability signalling. — **partial**. The colour-only half is done and CI-green: `test_the_active_filter_chip_carries_a_non_colour_selection_cue` asserts a non-colour cue (WCAG 1.4.1) rather than trusting the class list. **44 px touch targets and the focus ring are NOT TESTED** — there is no browser, Dusk, Playwright, or Cypress harness in this repository, so no rendered geometry or `:focus-visible` state was measured by anything
