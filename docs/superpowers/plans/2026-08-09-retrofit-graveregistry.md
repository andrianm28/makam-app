# Retrofit: GraveRegistry — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the `GraveRegistry` module (part of Kiro spec `renewal-and-grave-registry`, AC3/AC4/AC5/AC12/AC13/AC14/AC16) the two-tier Superpowers SDD review it never got, close any Critical/Important findings with a bounded fix wave and real regression tests, and give explicit disposition to every self-flagged gap already on record (AC4's NOT TESTED latency claim, AC13's whole unbuilt import feature, the gate-check-locus footgun, accessibility).

**Architecture:** Review-and-fix retrofit, matching the recipe both prior retrofits this session exercised (`CemeteryDirectory`+`CemeteryCapability` pilot, `Faq`). Work happens in an isolated worktree/branch off `docs/design-system-and-planning`, fans task-scoped review out by functional slice, runs one whole-module reviewer over the combined findings plus the code holistically, then applies one bounded fix wave.

**Scope note, stated up front:** `GraveRegistry` is a pure read/search domain — `app/Domain/GraveRegistry/Actions/` is empty (`.gitkeep` only). No write-side Actions, no Filament admin resource (the bulk-import UI is AC13, unbuilt, Sprint 13 — do not build it in this retrofit). This is closer in shape to the pilot (read-only public surface) than to `Faq` (write-lifecycle + admin CRUD) — four review slices, not five. The spec (`renewal-and-grave-registry`) also covers the separate `Renewal` module (city/cemetery selection, fee, payment, confirmation — journey steps 1, 2, 4-6), which is its own, later retrofit-backlog item (§1 item 5) — this plan's Review scope is GraveRegistry's own slice only; do not review `Renewal`'s files even though they share one Kiro spec and one consumer (`GraveSearch.php` sits on the boundary — see Review scope below for exactly how that's handled).

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4, PostgreSQL 18 (CI oracle, and the only engine that runs the real `pg_trgm` fuzzy search — see below) / SQLite (local, substring-match fallback only), Pest via PHPUnit, `ci/verify-docs.sh`.

## Global Constraints

- Never hardcode a design value or use a Tailwind arbitrary value — `ci/verify-docs.sh` gates 2/3/11/12.
- Every public read goes through `GraveRegistryPublicQuery` — its own doc block states this as the module's whole contract ("the ONE public read entry point... nothing outside `app/Domain/GraveRegistry/**` touches the model"). Verify this invariant actually holds, don't assume it transferred from the pilot/Faq.
- `AGENTS.md` §Testing: every Critical/Important fix gets a real regression test — CI (PostgreSQL 18) is the oracle. **This module has a stronger-than-usual PostgreSQL dependency**: fuzzy search (`pg_trgm`/`similarity()`) exists only on PostgreSQL — a local SQLite run "does not exercise AC3's fuzzy behaviour at all" per the class's own doc block. Any reviewer/implementer without PostgreSQL access must say so explicitly, not silently trust a SQLite-only pass.
- `AGENTS.md` §Infrastructure-agent execution: never report PASS for a check not executed; use BLOCKED or NOT TESTED explicitly. This host cannot run PHP locally (no `vendor/`, `CLAUDE.md` forbids `composer install`).
- `AGENTS.md` §Documentation: do not duplicate canonical catalogue data (`GraveRecordAccessMode`, `GraveRecordSource` stay the one PHP-side source).
- Kiro spec `renewal-and-grave-registry` (`requirements.md`/`design.md`/`tasks.md`) is the "what to build" authority for GraveRegistry's own ACs; this plan does not restate them.
- **A citation-accuracy note, learned from this session's two prior retrofits**: verify every claimed quote against the real committed file before including it in a review report. `design.md` for this spec is 50 lines (`## Search`/`## Data`/`## Import`/`## Duplicate prevention`/`## Privacy`) with one commit in its history (`05f6f4d`, the baseline import) — it has no "Open decisions" section. A real, substantively identical discussion of the gate-check-locus tradeoff exists, but it lives in `GraveRegistryPublicQuery.php:23-33`'s own class doc block, not in `design.md` — cite it there.

---

## Current shipped state

**Modules under review:**
- `app/Domain/GraveRegistry/Models/GraveRecord.php` — the Eloquent model for `grave_records`. Its own doc block/migration also confirm 7 further tables this spec names (`grave_import_batches`, `grave_import_rows`, `grave_import_errors`, `renewals`, `renewal_quotes`, `renewal_external_markings`, `reminder_deliveries`) do **not exist** — deferred to Sprint 13 alongside AC13's import feature. Confirm this is still accurate; do not build any of them.
- `app/Domain/GraveRegistry/GraveRegistryPublicQuery.php` — the module's one public read entry point. Never returns a `GraveRecord` model, only `GraveRecordProjection` value objects, so a caller cannot reach a column the configured access mode withholds.
- `app/Domain/GraveRegistry/GraveRecordProjection.php` — per-access-mode field reduction. `heir_contact_reference` has no property under any mode — structurally unexposable via this class, not merely unrendered by a caller's choice.
- `app/Domain/GraveRegistry/GraveSearchCriteria.php` — the three search inputs (name/block/death-date), cemetery-scoped.
- `app/Domain/GraveRegistry/GraveSearchOutcome.php` — holds `openResults`/`restrictedCount` as independently-readable facts specifically so a view cannot collapse "no result" and "privacy-limited" into one message (AC5's three-distinct-empty-states requirement). Gate-closed is deliberately *not* representable here — see the gate-check-locus note below.
- `app/Domain/GraveRegistry/GraveRecordAccessMode.php` — closed list (`open`/`limited`/`closed`), defaults to `closed` (safe default), unknown mode rejected.
- `app/Domain/GraveRegistry/GraveRecordSource.php` — closed list for provenance (`operator_import`, etc.).
- `app/Domain/GraveRegistry/GraveNameNormalizer.php` — shared normalization applied both at write time (`GraveRecord::booted()`) and query time, for trigram symmetry. Explicitly does not do honorific-stripping/transliteration/stemming — an open product question, not a bug, per its own doc block.
- `app/Domain/GraveRegistry/Actions/` — empty (`.gitkeep` only). No write-side Actions exist. Confirm this is correct-by-spec (no GraveRegistry-owned write path is required by AC3/AC4/AC5/AC12/AC14/AC16 — AC13's import is the only write path this spec names, and it's unbuilt).

**Migrations (2):** `2026_08_08_100000_create_grave_records_table.php` (creates the `pg_trgm` extension + GIN trigram index, `restrictOnDelete()` FK to `cemeteries`), `2026_08_08_100010_seed_example_grave_records.php` (14 fictional `Contoh...`-prefixed rows spanning all 3 access modes plus 1 negative fixture against a draft cemetery).

**Consumer:** exactly one file outside the module reaches in — `app/Livewire/Public/Renewal/GraveSearch.php` (the journey-step-3 screen). This is the module's real seam and its own review boundary — see "Review scope" below for exactly how much of `GraveSearch.php` is in scope.

**No Filament admin resource exists.** The bulk-import UI is AC13, unbuilt, Sprint 13.

**History (why this module, and its real incident record):** S4-T7 (08 Aug 2026, "agent-team" batch, same now-superseded batch process as every module retrofitted this session) shipped this alongside the Renewal journey shell. Two real incidents from that day, both worth the whole-module review's attention:

- **The commit immediately before this batch's own fix, `604dd1f`** ("Wave 1 (final part): wire renewal skeleton; fix its stale stub test too") — **not GraveRegistry-domain-specific**, but worth citing as process context: it fixed `HomePageRouteTest`'s weak `assertSee('Perpanjangan Makam')` assertion, which matched the nav label on *any* page (via `<x-mk.header>`) rather than the real renewal screen — the same shape of defect as a marketplace fix earlier the same day. The commit message states plainly this integration wave had **no automated two-tier review** — "the two review subagents this integration would normally use both failed on the team's API session limit... I read the load-bearing files directly against the same checklist," i.e. one human eyeballing a checklist. The fix itself reads complete and correct; it implies the original test was never asserting anything false, just too weak to distinguish the real page from any page sharing the same header. Context for Task 2's whole-module review, not a GraveRegistry-code finding to re-litigate.
- **`a150a3b`, five minutes after `604dd1f` the same day** — a real CI failure (751 passed, 1 failed), and the exact incident the pilot retrofit (`CemeteryDirectory`) already analyzed from the *other* side (see `docs/planning/retrofit-backlog.md` §2's `cemetery-directory-and-availability` entry). `CemeteryDirectoryIndexRouteTest`'s pre-existing test dropped `cemeteries` directly to simulate a DB failure, written before this module's own migration (`2026_08_08_100000_create_grave_records_table.php`) added a `restrictOnDelete()` FK from `grave_records.cemetery_id` to `cemeteries` — so the pre-existing test's `DROP TABLE cemeteries` started failing on the new FK. Fixed by drop-ordering the test, not by weakening the constraint. **This retrofit's schema-slice reviewer should check whether the FK-ordering bug class has recurred a third time** anywhere in this module's own tests or migrations, the same check the pilot and `Faq` retrofits both ran (the pilot found a live third instance; `Faq` found none).

**`sprint-plan.md` S4-T7 row** (verbatim, line 629): "✅ Done (08 Aug 2026, agent team), CI green — run `31248602859`, commit `a150a3b`. AC1–AC3, AC5, AC14 shipped at `/perpanjangan` + `/perpanjangan/cari`... **Partial:** only journey steps 1–3 have screens; steps 4–6 (fee, payment, confirmation) are Sprint 13. **AC4 (< 500 ms at 100k records) is NOT TESTED and not passing** — nothing measures latency, no 100k-row fixture exists." Already honest and specific — this retrofit's Task 5 correction (if any) should be narrow: confirm the CI run ID is real (unlike `Faq`'s S4-T2 row, this one already cites one) and add the retrofit's own finding counts, not restate what's already accurately disclosed.

**Kiro spec self-reported gaps** (`tasks.md`, `requirements.md`, quoted not summarized):
- **AC4** ("< 500 ms at 100,000 records"): "NOT TESTED and is not passing — nothing in this batch measures latency and nothing loads 100k rows." The one `assertLessThan` in `GraveRecordTrigramSearchTest` bounds a similarity *score*, not a *duration* — the batch's own tasks.md explicitly warns against misreading it as a benchmark. Same class of gap as the pilot's benchmarking finding — likely stays ledgered (no load-testing harness exists in this repo either), but confirm nothing has changed before assuming that.
- **AC13** ("async 10,000-row import"): "not started." A whole unbuilt feature, not a retrofit-fixable gap — out of scope, ledger only.
- **Accessibility/CLS**: "NOT TESTED... no browser, Dusk, Playwright, or Cypress harness exists in this repository" — the same program-level gap every prior retrofit this session has independently confirmed. Re-verify it's still true (cheap, one grep), don't just inherit the claim.
- **The gate-check-locus tradeoff** (real, but misattributed in earlier research for this plan — corrected per the Global Constraints note above): `GraveRegistryPublicQuery.php:23-33`'s own doc block states the class does **not** check `G-DATA-01` (the data-availability gate) itself — "That check belongs to the screen, before it decides whether to run a search at all... A caller that skips the gate check gets a working search, which is a real footgun; it is accepted here rather than duplicating gate resolution into the read path, because `App\Platform\FeatureGate\ModeResolver` is documented as 'the ONE place that pairs a mode enum with its backing gate id'... Flagged in this batch's report rather than resolved silently." This is AC16's enforcement locus ("WHILE the data gate is closed THE SYSTEM SHALL disable the search/reminder feature with an explanation") — the query doesn't enforce it, the screen (`GraveSearch.php`) does. **This retrofit's domain-lifecycle-equivalent reviewer must verify directly**: does `GraveSearch.php` actually check the gate before calling `GraveRegistryPublicQuery`, right now? If yes, is that the *only* caller (today, yes — confirmed single-consumer) and is the risk therefore latent-but-real for exactly the reason the doc block names (a future second caller could skip it)? Task 2 rules on disposition — likely ledger (the doc block's own reasoning against duplicating gate resolution into the read path is sound), but confirm the current single caller genuinely checks the gate before treating this as low-risk.

**What a design-time `brainstorming` pass would have asked, had one run before S4-T7 started** (reconstructed retrospectively, per the retrofit recipe):
1. "Two batches (`renewal` and `grave registry`, or `grave registry` and `cemetery directory`) are both adding FKs against or dropping `cemeteries` on the same day — has anyone checked for a collision?" (Would have prevented `a150a3b` outright — the same lesson the pilot retrofit's own reconstructed brainstorming question drew for the *other* side of this exact incident.)
2. "`GraveRegistryPublicQuery` deliberately doesn't check the data gate — is its one real caller (`GraveSearch.php`) actually checking it, and what happens the day a second caller is added without checking?" (Was actually asked and answered in the code's own doc block — flagged, not silently resolved — so this is confirmation of good practice, not a gap.)
3. "AC4 requires <500ms at 100k records — does anything in this repo have a load-testing harness, or is this AC unverifiable as written until one exists?" (Not asked; the gap was discovered only when the batch tried to write the test and couldn't.)

## Review scope

**In scope:**
- `app/Domain/GraveRegistry/**` (all files).
- `app/Livewire/Public/Renewal/GraveSearch.php` — **but only the seam**: how it calls `GraveRegistryPublicQuery`, whether it checks the gate before calling, and how it renders the three `GraveSearchOutcome` states. Do **not** review `GraveSearch.php`'s own journey-shell concerns (step navigation, session/draft handling shared with `RenewalStart.php`) — that's `Renewal`'s own future retrofit (`docs/planning/retrofit-backlog.md` §1 item 5).
- `resources/views/livewire/public/renewal/grave-search.blade.php` (or wherever `GraveSearch`'s view lives — confirm the real path) — same seam-only scope as above.
- The 2 migrations listed above.
- All 5 GraveRegistry-scoped test files: `tests/Feature/Domain/GraveRegistry/{GraveRegistryPublicQueryTest,GraveRecordTrigramSearchTest,GraveRecordSeedTest}.php`, `tests/Unit/Domain/GraveRegistry/{GraveRecordAccessModeTest,GraveNameNormalizerTest}.php` (confirm exact paths — reconstructed from research, verify against the real `tests/` tree first).
- `tests/Feature/Livewire/Public/Renewal/GraveSearchStatesTest.php` — the negative-space UI-state tests, since they assert exactly the seam this plan puts in scope (`test_the_privacy_limited_state_never_says_the_record_was_not_found`, `test_the_gate_closed_state_never_implies_the_record_does_not_exist`, `test_a_search_backend_failure_is_never_reported_as_not_found`, `test_the_privacy_limited_state_discloses_no_withheld_name`).

**Out of scope (do not touch — belongs to a different, separately-tracked unit of work):**
- `Renewal`'s own journey-shell files (`RenewalStart.php`, `RenewalJourneyStep`, journey steps 1/2/4-6, `tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php`, `tests/Unit/.../RenewalJourneyStepTest.php`) — `docs/planning/retrofit-backlog.md` §1 item 5, its own retrofit.
- AC13's import feature (`grave_import_batches`/`grave_import_rows`/`grave_import_errors` tables, any Filament resource) — entirely unbuilt, Sprint 13. This retrofit disposes the gap explicitly (ledger it), it does not build it.
- AC4's load-testing/benchmark harness — no owner module yet, same class of gap the pilot retrofit ledgered for `CemeteryDirectory`. Ledger, don't build.
- Accessibility browser/axe harness — program-level gap, out of scope for a single-module retrofit (matches every prior retrofit this session).
- `App\Platform\FeatureGate\ModeResolver` itself — real integration already exists and is correctly not duplicated per the module's own documented reasoning; this retrofit verifies the one real call site, it does not modify the gate-resolution module.

---

## Task 1: Draft the review briefs and dispatch task-scoped review

**Files:**
- Create (ledger, git-ignored, inside the worktree): `.superpowers/sdd/retrofit-graveregistry/task-1-brief-{schema,domain,ui-seam,tests}.md`
- Read only: everything under "Review scope" above.

**Interfaces:**
- Produces: four independent review reports (schema/migrations, domain query/projection logic, UI seam, tests), each graded against `AGENTS.md`, the `renewal-and-grave-registry` Kiro spec (GraveRegistry's own ACs only), and `codebase-design`'s deep-module vocabulary.

- [ ] **Step 1: Create the worktree and branch**

```bash
git worktree add .worktrees/retrofit-graveregistry -b retrofit-graveregistry origin/docs/design-system-and-planning
cd .worktrees/retrofit-graveregistry
```

- [ ] **Step 2: Commit this plan doc first, before any review work starts**

```bash
git add docs/superpowers/plans/2026-08-09-retrofit-graveregistry.md
git commit -m "Add retrofit plan doc: GraveRegistry"
```

- [ ] **Step 3: Dispatch four task-scoped review agents in parallel via `dispatching-parallel-agents`**

Each agent reviews ONLY its slice, against: (a) `AGENTS.md` in full, (b) `.kiro/specs/renewal-and-grave-registry/{requirements,design,tasks}.md` in full, (c) `mattpocock-skills:codebase-design` vocabulary, (d) this plan's "Current shipped state" section (verify and extend, don't re-discover).

- **Slice: schema/migrations** — the 2 migrations, `GraveRecord.php`'s `$fillable`/`casts()`/`booted()` block. Ask explicitly: does the closed-list guard on `access_mode`/`source` actually hold at the model seam; does the seed migration bypass `booted()` via `DB::table()->insert()` (the exact bug class both prior retrofits found — check if it recurs here, and if so whether the written values are still structurally guarded by closed-list constants the way `Faq`'s was, or a hand-typed literal the way the pilot's schema reviewer flagged as a real risk elsewhere); is there a third instance of the `a150a3b` FK-ordering bug class anywhere in this module's own tests/migrations (check every test that drops/truncates `cemeteries` or `grave_records` directly); is the `pg_trgm` GIN index actually the right shape for `GraveRegistryPublicQuery`'s real query patterns (read that class's WHERE/ORDER BY clauses and check the index covers them).
- **Slice: domain query/projection** — `GraveRegistryPublicQuery.php`, `GraveRecordProjection.php`, `GraveSearchCriteria.php`, `GraveSearchOutcome.php`, `GraveRecordAccessMode.php`, `GraveRecordSource.php`, `GraveNameNormalizer.php`. Ask explicitly: does `GraveRegistryPublicQuery` genuinely never return a bare `GraveRecord` model to any caller (the class's own headline invariant); is `heir_contact_reference` genuinely unreachable via `GraveRecordProjection` under every access mode, verified by reading the class, not assumed from its doc comment; does `GraveSearchOutcome`'s design actually make it structurally impossible to collapse "no result" and "privacy-limited" into one message (AC5), or is that only true by caller discipline; **the gate-check-locus question from "Current shipped state" above — verify directly**: does `GraveSearch.php` actually check the gate before calling into this module, right now, in the real shipped code (not the doc block's claim about intent)?
- **Slice: UI seam** — `GraveSearch.php` (seam-only, per Review scope's exclusion of journey-shell concerns) and its Blade view. Ask explicitly: does every array/value-object crossing the Livewire→Blade seam have a precise shape (the exact defect class both prior retrofits found live instances of); does the view correctly render all three `GraveSearchOutcome` states as genuinely distinct (not just differently-worded versions of the same template branch); run `bash ci/verify-docs.sh` and confirm gates 1-3, 11-12 pass on this slice specifically.
- **Slice: tests** — all GraveRegistry-scoped domain/unit tests plus `GraveSearchStatesTest.php`. Ask explicitly: does every claim in `tasks.md`'s "done" sections have a real assertion backing it (spot-check at least 3 test method names against their actual bodies, matching both prior retrofits' methodology); does `GraveRecordTrigramSearchTest`'s PostgreSQL-guard actually skip correctly on SQLite rather than silently passing a weaker check; run the full local suite if possible (state BLOCKED if not — this host has no `vendor/`) and report real PASS/FAIL counts.

- [ ] **Step 4: Each reviewer writes its report**

Format, one per slice, saved to `.superpowers/sdd/retrofit-graveregistry/task-1-report-{schema,domain,ui-seam,tests}.md`:

```markdown
# Task 1 report — <slice>

## Findings
- [Critical|Important|Minor] <file:line> — <what's wrong> — <why it matters, citing the AGENTS.md/spec rule violated>

## Confirmed correct (worth stating, not just silence)
- <thing that was checked and holds>

## Questions for the whole-module reviewer
- <anything that needs cross-slice context to judge>
```

- [ ] **Step 5: Commit the four briefs + four reports to the ledger**

```bash
git add .superpowers/sdd/retrofit-graveregistry/
git commit -m "Task 1: task-scoped review, 4 slices (schema, domain, ui-seam, tests)"
```

## Task 2: Whole-module review

**Files:**
- Read: all four Task 1 reports, plus the full module (everything in "Review scope" above), read holistically.
- Create: `.superpowers/sdd/retrofit-graveregistry/task-2-whole-module-review.md`

- [ ] **Step 1: Dispatch one reviewer over the whole module** — consolidate, dedupe, resolve every "Questions for the whole-module reviewer."
- [ ] **Step 2: Rule explicitly on the gate-check-locus disposition** — given the domain-slice reviewer's direct verification of whether `GraveSearch.php` currently checks the gate, decide: ledger as an accepted, documented tradeoff (the class's own reasoning against duplication is sound), or does the single real caller's current behavior itself need a fix?
- [ ] **Step 3: Triage every finding Critical/Important/Minor** — same triage rule as both prior retrofits.
- [ ] **Step 4: Commit the whole-module review**

## Task 3: Bounded fix wave

**Files:** Whatever Task 2's Critical/Important findings name. **Do not modify any file not named by a Critical or Important finding.**

- [ ] **Step 1-5**: same TDD/commit discipline as both prior retrofits (write failing test → confirm fails or state BLOCKED → minimal fix → confirm passes → commit).
- [ ] **Step 6: Ledger every Minor finding verbatim**, not fixed.
- [ ] **Step 7: If any Critical/Important finding is still open after this one bounded wave, stop and get a human ruling.**

## Task 4: Scoped re-review

**Files:** Only the files touched in Task 3. Dispatch one reviewer scoped to exactly those files; commit to `.superpowers/sdd/retrofit-graveregistry/task-4-rereview.md`.

## Task 5: Explicit disposition of every self-flagged gap + sprint-plan.md correction

**Files:**
- Modify: `docs/planning/sprint-plan.md` — S4-T7's row (line 629) gets an append-correction (do not edit original text) with this retrofit's real PR number, CI run ID, and finding counts. Its existing CI run ID (`31248602859`) and the AC4 NOT TESTED disclosure stay as-is — this retrofit adds to the record, it does not restate what's already accurate.
- Modify: `.kiro/specs/renewal-and-grave-registry/tasks.md` — only if Task 2/4 closed anything currently marked `[ ]` that belongs to GraveRegistry's own ACs (not Renewal's).
- Modify: `docs/planning/retrofit-backlog.md` — mark item 4 done, add a §2 disposition entry for every self-flagged gap (AC4 benchmark, AC13 import, gate-check-locus, accessibility).

- [ ] **Step 1: Give each self-flagged gap an explicit disposition** in `retrofit-backlog.md` §2.
- [ ] **Step 2: sprint-plan.md correction-append** on line 629.
- [ ] **Step 3: Commit.**

## Task 6: Finish the branch

- [ ] **Step 1: Run the full test suite** (via CI push — this host cannot run it locally; note this module's stronger-than-usual PostgreSQL dependency for the fuzzy-search tests specifically).
- [ ] **Step 2: Run `ci/verify-docs.sh`.**
- [ ] **Step 3: Use `superpowers:finishing-a-development-branch`** — base branch `docs/design-system-and-planning`.
- [ ] **Step 4: Once a PR exists and CI is green, fill in Task 5's PR/CI placeholders and push a final correcting commit.**

---

## Self-review

**Spec coverage:** AC3/AC5/AC14/AC16 get real review (Tasks 1-4); AC4 and AC13 get explicit disposition of an already-self-flagged gap (Task 5); AC12 (grave record fields) is reviewed as part of the domain slice's model check. AC1/2/6-11/15 belong to `Renewal`, explicitly out of scope.

**Placeholder scan:** only bracketed placeholders are Task 5's PR number/CI run ID (don't exist until the PR does) and Task 6 Step 4's fill-in — matching both prior retrofits' accepted exception.

**Type consistency:** `GraveRegistryPublicQuery`, `GraveRecordProjection`, `GraveSearchOutcome`, `GraveRecordAccessMode` referenced identically everywhere they appear across tasks — verified against the actual current source during this plan's research and this plan's own direct verification of the gate-check-locus quote (corrected from an initial misattribution to `design.md` before this plan was written, not after — see the Global Constraints note).

## Verification

- [ ] Plan doc exists, committed before review work starts.
- [ ] Ledger populated with 4 task-scoped briefs+reports+findings, plus a whole-module review that explicitly rules on the gate-check-locus disposition.
- [ ] A bounded fix-wave commit exists with every Critical/Important finding closed; every Minor finding visibly parked.
- [ ] Regression tests exist for whatever the fix wave touched.
- [ ] AC4, AC13, gate-check-locus, and accessibility each get an explicit disposition, not silence.
- [ ] A PR against `docs/design-system-and-planning` exists and merges.
- [ ] `sprint-plan.md`'s S4-T7 row gets an append-correction with real PR number, CI run ID, and finding counts.
