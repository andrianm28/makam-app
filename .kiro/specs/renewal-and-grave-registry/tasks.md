# Tasks — Renewal and Grave Registry

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

Status reviewed 08 Aug 2026 against the shipped S4-T7 code and CI run [`31248602859`](https://github.com/andrianm28/makam-app/actions/runs/31248602859) at commit `a150a3b`. S4-T7 was scoped to the **renewal skeleton — steps 1–3 only** (`sprint-plan.md`); steps 4–6 (fee, payment, confirmation) have no screen and are Sprint 13.

- [x] Implement the six visible journey steps (city, TPU/TPS, grave search, fee, payment, confirmation/invoice). _Requirements: 1_ — done 08 Aug 2026 (agent team, S4-T7), CI run `31248602859`. Added 08 Aug 2026 (later the same day) — this AC had real shipped evidence but no `_Requirements: 1_`-tagged line in this list; the design-system Tasks section below already covered the stepper build itself. `App\Domain\Renewal\RenewalJourneyStep` names all six steps; `App\Livewire\Public\Renewal\RenewalStart` and `GraveSearch` render them via `<x-mk.stepper>`. Only steps 1–3 have a screen behind them (steps 4–6 are Sprint 13, per this file's own header note) — the stepper still shows all six, which is the correct product framing, not a shortfall against this AC: `RenewalStartTest::test_the_stepper_shows_this_journeys_six_steps_not_the_nine_booking_ones`, `GraveSearchStatesTest::test_the_stepper_shows_this_journeys_six_steps_and_not_the_nine_booking_ones`
- [x] Include all five MVP launch areas in city selection. _Requirements: 2_ — done 08 Aug 2026 (agent team, S4-T7), CI run `31248602859`. Added 08 Aug 2026 (later the same day) — same documentation gap as AC1 above: real shipped evidence, no tagged line. `App\Livewire\Public\Renewal\RenewalStart` reads `App\Domain\CemeteryDirectory\CemeteryPublicQuery::launchCities()`, which derives from all five `LaunchCityCode::KNOWN_CODES` unconditionally — never filtered to cities with published cemeteries (the negative criterion this spec's own design intent names). `RenewalStartTest::test_all_five_launch_cities_are_offered_in_the_canonical_order` and `::test_a_city_with_no_published_cemetery_is_still_offered`
- [x] Enable/configure PostgreSQL trigram support. _Requirements: 3, 4_ — done 08 Aug 2026 (agent team, S4-T7), CI run `31248602859`. `2026_08_08_100000_create_grave_records_table.php` creates the `pg_trgm` extension and a GIN trigram index on the normalized-name column; both are asserted against the live database, not against the migration source: `GraveRecordTrigramSearchTest::test_the_pg_trgm_extension_is_installed_by_the_migration` and `::test_the_gin_trigram_index_exists_on_the_normalized_name_column`
- [x] Implement grave record model and access modes. _Requirements: 12, 14_ — done 08 Aug 2026 (agent team, S4-T7), CI run `31248602859`. `App\Domain\GraveRegistry\Models\GraveRecord` plus the three AC14 modes in `GraveRecordAccessMode`, defaulting to the **most restrictive**, with an unknown mode rejected rather than falling open (`GraveRecordAccessModeTest`, 5 methods). AC12's field list is present in the schema, but note the deliberate divergence: **no seeded record stores an heir contact** and the public projection has no property that could carry one (`GraveRecordSeedTest::test_no_seeded_record_stores_an_heir_contact`, `GraveRegistryPublicQueryTest::test_no_access_mode_can_project_heir_contact_because_the_projection_has_no_such_property`)
- [ ] Implement fuzzy search with benchmark at 100k records. _Requirements: 3, 4_ — **partial, and the split matters.** AC3 fuzzy search is done and CI-green: misspellings still match, unrelated names do not, short exact substrings survive a low similarity score, and name/block/death-date terms combine rather than alternate (`GraveRecordTrigramSearchTest`, `GraveRegistryPublicQueryTest`). **AC4 (< 500 ms at 100,000 records) is NOT TESTED and is not passing** — nothing in this batch measures latency and nothing loads 100k rows; `GraveRecordTrigramSearchTest` says so in its own header. The one `assertLessThan` in that file bounds a *similarity score*, not a duration. Do not read it as a benchmark
- [ ] Implement async 10k-row import and row error report. _Requirements: 13_ — not started
- [ ] Implement renewal quote with tariff source/effective time. _Requirements: 6, 7_ — not started; this is journey step 4, which has no screen (Sprint 13)
- [x] Implement manual entry/empty state. _Requirements: 5_ — done 08 Aug 2026 (agent team, S4-T7), CI run `31248602859`. This is the spec's highest-stakes requirement and it shipped as **three genuinely distinct states**, not one message: no-result (three parts — what is empty, why the registry may be incomplete, what to do next), privacy-limited, and gate-closed. Held apart by assertions written as denials, which is what makes them load-bearing: `GraveSearchStatesTest::test_the_privacy_limited_state_never_says_the_record_was_not_found`, `::test_the_no_result_state_is_not_confused_with_the_other_two`, `::test_the_gate_closed_state_never_implies_the_record_does_not_exist`, `::test_a_search_backend_failure_is_never_reported_as_not_found`, and `::test_the_privacy_limited_state_discloses_no_withheld_name`
- [ ] Implement external marking and duplicate-period guard. _Requirements: 10, 11_ — not started; both need a renewal record, which arrives with steps 4–6
- [ ] Integrate payment/invoice after gate. _Requirements: 8, 9_ — not started; journey steps 5–6, no screen (Sprint 13). `RenewalJourneyStepTest::test_only_the_first_three_steps_are_implemented_in_sprint_4` pins that boundary in code so it cannot drift silently. **Correction, 09 Aug 2026 (`Renewal` retrofit):** the preceding sentence overstates that test. Its body is a single `assertSame(RenewalJourneyStep::GRAVE_SEARCH, RenewalJourneyStep::LAST_IMPLEMENTED)` and `RenewalJourneyStep.php` declares `LAST_IMPLEMENTED = self::GRAVE_SEARCH`, so it compares a constant to its own definition. It guards the constant's *declaration* against one class of deliberate edit; it does **not** pin the boundary (renumbering the constant moves both sides together, and nothing in the Renewal production path reads `LAST_IMPLEMENTED` at all) and it is **not evidence the steps are absent**. The claim "not started; journey steps 5–6, no screen (Sprint 13)" is independently true and stands on `routes/web.php` and `screen-inventory.md` PUB-033/PUB-034, not on this test
- [ ] Implement reminder scheduler and idempotency key. _Requirements: 15_ — not started
- [ ] Add privacy, authorization, performance, and duplicate tests. _Requirements: 4, 11, 14, 16_ — **partial, 2 of 4.** Privacy (AC14) and the AC16 gate are done and CI-green: a closed record is counted but never reported as not-found, a search never reaches a record in another cemetery, a term-less search returns nothing rather than dumping the registry, a draft cemetery cannot be searched through a held URL, the query never returns an Eloquent model, and a closed gate runs no search even when the URL carries search terms. **Performance (AC4) is NOT TESTED** — see the fuzzy-search item above. **Duplicate-period (AC11) is NOT TESTED** — there is no renewal record to duplicate yet

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

The empty state on this journey carries unusual weight: a family searching for a grave record and finding nothing must not conclude the grave does not exist.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Six-step progress | `<x-mk.stepper>` §3.9 | same primitive as the booking wizard, **six** steps; `--mk-progress-track`, `--mk-progress-fill` |
| Search form | `<x-mk.field>` §3.2 | `--mk-border-interactive`, `--mk-control-h-md`, `--text-base` (16 px floor), `inputmode` hints for dates |
| Result rows | `<x-mk.table>` §3.5 → cards below `--breakpoint-md` | `--mk-table-hover`, `--mk-table-stripe` |
| Fee display | `<x-mk.card>` §3.3 | amount `--font-weight-bold` `--font-mono`; **source + last-updated mandatory** (AC6) in `--text-sm` `--mk-text-muted` |
| Tariff mismatch warning | `<x-mk.alert intent=pending>` §3.8 | `--mk-intent-pending-*` — a mismatch is a caution, not an error |
| Renewal status | `<x-mk.badge>` §3.6 + §3.7 | `MENUNGGU_PEMBAYARAN` → `pending`; `DIBAYAR` → `success`; `KEDALUWARSA` → `neutral` |
| Payment step | §6.9 mode banner | manual fallback = `intent=info`; **never remove the payment step** |
| Confirmation / invoice | `<x-mk.card>` | reference `--font-mono`, copyable; due date prominent |
| Import (admin) | progress + row errors | `role="progressbar"`; row-level errors in `<x-mk.table>` |

### Required UI states

All ten states apply — design-system.md **§6**.

| Screen | State notes |
|---|---|
| PUB-030 city/cemetery | loading §6.1 · empty §6.2 — never omit a required MVP city |
| PUB-031 grave search — results | loading skeleton rows with `sr-only` announcement; reserve heights |
| PUB-031 — **no result** | §6.2, three parts: *what is empty · why (the registry may be incomplete) · next action*. AC5 requires an honest manual-entry / customer-service path. **Do not imply the record does not exist.** |
| PUB-031 — **privacy-limited** | §6.2 — **distinct from "not found"**. When `G-DATA-01` restricts the field projection, say so explicitly. Two different states, two different messages |
| PUB-031 — data gate closed | §6.4 explanatory state (AC16), never a generic 404 |
| PUB-032 fee | source + last-updated always visible · mismatch warning `pending` · **no invented late fine** (AC7) — if there is no written operator basis, show nothing rather than a computed figure |
| PUB-033 payment | online · manual fallback §6.9 · `pending` · failed §6.5 with a live fallback |
| PUB-034 confirmation | success §6.8 **quiet**; reference, status, invoice state, resulting due date |
| duplicate/retry-safe | §6.6 — AC11 duplicate-period guard must surface as "sudah diperpanjang untuk periode ini", not a second invoice |
| provider unavailable | §6.5 — search backend down → state it, offer manual assistance |
| support | §6.10 on every step |
| responsive | §4.3 — result tables become cards below `--breakpoint-md` |

### Performance affects design

AC4 targets < 500 ms at 100k records. Skeleton loading (§6.1) must reserve exact row heights to keep CLS < 0.1, and the search field should debounce rather than fire per keystroke. Weight budget: design-system.md §4.6.

### Tasks

Reviewed 08 Aug 2026 against the shipped S4-T7 code and CI run `31248602859` (commit `a150a3b`). Only PUB-030 and PUB-031 exist; PUB-032/033/034 have no screen, so every item scoped to them stays `[ ]`.

- [x] Reference tokens for all colour/spacing/type; zero hardcoded values. — done 08 Aug 2026 (agent team, S4-T7), CI run `31248602859`, job **Docs and design gates**: `ci/verify-docs.sh` scans `resources/` and `app/` for hardcoded hex/px/ms/shadow and Tailwind arbitrary values, and passed with both renewal views in the tree
- [x] Build the six-step stepper with `<x-mk.stepper>` (same primitive, six steps). — done 08 Aug 2026 (agent team, S4-T7), CI run `31248602859`. Both screens render `<x-mk.stepper>`, and the risk this task actually guards against — reusing the booking wizard's nine steps — is tested directly: `RenewalStartTest::test_the_stepper_shows_this_journeys_six_steps_not_the_nine_booking_ones`, `GraveSearchStatesTest::test_the_stepper_shows_this_journeys_six_steps_and_not_the_nine_booking_ones`, and `RenewalJourneyStepTest::test_the_renewal_labels_are_not_the_nine_booking_labels`. The stepper displays all six steps even though only three are implemented, which is the correct product framing
- [x] Implement **three distinct empty states**: no-result, privacy-limited, and gate-closed. Do not collapse them into one message. — done 08 Aug 2026 (agent team, S4-T7), CI run `31248602859`. See the AC5 item above for the specific denial-shaped assertions that keep the three apart; `sprint-plan.md` flags collapsing them as *the* defect for this task, and they did not collapse
- [ ] Always render tariff source + last-updated; never display a computed fine without written basis. — not started; PUB-032 (the fee step) has no screen yet. Nothing renders a tariff or a fine today, so this is unbuilt rather than violated
- [ ] Surface the duplicate-period guard as an informative state, not a failure. — not started; no renewal record exists to duplicate
- [ ] Implement all ten required states per the table above. — **partial, and only for PUB-030/PUB-031.** Implemented and CI-green: §6.1 loading, §6.2 empty (all three distinct states), §6.3 validation error (a blank submission and an invalid death date are validation errors, explicitly *not* a no-result), §6.4 authorization failure (gate-closed page; a draft cemetery is unreachable through a held URL), §6.5 provider unavailable (a search backend failure is never reported as not-found; a failed cemetery read degrades instead of 500ing), §6.9 gated fallback banner (rendered **without removing the step**), §6.10 support (asserted present in *every* state, not just the happy path). **Not implemented:** §6.6 duplicate/retry-safe, §6.7 pending, §6.8 success — all three belong to steps 4–6, which have no screen
- [ ] Verify accessibility (§7) and that skeletons reserve exact heights (CLS < 0.1). — **NOT TESTED.** Skeletons exist in both views, but no browser, Dusk, Playwright, or Cypress harness exists in this repository, so **no CLS figure was measured and no reserved height was verified**. Treat the CLS < 0.1 target as unverified, not met
