# Retrofit: Renewal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the `Renewal` module (the non-GraveRegistry half of Kiro spec `renewal-and-grave-registry` — AC1, AC2, and the journey-shell side of AC5/AC16) the two-tier Superpowers SDD review it never got, close any Critical/Important findings with a bounded fix wave and real regression tests, and give explicit disposition to every self-flagged gap already on record (AC6–AC13 and AC15's whole unbuilt data model, AC4's NOT TESTED latency claim as it lands on this module's row, the accessibility/CLS gap, and the unsubstantiated `audit` dependency claim on `sprint-plan.md`'s S4-T7 row).

**Architecture:** Review-and-fix retrofit, matching the recipe the four prior retrofits this session exercised (`CemeteryDirectory`+`CemeteryCapability` pilot, `IdentityAccess/Mfa`+`Reauthentication`, `Faq`, `GraveRegistry`). Work happens in an isolated worktree/branch off `docs/design-system-and-planning` (which already carries `GraveRegistry`'s merged PR #11), fans task-scoped review out by functional slice, runs one whole-module reviewer over the combined findings plus the code holistically, then applies one bounded fix wave.

**Scope note, stated up front — this is the smallest surface any retrofit in this program has had, and that is the finding, not an accident:** `Renewal` is a thin skeleton. Its entire owned surface is **two source files** (`app/Domain/Renewal/RenewalJourneyStep.php`, `app/Livewire/Public/Renewal/RenewalStart.php`), **one Blade view** (`resources/views/livewire/public/renewal/start.blade.php`), and **two test files** (`tests/Unit/Domain/Renewal/RenewalJourneyStepTest.php`, `tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php`). `app/Domain/Renewal/Actions/` and `app/Domain/Renewal/Models/` are both `.gitkeep`-only. Journey steps 4–6 (fee, payment, confirmation) and their **entire data model** (`renewals`, `renewal_quotes`, `renewal_external_markings`, `reminder_deliveries` — all named in `design.md`'s `## Data` block) are **genuinely unbuilt**: no migration, no model, no Action, no screen. This retrofit **reviews what exists**. It does not build steps 4–6, and it does not treat their absence as a defect — Sprint 13 owns them.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4, PostgreSQL 18 (CI oracle) / SQLite (local), Pest via PHPUnit, `ci/verify-docs.sh`.

## Global Constraints

- Never hardcode a design value or use a Tailwind arbitrary value — `ci/verify-docs.sh` gates 2/3/11/12.
- **Hard file exclusion, non-negotiable:** `app/Livewire/Public/Renewal/GraveSearch.php`, `resources/views/livewire/public/renewal/grave-search.blade.php`, and `tests/Feature/Livewire/Public/Renewal/GraveSearchStatesTest.php` are **out of scope and must not be modified**. They were retrofitted as `GraveRegistry` (backlog item 4, PR [#11](https://github.com/andrianm28/makam-app/pull/11), merged) and that retrofit's own plan (`docs/superpowers/plans/2026-08-09-retrofit-graveregistry.md`, "Review scope") explicitly claimed the `GraveSearch` seam. Reviewers may **read** them for context — GraveSearch is journey step 3 and therefore the direct downstream consumer of the contract `RenewalJourneyStep` defines — but no finding against them enters this retrofit's fix wave. Route one to the backlog instead.
- `AGENTS.md` §Mandatory MVP UX: "Booking exposes Steps 1–9 exactly as documented" and its sibling rule that a documented step is never renamed, reordered, or hidden. `RenewalJourneyStep` is the Renewal-side equivalent contract; the whole point of the class is that a not-yet-built step still stays visible.
- `AGENTS.md` §Testing: every Critical/Important fix gets a real regression test — CI (PostgreSQL 18) is the oracle. **A known recurring hazard this session:** local SQLite behavior diverges from CI's PostgreSQL 18 in ways only CI catches (the `pg_trgm` fuzzy-search path is the standing example). Never report a local-only result as a CI result.
- `AGENTS.md` §Infrastructure-agent execution: never report PASS for a check not executed; use BLOCKED or NOT TESTED explicitly. **This host cannot run PHP tests at all** — `vendor/` is empty and `CLAUDE.md` forbids `composer install`/`npm run build` here. `php -l` and `bash ci/verify-docs.sh` are the only executable local gates. Every reviewer must state test execution as BLOCKED rather than implying a pass.
- `AGENTS.md` §Documentation: do not duplicate canonical catalogue data. `LaunchCityCode` (owned by `CemeteryDirectory`) is the one source for the five launch cities; `RenewalJourneyStep::LABELS` is the one source for the six step labels. Neither may be retyped anywhere.
- Kiro spec `renewal-and-grave-registry` (`requirements.md`/`design.md`/`tasks.md`) is the "what to build" authority; this plan does not restate its ACs.
- **A citation-accuracy note, learned from all four prior retrofits:** verify every claimed quote against the real committed file before including it in a review report. Two specific traps in this spec: `design.md` is 50 lines with **no Renewal-journey section at all** (it is `## Search`/`## Data`/`## Duplicate prevention`/`## Privacy` — all grave-registry-shaped), and `requirements.md` is a bare 16-item numbered list with no per-AC prose. If a reviewer wants design rationale for the Renewal journey specifically, it exists only in the two source files' own class doc blocks — cite it there, not in `design.md`.

---

## Current shipped state

**The module's entire owned surface (verified by directory listing at branch point `7bc3b8d`):**

- `app/Domain/Renewal/RenewalJourneyStep.php` (118 lines) — a `final class` + `const` closed vocabulary for AC1's six visible steps (`CITY`=1 … `CONFIRMATION`=6), plus `LAST_IMPLEMENTED = GRAVE_SEARCH` (3). Its doc block states the contract this retrofit must stress: all six steps are always *visible* even though only 1–3 are *built*, because "§6.9's rule that a closed gate 'never removes a required MVP step' applies just as much to a not-yet-built one." Static-only: `labels()`, `count()`, `isKnown()`, `assertKnown()`, `label()`.
- `app/Livewire/Public/Renewal/RenewalStart.php` (153 lines) — `/perpanjangan`, screen PUB-030, journey steps 1–2 (city then cemetery). Holds exactly one piece of state (`#[Url(as: 'kota')] public string $city`) and **derives** the current step from it rather than tracking a second drift-prone field. Also holds `bool $cemeteryListUnavailable` for the §6.5 degraded-read path. Reads `CemeteryPublicQuery::launchCities()`/`::inCity()` (owned by `CemeteryDirectory`) and `ModeResolver::graveSearchMode()` (owned by `FeatureGate`). No write path anywhere.
- `resources/views/livewire/public/renewal/start.blade.php` (13 KB).
- `app/Domain/Renewal/Actions/.gitkeep`, `app/Domain/Renewal/Models/.gitkeep` — **both directories are empty.** No Renewal Action, no Renewal model, no Renewal-owned table, no Renewal migration exists anywhere in this repository.
- `tests/Unit/Domain/Renewal/RenewalJourneyStepTest.php` (100 lines, 7 test methods).
- `tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php` (258 lines, 13 test methods).

**No schema slice exists, and that is a verified fact rather than an oversight:** `design.md`'s `## Data` block names `renewals`, `renewal_quotes`, `renewal_external_markings`, and `reminder_deliveries`. None of them has a migration. `grave_records` (the one table this spec *did* ship) belongs to `GraveRegistry` and was reviewed under backlog item 4. This retrofit therefore has **no schema slice and no dedicated tests slice** — the tests fold into slices 1 and 2 alongside the code they cover, exactly as the plan's Lane 1 section specifies.

**Downstream consumer, read-only:** `app/Livewire/Public/Renewal/GraveSearch.php` (journey step 3) consumes `RenewalJourneyStep::labels()` and `::GRAVE_SEARCH`. It is **out of scope for modification** (see Global Constraints) but is the reason slice 1's contract review matters: a change to `RenewalJourneyStep` propagates into a file this retrofit may not touch.

**Verified before this plan was written (do not re-derive, but do confirm if a finding depends on it):**

- `grep -rn "Audit" app/Domain/Renewal app/Livewire/Public/Renewal app/Domain/GraveRegistry` returns **nothing**. `sprint-plan.md`'s S4-T7 row lists `audit` in its Dependencies column. **That dependency claim is unsubstantiated in code** — the same defect class already found and corrected on the S4-T6 row during the pilot retrofit. Task 5 append-corrects it.
- `grep -rn "FeatureGate\|ModeResolver" app/Livewire/Public/Renewal` returns real hits in **both** `RenewalStart.php` (line 145) and `GraveSearch.php` (line 228). The `feature-gate` dependency claim on the same row **is** substantiated. Correct one claim, not both.
- `App\Livewire\Public\ComingSoon\RenewalComingSoon` still exists. `routes/web.php:166` routes `/perpanjangan` to `RenewalStart::class`, not to the stub, and no route anywhere references `RenewalComingSoon`. Its only remaining mentions are doc-block prose in `routes/web.php`, `BookingWizard.php`, `RenewalStart.php`, and `RenewalStartTest.php`. **Likely dead code — see the cross-lane note below before proposing its removal.**

**Cross-lane coordination note (this retrofit runs concurrently with three siblings):** Lane 4 (`Marketplace`) is independently reviewing `App\Livewire\Public\ComingSoon\MarketplaceComingSoon`, a sibling class in the same `app/Livewire/Public/ComingSoon/` directory, and has already flagged it as dead code for removal. `RenewalComingSoon` is the same shape. If this retrofit's fix wave concludes `RenewalComingSoon` should be deleted, note that a full removal also touches **`routes/web.php`** (line 56's doc-block prose) and **`app/Livewire/Public/Booking/BookingWizard.php`** (line 34's doc block) — both files outside this module and potentially contended. **Preferred disposition: ledger the finding with its exact evidence and let a single follow-up unit remove both stubs together.** Deleting only `app/Livewire/Public/ComingSoon/RenewalComingSoon.php` and leaving its prose references dangling is worse than leaving it alone.

**`sprint-plan.md` S4-T7 row** (line 629, quoted in part): "✅ Done (08 Aug 2026, agent team), CI green — run `31248602859`, commit `a150a3b`. AC1–AC3, AC5, AC14 shipped at `/perpanjangan` + `/perpanjangan/cari`… **Partial:** only journey steps 1–3 have screens; steps 4–6 (fee, payment, confirmation) are Sprint 13. **AC4 (< 500 ms at 100k records) is NOT TESTED and not passing**… **Correction, 09 Aug 2026:** `GraveRegistry` (the grave-search half of this row) retrofitted…". Note the row is **shared** between this module and `GraveRegistry`, and already carries one append-correction from PR #11. Task 5 appends a **second** correction naming `Renewal` explicitly as the other half — it does not rewrite or renumber the first.

**Kiro spec self-reported gaps that land on this module** (`tasks.md`, quoted not summarized):

- **AC6/AC7** ("tariff amount, source, last-update time"; "no late fine without written operator basis"): "not started; this is journey step 4, which has no screen (Sprint 13)."
- **AC8/AC9** (payment mode / confirmation output): "not started; journey steps 5–6, no screen (Sprint 13). `RenewalJourneyStepTest::test_only_the_first_three_steps_are_implemented_in_sprint_4` pins that boundary in code so it cannot drift silently." **Slice 1 must verify that test really does pin the boundary** rather than merely asserting a constant equals itself.
- **AC10/AC11** (external marking; duplicate-period guard): "not started; both need a renewal record, which arrives with steps 4–6." AC11 additionally: "**Duplicate-period (AC11) is NOT TESTED** — there is no renewal record to duplicate yet." Formally ledger this.
- **AC13** (async 10k-row import): "not started."
- **AC15** (one reminder per grave per window): "not started."
- **AC4** (< 500 ms at 100k records): "**NOT TESTED**." Already ledgered unchanged by `GraveRegistry`'s retrofit; it belongs to the grave-search half, so this retrofit **re-states the existing disposition, it does not re-adjudicate it**.
- **Accessibility / CLS**: "**NOT TESTED.** Skeletons exist in both views, but no browser, Dusk, Playwright, or Cypress harness exists in this repository." The same program-level gap all four prior retrofits independently confirmed. Re-verify cheaply (one `find`/`grep`), don't just inherit the claim.
- **`tasks.md`'s "Implement all ten required states" item**: marked "**partial, and only for PUB-030/PUB-031**", listing §6.1/§6.2/§6.3/§6.4/§6.5/§6.9/§6.10 as implemented and §6.6/§6.7/§6.8 as belonging to steps 4–6. **Slice 2 must verify the PUB-030 half of that claim specifically** — several of those citations (§6.3 validation error, §6.4 authorization failure) are backed by `GraveSearchStatesTest`, which is PUB-031's file, not PUB-030's. A state claimed for both screens but tested on only one is exactly the kind of overclaim Task 5 corrects.

**What a design-time `brainstorming` pass would have asked, had one run before S4-T7 started** (reconstructed retrospectively, per the retrofit recipe):

1. "The stepper will show six steps when only three are reachable. What does a user see when they finish step 3 — is there an honest terminus, or does the journey just stop?" (This is the single most important open question this retrofit can answer, and it is a **product-honesty** question, not a code-style one. `AGENTS.md` §Mandatory MVP UX requires every transactional screen to have a support state; a journey that visibly promises three more steps it cannot deliver needs to say so somewhere.)
2. "`RenewalStart` derives `currentStep()` from `$city` alone. What happens when steps 4–6 arrive and the journey needs real cross-step state — does this shape extend, or does it get thrown away?" (Not asked at build time. Slice 1 should record the answer as a design constraint for Sprint 13's implementer, in the same way `GraveRegistry`'s retrofit recorded two constraints for AC13's future implementer.)
3. "Both `RenewalStart` and `GraveSearch` read `ModeResolver::graveSearchMode()` and render a gate affordance, but one renders a *banner* and the other a *page*. Is that divergence deliberate and documented, or drift?" (Was actually asked and answered in `RenewalStart.php:34-50`'s own doc block — confirmation of good practice, not a gap. Slice 2 should verify the answer still holds against the real Blade view rather than trusting the doc block.)

## Review scope

**In scope:**

- `app/Domain/Renewal/RenewalJourneyStep.php` and `tests/Unit/Domain/Renewal/RenewalJourneyStepTest.php` (slice 1).
- `app/Livewire/Public/Renewal/RenewalStart.php`, `resources/views/livewire/public/renewal/start.blade.php`, and `tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php` (slice 2).
- `app/Domain/Renewal/Actions/` and `app/Domain/Renewal/Models/` — confirm both are still `.gitkeep`-only, i.e. that the "unbuilt" claim is a verified fact and not a stale assumption (slice 3).
- Whole-module scope-honesty review of `.kiro/specs/renewal-and-grave-registry/tasks.md`, `design.md`, `docs/planning/sprint-plan.md`'s S4-T7 row, and `docs/product/screen-inventory.md`'s PUB-030 entry, against the real code (slice 3).

**Out of scope (do not touch — belongs to a different, separately-tracked unit of work):**

- **`GraveSearch.php`, `grave-search.blade.php`, `GraveSearchStatesTest.php`** — `GraveRegistry`, backlog item 4, merged PR #11. Read-only for context. See Global Constraints.
- All of `app/Domain/GraveRegistry/**` and the two `grave_records` migrations — same, backlog item 4.
- `App\Domain\CemeteryDirectory\CemeteryPublicQuery` and `LaunchCityCode` — backlog item 1 (pilot, done). This retrofit verifies `RenewalStart`'s **use** of them; it does not modify them.
- `App\Platform\FeatureGate\ModeResolver` and `GraveSearchMode` — backlog item 8. Verify the call site, don't modify the gate module.
- **Journey steps 4–6 and their entire data model** (`renewals`, `renewal_quotes`, `renewal_external_markings`, `reminder_deliveries`; AC6–AC11, AC13, AC15). Genuinely unbuilt, Sprint 13. **Ledger, do not build.** A finding of the form "AC7 is not implemented" is not a finding — it is a restatement of known scope.
- AC4's load-testing/benchmark harness — no owner module; already ledgered by `GraveRegistry`'s retrofit.
- Accessibility browser/axe harness — program-level gap, confirmed by four prior retrofits.
- `app/Livewire/Public/ComingSoon/MarketplaceComingSoon.php` — Lane 4's, concurrently.

---

## Task 1: Draft the review briefs and dispatch task-scoped review

**Files:**
- Create (ledger, git-ignored, inside the worktree): `.superpowers/sdd/retrofit-renewal/task-1-brief-{domain,ui-seam,scope-honesty}.md`
- Read only: everything under "Review scope" above.

**Interfaces:**
- Produces: three independent review reports (domain/contract, UI seam for steps 1–2 only, whole-module gap/scope-honesty), each graded against `AGENTS.md`, the `renewal-and-grave-registry` Kiro spec, and `mattpocock-skills:codebase-design`'s deep-module vocabulary.

- [ ] **Step 1: Create the worktree and branch**

```bash
git worktree add .worktrees/retrofit-renewal -b retrofit-renewal origin/docs/design-system-and-planning
cd .worktrees/retrofit-renewal
```

- [ ] **Step 2: Commit this plan doc first, before any review work starts**

```bash
git add docs/superpowers/plans/2026-08-09-retrofit-renewal.md
git commit -m "Add retrofit plan doc: Renewal"
```

- [ ] **Step 3: Dispatch three task-scoped review agents in parallel via `dispatching-parallel-agents`**

Each agent reviews ONLY its slice, against: (a) `AGENTS.md` in full, (b) `.kiro/specs/renewal-and-grave-registry/{requirements,design,tasks}.md` in full, (c) `mattpocock-skills:codebase-design` vocabulary, (d) this plan's "Current shipped state" section (verify and extend, don't re-discover). Every agent is told the hard file exclusion verbatim.

- **Slice 1 — domain/contract:** `RenewalJourneyStep.php` + `RenewalJourneyStepTest.php`. Ask explicitly: is the "6 visible, 3 implemented" contract airtight now that AC1 spans built and unbuilt steps — i.e. can a caller distinguish *visible* from *reachable* without retyping either list? Does `LAST_IMPLEMENTED` have any enforcement behind it, or is it a documentation constant a caller can ignore silently (and if the latter, is that acceptable given the two real callers, or a footgun worth a guard)? Does `test_only_the_first_three_steps_are_implemented_in_sprint_4` actually pin the boundary, or is it a tautology asserting a constant equals its own literal? Are `assertKnown()`'s failure message and `label()`'s contract consistent with `count()` when `LABELS` changes? Does the class's own doc-block claim about `stepper.blade.php`'s `labels` prop match what that Blade file really does — read it, don't trust the citation. Record any design constraint Sprint 13's step-4–6 implementer will need (brainstorming question 2 above).
- **Slice 2 — UI seam, steps 1–2 only:** `RenewalStart.php` + `start.blade.php` + `RenewalStartTest.php`. Ask explicitly: does every array/value crossing the Livewire→Blade seam have a precise shape (the exact defect class the pilot and `Faq` retrofits both found live instances of — `$cards`, `$code => $label`)? Is `launchCities()` really called twice per render (lines 130 and 137) and does that matter? Is the `#[Url]`-bound `$city` reset in `mount()` genuinely safe against a tampered value, and is `selectCity()`'s silent `return` on an unknown code the right failure mode or a swallowed error? Does the gate **banner** (not page) decision documented at lines 34–50 hold in the real Blade markup, including the dismissibility claim? Does the §6.5 degraded-read path (`cemeteryListUnavailable`) actually render something honest, and is `report($e)` at line 119 at risk of carrying restricted data into an error tracker (`AGENTS.md` §Observability — the same question `GraveRegistry`'s retrofit ledgered as program-level; state whether this call site adds a *new* instance or is covered by that existing ledger)? Verify PUB-030's own share of `tasks.md`'s ten-states claim — which of §6.1/§6.2/§6.3/§6.4/§6.5/§6.9/§6.10 are tested **on this screen's own test file**, versus claimed on the strength of `GraveSearchStatesTest`? Run `bash ci/verify-docs.sh` and report the real result.
- **Slice 3 — whole-module gap / scope-honesty:** no code changes; a documentation-versus-reality audit. Ask explicitly: do `sprint-plan.md` (S4-T7 row), `.kiro/specs/renewal-and-grave-registry/tasks.md`, `design.md`, `docs/product/screen-inventory.md` (PUB-030), and `AGENTS.md` **consistently** state that steps 4–6 and AC6–AC11/AC13/AC15 are unbuilt — or does any of them silently imply completion? Confirm the `audit` dependency claim on S4-T7 is unsubstantiated and the `feature-gate` claim is substantiated (both pre-verified above — re-confirm, then say so with the exact grep). Confirm `app/Domain/Renewal/{Actions,Models}/` are still `.gitkeep`-only. Confirm the accessibility-harness gap is still real (`find` for Dusk/Playwright/Cypress). Formally ledger AC4 and AC11's NOT-TESTED status with named reasons. Answer brainstorming question 1: what does a user actually see at the end of step 3, and does any document promise something the code does not deliver?

- [ ] **Step 4: Each reviewer writes its report**

Format, one per slice, saved to `.superpowers/sdd/retrofit-renewal/task-1-report-{domain,ui-seam,scope-honesty}.md`:

```markdown
# Task 1 report — <slice>

## Findings
- [Critical|Important|Minor] <file:line> — <what's wrong> — <why it matters, citing the AGENTS.md/spec rule violated>

## Confirmed correct (worth stating, not just silence)
- <thing that was checked and holds>

## Questions for the whole-module reviewer
- <anything that needs cross-slice context to judge>
```

- [ ] **Step 5: Commit the three briefs + three reports to the ledger**

```bash
git add .superpowers/sdd/retrofit-renewal/
git commit -m "Task 1: task-scoped review, 3 slices (domain, ui-seam, scope-honesty)"
```

## Task 2: Whole-module review

**Files:**
- Read: all three Task 1 reports, plus the full module (everything in "Review scope" above), read holistically.
- Create: `.superpowers/sdd/retrofit-renewal/task-2-whole-module-review.md`

- [ ] **Step 1: Dispatch one reviewer over the whole module** — consolidate, dedupe, resolve every "Questions for the whole-module reviewer."
- [ ] **Step 2: Rule explicitly on the journey-terminus question** (brainstorming question 1) — is the absence of an honest end-of-step-3 terminus a real Important finding against `AGENTS.md` §Mandatory MVP UX, or correct-by-scope because step 3 belongs to `GraveRegistry` and its own retrofit already ruled on its states? **If the answer implicates `GraveSearch.php`, it is a ledgered backlog item, not a fix-wave item** — that file is excluded.
- [ ] **Step 3: Rule explicitly on `RenewalComingSoon`'s disposition** — delete, or ledger for a combined follow-up with `MarketplaceComingSoon` (see the cross-lane note; ledgering is the stated preference).
- [ ] **Step 4: Triage every finding Critical/Important/Minor** — same triage rule as all four prior retrofits. Explicitly reject as non-findings any item that merely restates known unbuilt scope.
- [ ] **Step 5: Commit the whole-module review**

## Task 3: Bounded fix wave

**Files:** Whatever Task 2's Critical/Important findings name. **Do not modify any file not named by a Critical or Important finding, and never any file in the Global Constraints exclusion list.**

- [ ] **Step 1–5:** same TDD/commit discipline as all four prior retrofits (write failing test → confirm it fails, or state BLOCKED with the reason → minimal fix → confirm it passes, or state BLOCKED → commit). This host cannot execute PHPUnit; `php -l` plus CI is the real verification path, and every report must say so rather than implying a local pass.
- [ ] **Step 6: Ledger every Minor finding verbatim**, not fixed.
- [ ] **Step 7: If any Critical/Important finding is still open after this one bounded wave, stop and get a human ruling.** Max 5 rounds; escalate at round 4.

## Task 4: Scoped re-review

**Files:** Only the files touched in Task 3. Dispatch one reviewer scoped to exactly those files; commit to `.superpowers/sdd/retrofit-renewal/task-4-rereview.md`.

## Task 5: Explicit disposition of every self-flagged gap + documentation correction

**Files:**
- Modify: `docs/planning/sprint-plan.md` — S4-T7's row (line 629) gets a **second** append-correction (do not edit original text, and do not touch PR #11's existing correction) naming `Renewal` as the row's other half, with this retrofit's real PR number, CI run ID, and finding counts, plus the `audit`-dependency correction.
- Modify: `.kiro/specs/renewal-and-grave-registry/tasks.md` and/or `design.md` — **only if** Task 2/4 found a real overclaim. Candidate identified in advance: the "all ten required states" item's PUB-030 share (see "Kiro spec self-reported gaps" above).
- Modify: `docs/planning/retrofit-backlog.md` — mark §1 item 5 done, add a §2 disposition entry following the exact convention items 1–4 already use (a prose lede naming the slice count and finding counts, then a `| Gap / finding | Disposition | Owner / reason |` table, then a "Full evidence trail" line).

- [ ] **Step 1: Give each self-flagged gap an explicit disposition** in `retrofit-backlog.md` §2 — closed with a named test, or ledgered with a named owner and reason. **Never silently dropped.** Minimum set: AC6/AC7, AC8/AC9, AC10/AC11, AC13, AC15, AC4 (re-state existing disposition), accessibility/CLS, `RenewalComingSoon`, and every Minor finding from Task 3 Step 6.
- [ ] **Step 2: sprint-plan.md append-correction** on line 629.
- [ ] **Step 3: Kiro spec correction**, if Task 2/4 warranted one.
- [ ] **Step 4: Commit.**

## Task 6: Finish the branch

- [ ] **Step 1: `php -l` every modified PHP file** (the only executable syntax gate on this host).
- [ ] **Step 2: Run `bash ci/verify-docs.sh`** and report the real result.
- [ ] **Step 3: Use `superpowers:finishing-a-development-branch`** — base branch `docs/design-system-and-planning`. Open the PR; **do not merge.**
- [ ] **Step 4: Once a PR exists and CI is green, fill in Task 5's PR/CI placeholders and push a final correcting commit.**

---

## Self-review

**Spec coverage:** AC1 and AC2 get real review (slices 1 and 2, Tasks 1–4). AC5's PUB-030 half (the "city with no published cemetery" three-part empty state) and AC16's PUB-030 half (the gate banner) get real review in slice 2; their PUB-031 halves belong to `GraveRegistry` and are excluded. AC3, AC4, AC12, AC14 belong wholly to `GraveRegistry` — out of scope, AC4's disposition re-stated not re-adjudicated. AC6–AC11, AC13, AC15 are unbuilt and get explicit disposition in Task 5, not construction.

**Placeholder scan:** the only bracketed placeholders are Task 5's PR number/CI run ID and Task 6 Step 4's fill-in — they cannot exist until the PR does, matching all four prior retrofits' accepted exception.

**Type consistency:** `RenewalJourneyStep`, `RenewalStart`, `CemeteryPublicQuery`, `LaunchCityCode`, `ModeResolver` are referenced identically everywhere they appear across tasks, and each was read at branch point `7bc3b8d` during this plan's research rather than recalled.

**Known risk this plan accepts:** the review surface is two source files, one view, and two test files. A three-agent fan-out over that surface will produce overlapping findings. That is deliberate — slice 3 is a documentation-versus-code audit that shares no files with slices 1 and 2, and the overlap between slices 1 and 2 is exactly the `RenewalJourneyStep` → `RenewalStart` contract seam this retrofit most needs a second opinion on. Task 2 dedupes.

## Verification

- [ ] Plan doc exists, committed before review work starts.
- [ ] Ledger populated with 3 task-scoped briefs + reports, plus a whole-module review that explicitly rules on the journey-terminus and `RenewalComingSoon` questions.
- [ ] A bounded fix-wave commit exists with every Critical/Important finding closed; every Minor finding visibly parked.
- [ ] Regression tests exist for whatever the fix wave touched (or a stated BLOCKED reason, never an implied pass).
- [ ] No commit in this branch touches `GraveSearch.php`, `grave-search.blade.php`, or `GraveSearchStatesTest.php` — verifiable with `git diff --stat origin/docs/design-system-and-planning...retrofit-renewal`.
- [ ] AC6–AC11, AC13, AC15, AC4, accessibility, and `RenewalComingSoon` each get an explicit disposition, not silence.
- [ ] A PR against `docs/design-system-and-planning` exists; CI is green; **it is not self-merged.**
- [ ] `sprint-plan.md`'s S4-T7 row gets a second append-correction with real PR number, CI run ID, finding counts, and the `audit`-dependency correction; PR #11's existing correction is untouched.
