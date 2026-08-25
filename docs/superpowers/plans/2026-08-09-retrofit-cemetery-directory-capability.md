# Retrofit: CemeteryDirectory + CemeteryCapability — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the `CemeteryDirectory` + `CemeteryCapability` modules (Kiro spec `cemetery-directory-and-availability`) the two-tier Superpowers SDD review they never got, close any Critical/Important findings with a bounded fix wave and real regression tests, and give explicit disposition to every self-flagged gap already on record in `tasks.md`.

**Architecture:** This is a **review-and-fix retrofit, not new-feature construction.** No new module boundary, no new seam. Work happens in an isolated worktree/branch off `docs/design-system-and-planning`, fans task-scoped review out by functional slice via `dispatching-parallel-agents`, runs one whole-module reviewer over the combined findings plus the code holistically, then applies one bounded fix wave to whatever the review confirms as Critical or Important. Minor findings get ledgered and parked, not chased.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4 (Volt-less class components), PostgreSQL 18 (CI oracle) / SQLite (local), Pest via PHPUnit, `ci/verify-docs.sh` (design-token/Blade gates).

## Global Constraints

- Never hardcode a design value (hex/px/ms/shadow) or use a Tailwind arbitrary value — `docs/design/design-system.md` §9.2, machine-enforced by `ci/verify-docs.sh` gates 2–3, 11–12.
- Every public directory read goes through `App\Domain\CemeteryDirectory\CemeteryPublicQuery` (or a helper composing it) — never a bare `Cemetery::query()`. This is the model's own documented invariant (`Cemetery.php:126-129`) and this spec's AC2 base guarantee.
- Availability intent resolves through the single `StatusIntent`/`CemeteryAvailabilityIntent` helper — components must not switch on capability strings (design.md, "Availability → visual intent (normative)" table).
- `AGENTS.md` §Documentation: do not duplicate canonical catalogue data (`LaunchCityCode`, `CemeteryType` stay the one PHP-side source).
- `AGENTS.md` §Testing: every Critical/Important fix in this retrofit gets a real regression test — CI (PostgreSQL 18) is the oracle, not a local SQLite green run (this module has a documented history of exactly this class of divergence — see Task 1's `a150a3b` background below).
- `AGENTS.md` §Infrastructure-agent execution: never report PASS for a check not executed; use BLOCKED or NOT TESTED explicitly.
- Kiro spec `cemetery-directory-and-availability` (`requirements.md`/`design.md`/`tasks.md`) is the "what to build" authority for this retrofit; this plan does not restate its ACs, it reviews shipped code against them.

---

## Current shipped state

**Modules under review:**
- `app/Domain/CemeteryDirectory/**` — `Cemetery` model, `CemeteryPublicQuery` (the one public read entry point), `CemeteryType`/`CemeteryPublicationStatus`/`LaunchCityCode` closed lists.
- `app/Domain/CemeteryCapability/**` — `CemeteryCapabilityProfile` (append-only versioned profile) + `CemeteryPackage` models, `ResolveCemeteryCapabilityProfile` action (the one write→read Action that exists), six closed-list mode enums (`AvailabilityMode`, `BookingMode`, `CertificateMode`, `MapMode`, `RegistryMode`, `VisitationMode`).
- Consumers outside the two modules: `App\Livewire\Public\Directory\{CemeteryDirectoryIndex,CemeteryDetail}` (the public routes, `/cemeteries` and `/cemeteries/{cemeterySlug}`), `App\Livewire\Public\Directory\Support\{CemeteryPresenter,CemeteryAvailabilityIntent,PublicCapabilityProjection}`, `App\Livewire\Public\HomePage`, `App\Livewire\Public\Renewal\{RenewalStart,GraveSearch}`, `App\Domain\GraveRegistry\{Models\GraveRecord,GraveRegistryPublicQuery}`.

**History (why this is the pilot):** Sprint 4's S4-T6 batch (08 Aug 2026, agent team, no plan doc, no worktree, single self-reviewed commit) shipped this module concurrently with S4-T7 (renewal). Neither batch owned `App\Domain\CemeteryDirectory`, so each independently built its own stand-in read class. That produced two real, documented incidents:

- **`ba662d1`** (08 Aug 2026) — the two stand-ins (`App\Livewire\Public\Directory\Support\CemeteryDirectoryQuery` from S4-T6, `App\Domain\Renewal\RenewalLocationQuery` from S4-T7) were merged into the current single `CemeteryPublicQuery`, all four consumers repointed, and a real bug surfaced in the process: `directory/index.blade.php`'s two `@foreach` loops destructured `launchCities()`/`types()` as `$code => $label`, which didn't match the merged class's `list<array{code,label}>` return shape. This merge was **itself an uncommitted, unreviewed batch operation** — "agent-team teammate query-merger, reviewed by me" per the commit message, i.e. one human eyeballing a diff, not the two-tier process this retrofit applies.
- **`a150a3b`** (08 Aug 2026) — CI (PostgreSQL 18, the real oracle) caught a cross-batch integration failure that no single batch could have seen alone: a directory-side test dropped `cemeteries` directly to simulate a DB failure; a same-day renewal-side migration (`2026_08_08_100000_create_grave_records_table.php`) had since added `grave_records.cemetery_id` as `restrictOnDelete` against `cemeteries`, so the pre-existing test's `DROP TABLE cemeteries` started failing with a real Postgres FK-dependency error. Fixed by drop-ordering the test, not by disabling the constraint.

Both incidents are exactly the failure mode Superpowers SDD's worktree isolation + task-scoped review exists to catch *before* merge, not after CI fails or a human eyeballs a late diff. That is the specific thing this retrofit's whole-module review (Task 4 below) must assess: **would a real two-tier SDD review, run at the time, have caught either incident earlier than it was actually caught?**

**`sprint-plan.md` S4-T6 row** (verbatim, line 626): claims dependencies "feature-gate, audit, identity" alongside this module. **Spot-checked and found inaccurate**: `grep -rn "FeatureGate\|Audit::\|IdentityAccess" app/Livewire/Public/Directory app/Domain/CemeteryDirectory app/Domain/CemeteryCapability` finds exactly one hit, and it is a docblock analogy in `PublicCapabilityProjection.php:37` ("Same instinct as `App\Platform\FeatureGate`'s resolver…") — not a real integration. The shipped module has **zero live FeatureGate/Audit/IdentityAccess wiring**: it is a fully public, unauthenticated, read-only surface with no write path (AC9's write-side Action doesn't exist yet — see Kiro gap below), so there is currently nothing to gate, audit, or authenticate. This is a documentation-accuracy finding for Task 5, not a code defect.

**Kiro spec self-reported gaps** (`tasks.md`, already honest — quoted, not summarized, so the retrofit doesn't re-litigate what's already correctly disclosed):
- AC6/AC7 — "Add optional plot source adapter interface… not started; deliberately out of S4-T1's *and* S4-T6's scope (owned by the separate, not-yet-built `plot-inventory-and-reservation` spec…)."
- AC8 — "Add stale-source monitoring and fallback… not started… AC8 is about a **plot data source** being missing or stale, which requires the adapter above to exist first."
- AC9 — "**partial**… The AC9 half is still absent: AC9 scopes *operator updates* to the operator's assigned cemetery and requires them audited. No write-side capability Action exists… That write path belongs to `admin-operations` and was **deliberately not** in this batch."
- Benchmarking (AC2/AC3/AC11) — "Benchmark directory and map queries… not started… no benchmark was written and none ran… NOT TESTED, not passing."
- Ten required UI states — 7 of 10 implemented; §6.6 (duplicate/retry-safe), §6.8 (success), §6.9 (gated fallback banner) absent, "defensible on a read-only browse surface with no mutation, no gate, and no success outcome, but recorded as absent rather than counted as done."
- Accessibility (§7) — colour-only signalling verified CI-green; "44 px touch targets and the focus ring are NOT TESTED — there is no browser, Dusk, Playwright, or Cypress harness in this repository."

**What a design-time `brainstorming` pass would have asked, had one run before S4-T6/S4-T7 started (reconstructed retrospectively — this is analysis for the plan doc, not a live session, since neither shipped-code batch nor this pilot retrofit is security/authorization-adjacent work under `AGENTS.md`'s mandatory-human-review rule):**
1. "Two batches are about to read the same `cemeteries` table from two different owning modules on the same day — who owns the read interface, and does it exist yet?" (Would have prevented the two stand-ins outright — this is the direct cause of `ba662d1`.)
2. "Is a new FK being added against a table another concurrently-running batch already has tests that DROP TABLE against?" (Would have prevented `a150a3b` — a one-line cross-batch heads-up would have surfaced the collision before CI did.)
3. "AC9 requires an audited, scoped write path — is that in this batch's scope, and if not, who owns it and when?" (Was actually asked and answered correctly — `tasks.md` already scopes this to `admin-operations` — so this one is confirmation, not a gap.)
4. "Does `sprint-plan.md`'s dependency claim (`feature-gate, audit, identity`) match what this batch is actually going to build?" (Not asked — the row's claim went uncorrected for a day until this retrofit's research found it.)

## Review scope

**In scope:**
- `app/Domain/CemeteryDirectory/**`, `app/Domain/CemeteryCapability/**` (all files).
- `app/Livewire/Public/Directory/**` (`CemeteryDirectoryIndex`, `CemeteryDetail`, and the three `Support/*` classes) — the primary consumer surface, reviewed as part of this module's seam per the Kiro design.md's own testing-seam decision ("tested at its two existing seams — the route surface… and the domain read interface").
- `resources/views/livewire/public/directory/{index,detail}.blade.php`.
- Migrations `2026_07_26_190000_create_cemeteries_table.php`, `2026_07_26_190100_create_cemetery_capability_profiles_table.php`, `2026_07_26_190200_create_cemetery_packages_table.php`, `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php`, `2026_07_26_210000_backfill_dummy_map_price_and_photo_for_seeded_cemeteries.php`.
- All 8 test files: `tests/Feature/Domain/CemeteryCapability/{CemeteryCapabilityModeClosedListTest,CemeteryCapabilityProfileSafeDefaultsTest,CemeteryPackageAvailabilityTest}.php`, `tests/Feature/Domain/CemeteryDirectory/{CemeterySeedTest,CemeteryTypeClosedListTest}.php`, `tests/Feature/Livewire/Public/Directory/{CemeteryAvailabilityIntentTest,CemeteryDetailRouteTest,CemeteryDirectoryIndexRouteTest}.php`.

**Out of scope (do not touch — belongs to a different, separately-tracked unit of work):**
- `plot-inventory-and-reservation` (unbuilt spec) — AC6/AC7 plot-source adapter. This retrofit disposes the gap explicitly (ledger it), it does not build the adapter.
- `admin-operations` — AC9's write-side capability Action, operator scoping, and audit wiring. Same treatment: dispose, don't build.
- `App\Domain\GraveRegistry\**`, `App\Domain\Renewal\**`, `App\Domain\Marketplace\**` — these consume `CemeteryPublicQuery` but are separate modules with their own place in the retrofit backlog (items 4, 6 respectively). Reviewers may note a finding that touches a call site in one of these files, but the fix (if any) stays inside this module's own public interface, not inside the consumer.
- `App\Platform\FeatureGate`/`Audit`/`IdentityAccess` — real integration is explicitly not being retrofitted here (nothing currently calls them from this module, and nothing in this module's Kiro spec requires it yet). The finding is "the `sprint-plan.md` dependency claim is inaccurate," not "wire these in."
- No new UI states, no new benchmark suite, no plot-source adapter, no operator write path get built as part of the fix wave — those are ledgered backlog items per the recipe, not retrofit-scope code.

---

## Task 1: Draft the review briefs and dispatch task-scoped review

**Files:**
- Create (ledger, git-ignored, inside the worktree): `.superpowers/sdd/retrofit-cemetery-directory-capability/task-1-brief-{schema,domain,ui,tests}.md`
- Read only: everything under "Review scope" above.

**Interfaces:**
- Produces: four independent review reports (schema/migrations, domain actions/queries, UI/Livewire+Blade, tests), each graded against `AGENTS.md`, the `cemetery-directory-and-availability` Kiro spec, and `codebase-design`'s deep-module vocabulary (module/interface/implementation/seam/adapter/depth/locality — not "component"/"service").

- [ ] **Step 1: Create the worktree and branch**

```bash
git worktree add .worktrees/retrofit-cemetery-directory-capability -b retrofit-cemetery-directory-capability origin/docs/design-system-and-planning
cd .worktrees/retrofit-cemetery-directory-capability
```

- [ ] **Step 2: Commit this plan doc first, before any review work starts**

The plan doc must already exist at `docs/superpowers/plans/2026-08-09-retrofit-cemetery-directory-capability.md` on this branch before Step 3. If it was authored on the main checkout, copy or `git cherry-pick`/`git checkout <sha> -- <path>` it into this worktree and commit:

```bash
git add docs/superpowers/plans/2026-08-09-retrofit-cemetery-directory-capability.md
git commit -m "Add retrofit plan doc: CemeteryDirectory + CemeteryCapability pilot"
```

- [ ] **Step 3: Dispatch four task-scoped review agents in parallel via `dispatching-parallel-agents`**

Each agent reviews ONLY its slice, against: (a) `AGENTS.md` in full, (b) `.kiro/specs/cemetery-directory-and-availability/{requirements,design,tasks}.md`, (c) `mattpocock-skills:codebase-design` vocabulary, (d) this plan's "Current shipped state" section above (so reviewers don't re-discover facts already established here — they verify and extend them).

- **Slice: schema/migrations** — the 5 migrations listed above, plus `CemeteryCapabilityProfile`/`CemeteryPackage`/`Cemetery` model `$fillable`/`casts()`/`booted()` blocks. Ask explicitly: does every column have a closed-list guard where the domain requires one; is the append-only versioning on `cemetery_capability_profiles` actually enforced (not just documented); are the two 08 Aug incidents (`ba662d1`, `a150a3b`) visible as risk in the current schema, i.e. is there a THIRD undiscovered FK-ordering or cross-table assumption like `a150a3b`'s?
- **Slice: domain actions/queries** — `CemeteryPublicQuery`, `ResolveCemeteryCapabilityProfile`, the six mode enums, `CemeteryType`/`CemeteryPublicationStatus`/`LaunchCityCode`. Ask explicitly: does every method that claims "published only" actually start from `Cemetery::published()`; is `findPublishedById`'s UUID guard (`CemeteryPublicQuery.php:234-249`) the only place a malformed id could reach the database, or are there other unguarded id-shaped inputs; does `ResolveCemeteryCapabilityProfile`'s safe-default fallback actually match the "safe defaults" the six enums' own default cases specify (cross-check `CemeteryCapabilityProfileSafeDefaultsTest.php` assertions against the enum defaults directly, don't trust the test's own framing).
- **Slice: UI (Livewire + Blade)** — `CemeteryDirectoryIndex`, `CemeteryDetail`, `CemeteryPresenter`, `CemeteryAvailabilityIntent`, `PublicCapabilityProjection`, both Blade views. Ask explicitly: does every availability badge resolve through `StatusIntent`/`CemeteryAvailabilityIntent` with no capability-string switch anywhere else; is the `ba662d1` `$code => $label` destructuring bug's fix (now `$option['code']`/`['label']`) the ONLY place that pattern existed, or does a similar shape-mismatch risk exist elsewhere in these two Blade files; run `bash ci/verify-docs.sh` yourself and confirm gates 1–3, 11–12 pass on this slice specifically, don't just cite the cached CI run.
- **Slice: tests** — all 8 test files listed above. Ask explicitly: does every claim in `tasks.md`'s "Ticked 08 Aug 2026 against the shipped S4-T6 code" section have a real assertion backing it (spot-check at least 3 of the cited test method names against their actual bodies, not just their names); is `CemeteryDirectoryIndexRouteTest`'s post-`a150a3b`-fix version (the one with the corrected drop order) actually still correct today, or did later migrations reintroduce the same FK-ordering risk; run the full local suite (`vendor/bin/phpunit --filter Cemetery` or equivalent) and report real PASS/FAIL counts — do not report PASS without running it.

- [ ] **Step 4: Each reviewer writes its report**

Format, one per slice, saved to `.superpowers/sdd/retrofit-cemetery-directory-capability/task-1-report-{schema,domain,ui,tests}.md`:

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
git add .superpowers/sdd/retrofit-cemetery-directory-capability/
git commit -m "Task 1: task-scoped review, 4 slices (schema, domain, ui, tests)"
```

(The `.superpowers/sdd/` path inside a worktree has its own nested `.gitignore` per `AGENTS.md` §Development methodology — verify with `git check-ignore -v .superpowers/sdd/retrofit-cemetery-directory-capability/task-1-report-schema.md` before assuming this commit does nothing; if it's ignored, the ledger lives as worktree-local files only, which is the documented intent — record the reports' key findings inline in Task 4's report instead of relying on this commit landing.)

## Task 2: Whole-module review

**Files:**
- Read: all four Task 1 reports, plus the full module (everything in "Review scope" above), read holistically rather than by slice this time.
- Create: `.superpowers/sdd/retrofit-cemetery-directory-capability/task-2-whole-module-review.md`

**Interfaces:**
- Consumes: the four Task 1 reports' findings and "Questions for the whole-module reviewer" sections.
- Produces: one consolidated, deduplicated findings list, each graded Critical/Important/Minor, PLUS an explicit written answer to the specific verification question this plan requires (see Step 2 below).

- [ ] **Step 1: Dispatch one reviewer over the whole module**

The reviewer reads all four Task 1 reports first, then the full module code, and produces one consolidated list — deduplicating any finding two slices independently raised, and resolving any "Questions for the whole-module reviewer" left by the task-scoped reviewers.

- [ ] **Step 2: The whole-module review MUST explicitly answer this question, in writing, as its own section**

> "Would a real two-tier Superpowers SDD review — worktree isolation, task-scoped review before merge, one whole-branch review before PR — run at the time S4-T6 and S4-T7 were built, have caught `ba662d1`'s two-stand-in duplication and/or `a150a3b`'s FK-ordering collision earlier than they were actually caught (a same-day human-reviewed merge commit, and a CI failure, respectively)? Answer for each incident separately, with reasoning, not just yes/no."

This is the plan's own verification criterion (see "Verification" section below) — do not skip it or answer vaguely.

- [ ] **Step 3: Triage every finding Critical / Important / Minor**

Critical: violates a `AGENTS.md` MUST/SHALL, a negative criterion in `requirements.md`, or produces incorrect data on the public surface. Important: a real bug or spec gap that isn't yet user-visible or isn't a hard rule violation. Minor: everything else (naming, minor duplication, documentation polish).

- [ ] **Step 4: Commit the whole-module review**

```bash
git add .superpowers/sdd/retrofit-cemetery-directory-capability/task-2-whole-module-review.md
git commit -m "Task 2: whole-module review, consolidated findings + ba662d1/a150a3b assessment"
```

## Task 3: Bounded fix wave

**Files:** Whatever Task 2's Critical/Important findings name — cannot be enumerated in advance since the findings don't exist yet. **Do not modify any file not named by a Critical or Important finding.**

**Interfaces:**
- Consumes: Task 2's triaged findings list.
- Produces: one commit per logically-independent fix (small findings touching the same file may be combined into one commit; do not combine fixes across unrelated findings), each with its own regression test.

- [ ] **Step 1: For each Critical/Important finding, write the failing regression test first**

The test must fail against the pre-fix code and demonstrate the exact defect the finding describes — not a restatement of the finding in prose.

- [ ] **Step 2: Run the test, confirm it fails**

```bash
vendor/bin/phpunit --filter <TestClass>::<test_method> -v
```
Expected: FAIL, for the reason the finding predicts (not an unrelated error — if it fails for a different reason, the finding was misdiagnosed; go back to Task 2's reviewer for correction before proceeding).

- [ ] **Step 3: Apply the minimal fix**

- [ ] **Step 4: Run the test, confirm it passes; run the full module test suite, confirm no regression**

```bash
vendor/bin/phpunit --filter Cemetery -v
```

- [ ] **Step 5: Commit**

```bash
git add <touched files>
git commit -m "Fix wave: <finding summary> (Task 2 finding #<n>)"
```

- [ ] **Step 6: For every Minor finding, ledger it verbatim rather than fixing it**

Append to `.superpowers/sdd/retrofit-cemetery-directory-capability/progress.md`:
```
minor (deferred, retrofit): <finding> — <why parked, e.g. "cosmetic, no spec or AGENTS.md rule violated">
```

- [ ] **Step 7: If any Critical/Important finding is still open after this one bounded wave, stop and get a human ruling**

Per the retrofit recipe: "A finding needing a second wave is adjudicated by a human, not auto-looped." Do not start a second fix wave unilaterally.

## Task 4: Scoped re-review

**Files:** Only the files touched in Task 3.

**Interfaces:**
- Consumes: Task 3's commit list (`git diff <task-2-sha>..HEAD --stat`).
- Produces: `.superpowers/sdd/retrofit-cemetery-directory-capability/task-4-rereview.md` — pass/fail per fix, confirming each Critical/Important finding from Task 2 is actually closed and no new defect was introduced by the fix itself.

- [ ] **Step 1: Dispatch one reviewer scoped to exactly the touched files**

Give it Task 2's findings list and Task 3's diff. It confirms each fix closes its finding and did not introduce a new one — it does not re-review the whole module again.

- [ ] **Step 2: Commit the re-review**

```bash
git add .superpowers/sdd/retrofit-cemetery-directory-capability/task-4-rereview.md
git commit -m "Task 4: scoped re-review of fix wave"
```

## Task 5: Explicit disposition of every self-flagged gap + the sprint-plan.md dependency-claim finding

**Files:**
- Modify: `docs/planning/sprint-plan.md` (S4-T6 row, line 626 — append-correction only, per this file's established convention; do not edit the existing row text).
- Modify: `.kiro/specs/cemetery-directory-and-availability/tasks.md` — only if Task 2/4 closed anything the tasks.md currently marks `[ ]`; otherwise leave as-is (it is already honestly self-reported).
- Create (backlog record, not code): entries inside Task #10's future backlog doc (see this plan's parent session context — recorded separately, not duplicated here per `AGENTS.md` §Documentation).

**Interfaces:**
- Consumes: Task 2/4's final findings state, plus the six self-flagged gaps already quoted in this plan's "Kiro spec self-reported gaps" section.
- Produces: one append-correction paragraph on `sprint-plan.md`'s S4-T6 row.

- [ ] **Step 1: Give each of the six self-flagged gaps an explicit disposition**

For each of: (a) AC6/AC7 plot-source adapter, (b) AC8 stale-source monitoring, (c) AC9 write-side scoping/audit, (d) benchmarking, (e) 3 missing UI states (§6.6/§6.8/§6.9), (f) 44px-target/focus-ring accessibility — write one line each: either "closed by this retrofit, evidence: `<test file>::<method>`" or "ledgered as backlog item, owner: `<module/spec that owns it>`, reason: `<why it's out of this retrofit's scope>`." Every one of these six is expected to land in the second bucket (they were already correctly scoped to other modules/specs before this retrofit started) — the retrofit's job is to make that disposition explicit and traceable, not to build the missing pieces.

- [ ] **Step 2: Correct the `sprint-plan.md` dependency-claim finding**

Append directly after line 626 (do not edit the row itself):

```markdown
**Correction, 09 Aug 2026 (retrofit finding): the "feature-gate, audit, identity" dependency claim in this row is inaccurate as shipped.** `grep -rn "FeatureGate\|Audit::\|IdentityAccess" app/Livewire/Public/Directory app/Domain/CemeteryDirectory app/Domain/CemeteryCapability` finds one hit, a docblock analogy (`PublicCapabilityProjection.php:37`), not a real integration. The shipped module is a fully public, unauthenticated, read-only surface with no write path, so there is currently nothing to gate, audit, or authenticate against those three Platform modules — this is expected given AC9's write path (the piece that would need them) is explicitly out of scope until `admin-operations` builds it. Retrofitted via `docs/superpowers/plans/2026-08-09-retrofit-cemetery-directory-capability.md`, PR #<fill in from Task 6>, <N> Critical + <N> Important findings, <N> Minor ledgered. CI run: <fill in>.
```

- [ ] **Step 3: Commit**

```bash
git add docs/planning/sprint-plan.md .kiro/specs/cemetery-directory-and-availability/tasks.md
git commit -m "Task 5: disposition of self-flagged gaps; correct sprint-plan.md S4-T6 dependency claim"
```

## Task 6: Finish the branch

**Files:** None (process task).

- [ ] **Step 1: Run the full test suite**

```bash
vendor/bin/phpunit
```

- [ ] **Step 2: Run `ci/verify-docs.sh`**

```bash
bash ci/verify-docs.sh
```

- [ ] **Step 3: Use `superpowers:finishing-a-development-branch`**

Base branch: `docs/design-system-and-planning`. Follow that skill exactly — verify tests, detect worktree environment, present the merge/PR/keep menu, execute the chosen option.

- [ ] **Step 4: Once a PR exists and CI is green, fill in Task 5 Step 2's `<fill in>` placeholders with the real PR number and CI run ID, and push a final commit correcting them**

This is the one deliberate exception to "no placeholders" in a plan doc: the PR number and CI run ID cannot exist before the PR does. Everything else in this plan is concrete.

---

## Self-review

**Spec coverage:** Every AC in `cemetery-directory-and-availability/requirements.md` is either (a) already shipped and now getting real review (AC1–AC5, AC10–AC12 via Tasks 1–4), or (b) already correctly identified as out of this module's current scope and getting explicit disposition (AC6–AC9 partial, via Task 5). AC10 (admin management without deployment) is not built anywhere in this module and is not claimed as built by `tasks.md` — it belongs to `admin-operations`; Task 5 Step 1 should ledger it explicitly alongside the other five if the whole-module reviewer confirms it's genuinely absent (Task 1's domain-slice reviewer should check for a Filament resource touching `Cemetery`/`CemeteryCapabilityProfile` and report back — flagged here since research did not check Filament).

**Placeholder scan:** The only bracketed placeholders are in Task 5 Step 2 and Task 6 Step 4, both explicitly justified above (values that don't exist until the PR is opened).

**Type consistency:** `CemeteryPublicQuery`, `ResolveCemeteryCapabilityProfile`, `CemeteryAvailabilityIntent`, `PublicCapabilityProjection` are referenced identically (same FQCN, same method names) everywhere they appear across tasks — verified against the actual current source during research, not assumed.

---

## Verification

Matches the parent plan's (`docs/superpowers/plans/` retrofit program) stated bar for the pilot:

- [ ] Plan doc exists, committed before review work starts (Task 1 Step 2).
- [ ] `.superpowers/sdd/retrofit-cemetery-directory-capability/progress.md` (or the four task-N-report files, if the nested `.gitignore` prevents the ledger from landing in the commit — see Task 1 Step 5's caveat) is populated with task-scoped briefs + reports + findings, plus a separate whole-module review.
- [ ] The whole-module review (Task 2 Step 2) explicitly answers whether it would have caught `ba662d1` and `a150a3b`, with reasoning per incident.
- [ ] A bounded fix-wave commit exists (Task 3) with every Critical/Important finding closed; every Minor finding visibly parked in the ledger.
- [ ] Regression tests exist for whatever the fix wave touched (Task 3 Steps 1–5).
- [ ] The AC6–AC9 gaps and the benchmarking gap each get an explicit disposition, not silence (Task 5 Step 1).
- [ ] A PR against `docs/design-system-and-planning` exists and merges (Task 6).
- [ ] `sprint-plan.md`'s S4-T6 row gets an append-correction with the real CI run ID, PR number, and finding counts (Task 5 Step 2, finalized in Task 6 Step 4).
