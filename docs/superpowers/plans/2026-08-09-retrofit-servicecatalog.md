# Retrofit: ServiceCatalog — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the `ServiceCatalog` module (Kiro spec `package-and-service-bundles`, AC1–AC8) the two-tier Superpowers SDD review it never got, close any Critical/Important findings with a bounded fix wave and real regression tests, and give explicit disposition to every self-flagged gap already on record (AC8's unbuilt admin editor, AC3/AC4's quote-expansion half, the uncatalogued publish/revise event, the discontinued-item-on-active-bundle hole `design.md` names but does not design around).

**Architecture:** Review-and-fix retrofit, matching the recipe the four prior retrofits this session exercised (`CemeteryDirectory`+`CemeteryCapability` pilot, `IdentityAccess/Mfa`+`Reauthentication`, `Faq`, `GraveRegistry`). Work happens in an isolated worktree/branch off `docs/design-system-and-planning`, fans task-scoped review out by functional slice, runs one whole-module reviewer over the combined findings plus the code holistically, then applies one bounded fix wave.

**Scope note, stated up front:** `ServiceCatalog` is backend/Actions-only. It owns **no** Blade view, **no** Livewire component, and **no** Filament resource — verified directly (`grep -rn "ServiceCatalog" resources/ app/Filament/` returns nothing; `app/Filament` contains only `Admin/Resources/FaqArticles/**`). AC8 ("present inclusions, exclusions, and additional charges clearly in the UI") and `tasks.md`'s "Build admin editor with publish workflow" are therefore **deliberately unbuilt, not defects** — `design.md`'s "Not covered, deliberately" section already assigns the admin editor away from this spec, and `retrofit-backlog.md`'s pattern for such gaps (see §2's AC9/AC10 rows for the pilot) is to ledger them with a named owner. **This retrofit ledgers AC8; it does not build any UI.** The sibling `Marketplace` module — the other half of `retrofit-backlog.md` §1 item 7 — is a *separate* retrofit unit running concurrently in its own worktree; do not touch `app/Domain/Marketplace/**` or `app/Livewire/Public/Marketplace/**`.

**Scope refinement discovered while writing this plan (not in the Track A lane brief):** the lane brief says "no UI exists anywhere for it," which is true of ServiceCatalog-*owned* UI but understates the module's real reach. `ServiceCatalog` has **one live read seam into another module's UI**: `app/Livewire/Public/Booking/BookingWizard.php:394` calls `ServiceCatalogQuery::allActive()` to render booking Step 4's service picker, and `app/Domain/Booking/Actions/SaveBookingDraftStep.php` + `app/Domain/Booking/BookingDraftQuery.php` consume `ServiceCode` / `ServiceDefinition` directly. That seam is **in scope, seam-only** — exactly the way `GraveRegistry`'s retrofit scoped `GraveSearch.php`. Booking's own wizard concerns (step navigation, draft persistence, autosave) are **out of scope**; they belong to `public-booking-wizard` / `booking-and-order-orchestration`.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL 18 (CI oracle) / SQLite (local), Pest via PHPUnit, `ci/verify-docs.sh`.

## Global Constraints

- `AGENTS.md` §Documentation: "Do not duplicate canonical catalog data in multiple hand-maintained documents or code locations." `docs/product/service-catalog.md` is the canonical catalogue; `App\Domain\ServiceCatalog\ServiceCode` is its **single** application-layer representation. Nothing may restate those 12 strings a third time.
- `AGENTS.md` §Testing: every Critical/Important fix gets a real regression test — CI (PostgreSQL 18) is the oracle. Every traceability item marked `Covered` needs test evidence.
- `AGENTS.md` §Infrastructure-agent execution: never report `PASS` for a check that was not executed; use `BLOCKED` or `NOT TESTED` explicitly. **This host cannot run PHP tests** — `vendor/` is empty and `CLAUDE.md` forbids `composer install`/`npm run build` here. Local verification is `php -l` and `bash ci/verify-docs.sh` only; the real suite runs in CI.
- `AGENTS.md` §Infrastructure-agent execution: human review is mandatory before security, authorization, financial, privacy, or **destructive-migration** changes. Every ServiceCatalog table is already deployed to `dev.makam.co.id` (26 Jul 2026 deployment, `sprint-plan.md` Deployments log) — **any new migration against them is a human-ruling item, not a fix-wave item.** This is the same disposition the pilot and `Faq` retrofits both reached for their DB-constraint findings; expect to reach it again here rather than treating it as new.
- `AGENTS.md` §Domain and financial invariants: prices are money. `RecordServiceDefinitionPriceVersion` takes `string $amount` and the column is `decimal` specifically to avoid float rounding — any fix touching price handling must preserve that, never "simplify" it to `float`.
- Kiro spec `package-and-service-bundles` (`requirements.md`/`design.md`/`tasks.md`) is the "what to build" authority; this plan implements review against those ACs, it does not restate or replace them.
- **Citation-accuracy rule, learned from the three prior retrofits this session:** verify every claimed quote against the real committed file before putting it in a review report. The pilot's plan and `Faq`'s plan each shipped one misattributed quote that a task-scoped review had to correct. In this module specifically, the doc blocks are unusually long and unusually specific — treat each as a *claim to verify*, not as established fact.

---

## Current shipped state

**19 PHP files under `app/Domain/ServiceCatalog/`, no `.gitkeep` placeholders — everything here is real code.**

**Actions (4) — the module's whole write surface:**
- `Actions/DefineServicePackage.php` (195 lines) — creates `service_packages` + its version-1 `draft` + that draft's items, evidence requirements, and substitution policies, in one `DB::transaction`. Requires ≥1 item. Audits `PACKAGE_DEFINED`.
- `Actions/PublishServicePackageVersion.php` (88 lines) — the only `draft` → `published` transition. Takes `lockForUpdate()`, returns early (idempotent no-op, no second audit row) if already published, refuses to publish a zero-item version. Audits `PACKAGE_VERSION_PUBLISHED`.
- `Actions/ReviseServicePackageVersion.php` (128 lines) — copies (never links) every item + child row from the current published version into a fresh `draft`. Refuses if an open draft already exists or if the package was never published. Audits `PACKAGE_VERSION_REVISED`.
- `Actions/RecordServiceDefinitionPriceVersion.php` (106 lines) — closes the current `price_versions` row (`superseded_at = now`) and inserts the new one, in one transaction, so at most one row per service has `superseded_at IS NULL`. Audits `PRICE_VERSION_RECORDED`.

**Models (7):** `ServicePackage`, `ServicePackageVersion`, `ServicePackageItem`, `ServiceDefinition`, `PriceVersion` (polymorphic `morphMany` — attaches to both `ServiceDefinition` and `ServicePackageVersion`), `SubstitutionPolicy`, `EvidenceRequirement`.

**Closed lists / support (5):** `ServiceCode` (12 codes: 2 basic + 10 additional, derived from `docs/product/service-catalog.md`), `ServiceCategory`, `ServicePackageItemType` (included/optional/excluded), `FulfillmentOwner` (platform/cemetery/vendor), `ServicePackageVersionStatus` (draft/published).

**Seams:** `ServiceCatalogQuery` (static read facade), `ServiceCatalogAuditActions` (4 action-name constants), `Exceptions/PublishedServicePackageVersionIsImmutableException`.

**AC2 immutability, as actually enforced (verified by reading the code, not the doc block):**
- `ServicePackageVersion::booted()` — `saving()` throws if `$version->exists && $version->getOriginal('status') === PUBLISHED`. Checking the **original** status is what permits the one legal `draft → published` save while freezing everything after it. `deleting()` throws for a published row too — deliberately stricter than AC2's literal "modification" wording.
- `ServicePackageItem::booted()` — an item has no `status` column; its editability is derived by re-querying the owning version's *current* status on every save/delete (explicitly not trusting a possibly-stale loaded relation).
- Both guards are **Eloquent-event-level, not structural**: a raw `DB::table(...)->update(...)` bypasses them entirely. The version model's own doc block says so plainly. This is the identical shape of guarantee `FaqArticle` carries, and `Faq`'s retrofit ledgered the DB-constraint half as a human-ruling item.

**Migrations (8 in this module's `2026_07_26_180000`–`189999` range, plus 1 later dummy-data migration):**
`create_service_definitions_table`, `create_service_packages_table`, `create_service_package_versions_table`, `create_service_package_items_table`, `create_price_versions_table`, `create_substitution_policies_table`, `create_evidence_requirements_table`, `seed_service_definitions_from_catalog`, and `2026_07_26_220000_seed_service_definition_dummy_operational_data`. Note `create_cemetery_packages_table` (`2026_07_26_190200`) is **CemeteryCapability's**, not this module's, despite its name — its own doc block says the concept "belongs to `ServiceCatalog`/`Booking`, neither of which this batch may [define]." That overlap is worth the schema reviewer's attention as a boundary question, not a defect to fix here.

**Tests (6 files, 1,054 lines):** `ServicePackageLifecycleTest` (260), `ServiceDefinitionSeedTest` (183), `PriceVersioningTest` (156), `ServiceCatalogAuditIntegrationTest` (143), `ServiceCodeDriftTest` (152 — a catalogue-drift guard, the same shape as Marketplace's), `ServicePackageVersionImmutabilityTest` (160).

**Consumers outside the module (the seam, in scope seam-only):**
- `app/Livewire/Public/Booking/BookingWizard.php:394` — `ServiceCatalogQuery::allActive()`, split into "Wajib"/additional groups via `ServiceCode::isBasic()` rather than the catalogue's own `category` column. The call site's own comment explains why (it must exactly match what `SaveBookingDraftStep::validateServices()` enforces). **Verify that stated invariant actually holds** — that comment is a claim, and the two sources agreeing "today" is exactly the kind of assertion a retrofit exists to check.
- `app/Domain/Booking/Actions/SaveBookingDraftStep.php` — consumes `ServiceCode`.
- `app/Domain/Booking/BookingDraftQuery.php` — consumes `ServiceDefinition` for Step 5's price presentation.

**`sprint-plan.md` S4-T1 row** (line 623, verbatim): "✅ Done (26 Jul 2026) — master data/seeds only; full AC coverage for each spec is S4-T6/S4-T7/S4-T8's job. **Deployed to dev.makam.co.id 26 Jul 2026**". Note what this means for Task 5: unlike S4-T6/T7/T8, **S4-T1 is a shared multi-spec row** (`cemetery-directory…`, `package-and-service-bundles`, `funeral-marketplace…`). The concurrent `Marketplace` retrofit will append-correct the *same* row. Append only the ServiceCatalog-specific sentence; expect and accept a small merge conflict there.

**Kiro spec self-reported gaps** (`tasks.md`/`design.md`, quoted not summarized):
- **AC2's admin editor** — "the admin Filament editor UI is not built (out of S4-T1's master-data scope)." Confirmed absent. **Ledger, do not build.**
- **AC3/AC4's quote half** — "quote *expansion* (turning a package into real order/quote line items) is not built — that belongs to the booking wizard/orchestration work (S4-T4/S4-T5 onward)." `design.md` §"Consumption boundary" states the same boundary and calls AC3/AC4 "only half-built here." **This is the boundary Slice 3 must test-check**, not re-decide: the question is whether the shipped tests correctly prove the *snapshot* half without overclaiming the *expansion* half.
- **No catalogued event** — `design.md`: "No event for publish/revise/discontinue is catalogued in `docs/contracts/event-catalog.md` (checked — none exists); a consumer needing one requires adding it there first, not inventing an ad hoc name (N-12's lesson)." Re-verify (cheap grep) rather than inheriting.
- **Discontinued item still attached to an active bundle** — `design.md`: "unresolved by the current schema — flagged, not designed around, since AC1–AC8 don't specify the behaviour." A real, self-disclosed hole. Slice 1/2 should state what actually happens today if a `ServiceDefinition` is deactivated while a published version still references it.
- **AC5/AC6 (substitution + evidence)** — `tasks.md` marks these `[x]` "done… wired into package-item authoring, tested," but `design.md`'s Error handling section describes *runtime* enforcement ("completion Actions check every required item's `EvidenceRequirement` first; a missing item blocks completion") and **no completion Action exists in this module**. Slice 3's central question: does the shipped test coverage prove the *authoring* half only, while `tasks.md`'s `[x]` reads as if the *enforcement* half is also done? If so, that is a `tasks.md` overclaim to correct in Task 5 — the same defect class `Faq`'s retrofit corrected (9-of-10 UI states claimed, 8 real).

**A lead already visible from this plan's own research** (state it so a reviewer confirms or refutes it independently, rather than re-discovering it by luck): `ServiceCatalogQuery`'s doc blocks say "The 11 catalogue-defined 'Additional services'" and "All 12 active catalogue services" — 2 + 11 ≠ 12, and `ServiceCode`'s own doc block resolves the count explicitly to **10** additional (its "12 codes, not 13" section, which reasons the discrepancy out against the canonical file). One of these two doc blocks is stale. Confirm which by counting `docs/product/service-catalog.md`'s real table rows, and treat the count in `ServiceCode` as authoritative only if the file agrees.

**What a design-time `brainstorming` pass would have asked, had one run before S4-T1 started** (reconstructed retrospectively, per the retrofit recipe):
1. "AC2 says a published version is immutable. Immutable *to whom* — Eloquent callers, or the database? If a seed migration or a future bulk operation writes via the query builder, does the guarantee still hold?" (Asked and answered honestly in the model's doc block — but answered as a disclosure, not a decision, and no DB-level constraint followed.)
2. "AC5/AC6 describe *runtime fulfillment* behaviour (substitution approval, completion-evidence checking), but this batch ships only authoring. Should `tasks.md` mark them `[x]` when only half the AC has code?" (Not asked — and `tasks.md` does mark them `[x]`.)
3. "`cemetery_packages` is being created in the same wave by a different module for a concept this spec owns. Who owns package↔cemetery association — and what happens when both definitions exist?" (Half-asked: `CemeteryPackage`'s doc block flags the overlap, but no resolution was recorded anywhere.)

## Review scope

**In scope:**
- `app/Domain/ServiceCatalog/**` — all 19 files.
- The 8 module-owned migrations listed above, plus `2026_07_26_220000_seed_service_definition_dummy_operational_data.php`.
- All 6 test files under `tests/Feature/Domain/ServiceCatalog/`.
- **Seam-only**: `BookingWizard.php:394`'s `ServiceCatalogQuery::allActive()` call and the `ServiceCode`/`ServiceDefinition` consumption in `SaveBookingDraftStep.php` / `BookingDraftQuery.php` — specifically whether the catalogue contract holds across that boundary. **Not** Booking's own wizard/draft concerns.
- `docs/product/service-catalog.md` and `docs/contracts/service-package-schema.md` — read-only, as the canonical sources the code claims to derive from.

**Out of scope (do not touch — belongs to a different, separately-tracked unit of work):**
- `app/Domain/Marketplace/**`, `app/Livewire/Public/Marketplace/**` — the *other* half of `retrofit-backlog.md` §1 item 7, retrofitted concurrently by a sibling agent in its own worktree.
- AC8 / the admin Filament editor — deliberately unbuilt, owner `admin-operations`. **Ledger, do not build.**
- Quote expansion (AC3/AC4's second half) — owner `booking-and-order-orchestration`. **Ledger, do not build.**
- Any new migration against an already-deployed ServiceCatalog table — human-ruling item per `AGENTS.md`, not a fix-wave item.
- `App\Platform\Audit\**` itself — this retrofit verifies the four call sites, it does not modify the Audit platform module.
- Booking's wizard/draft internals, and `CemeteryCapability`'s `cemetery_packages` table — flag the boundary question, do not resolve it here.

---

## Task 1: Draft the review briefs and dispatch task-scoped review

**Files:**
- Create (ledger, git-ignored, inside the worktree): `.superpowers/sdd/retrofit-servicecatalog/task-1-brief-{domain,schema,tests}.md`
- Read only: everything under "Review scope" above.

**Interfaces:**
- Produces: three independent review reports (domain/lifecycle Actions + immutability; schema/migrations + audit integration; test-coverage adequacy for the AC3–AC6 boundary claims), each graded against `AGENTS.md`, the `package-and-service-bundles` Kiro spec, and `mattpocock-skills:codebase-design`'s deep-module vocabulary. Task 2's whole-module reviewer consumes all three.

- [ ] **Step 1: Create the worktree and branch**

```bash
git worktree add .worktrees/retrofit-servicecatalog -b retrofit-servicecatalog origin/docs/design-system-and-planning
cd .worktrees/retrofit-servicecatalog
```

- [ ] **Step 2: Commit this plan doc first, before any review work starts**

```bash
git add docs/superpowers/plans/2026-08-09-retrofit-servicecatalog.md
git commit -m "Add retrofit plan doc: ServiceCatalog"
```

- [ ] **Step 3: Dispatch three task-scoped review agents in parallel via `dispatching-parallel-agents`**

Each agent reviews ONLY its slice, against: (a) `AGENTS.md` in full, (b) `.kiro/specs/package-and-service-bundles/{requirements,design,tasks}.md` in full, (c) `mattpocock-skills:codebase-design` vocabulary, (d) this plan's "Current shipped state" section (verify and extend, don't re-discover).

- **Slice 1 — domain/lifecycle Actions + immutability enforcement.** Files: the 4 Actions, the 7 models, the 5 closed lists, `ServiceCatalogQuery`, the exception class. Ask explicitly:
  - Does `ServicePackageVersion::booted()`'s `getOriginal('status')` check actually permit exactly one `draft → published` save and nothing after it? Trace the real Eloquent lifecycle, including `forceFill()->save()` (which `PublishServicePackageVersion` uses) and `refresh()`.
  - Does `ServicePackageItem::booted()`'s fresh re-query of the owning version genuinely close the stale-relation hole its doc block claims? What happens on `ServicePackageItem::insert()` (static, event-free) or a mass `update()` on a query builder?
  - `DefineServicePackage::createItem()` writes `item_type` and `fulfillment_owner` straight from the caller's array. Are those validated against `ServicePackageItemType` / `FulfillmentOwner` anywhere before they reach the DB — in the model's `booted()`, in a CHECK constraint, or nowhere? (`ServicePackageVersionStatus::assertKnown()` is called for status; check whether the item-level closed lists got the same treatment.)
  - `ReviseServicePackageVersion` computes `version_number = $current->version_number + 1`. Is there any uniqueness invariant on `(service_package_id, version_number)`, and can two concurrent revisions collide? (It takes `lockForUpdate()` on the *package* — assess whether that is sufficient.)
  - `RecordServiceDefinitionPriceVersion`'s "at most one row with `superseded_at IS NULL`" invariant — is it enforced by the lock, by a partial unique index, or only by this Action's discipline? `PriceVersion` is polymorphic (`morphMany` from both `ServiceDefinition` and `ServicePackageVersion`); does the `max('version_number')` computation scope correctly to one `priceable`?
  - The `discontinued item still attached to an active bundle` hole `design.md` self-discloses: state what actually happens today when a `ServiceDefinition` is deactivated while a published version references it.
  - Deep-module check: is `ServiceCatalogQuery` a genuinely deep read seam, or a thin pass-through whose own doc block admits it ("a convenience, not a NEW mechanism")? If callers can and do bypass it, say so.
  - Verify the `ServiceCatalogQuery` doc-block count discrepancy named in "Current shipped state" — confirm or refute against `docs/product/service-catalog.md`'s real rows.

- **Slice 2 — schema/migrations + audit integration.** Files: the 8 module migrations, `2026_07_26_220000_seed_service_definition_dummy_operational_data.php`, `ServiceCatalogAuditActions`, and the 4 `Audit::record()` call sites. Ask explicitly:
  - Do the seed migrations write via `DB::table()->insert()` (bypassing every model guard) or via Eloquent? This exact bug class has appeared in **three** prior retrofits — check whether it recurs, and if it does, whether the written values are still structurally protected by closed-list constants or are hand-typed literals.
  - Are the closed-list columns (`status`, `item_type`, `fulfillment_owner`, `category`) backed by any DB-level guard (CHECK constraint / partial unique index), or by Eloquent hooks alone? If hooks alone, that is a **known human-ruling item** (deployed tables) — record it as such, do not propose a migration.
  - FK `onDelete` behaviour on every relation: does deleting a `ServiceDefinition` cascade into `service_package_items` (which would silently mutate a *published*, immutable version — an AC2 violation reached through a side door)? This is the single highest-value question in this slice. `Faq`'s retrofit found the equivalent `cascadeOnDelete()` hole on version history.
  - Is there an FK-ordering hazard in any test that drops/truncates these tables? (The `a150a3b` bug class — the pilot found a live third instance.)
  - Audit integration: does every one of the 4 Actions record inside the same transaction as its state mutation? Does `PublishServicePackageVersion`'s idempotent early-return correctly write **no** second audit row (its doc block claims so)? Are the `AuditSubject` type strings (`service_package`, `service_package_version`, `service_definition`) consistent with the rest of the codebase's audit vocabulary?
  - `ServiceCatalogAuditActions` doc block claims these are deliberately not on `SensitiveActions::ACTIONS`. Verify that claim against `App\Platform\Audit\SensitiveActions` and assess whether price-version recording (a financial-adjacent action) belongs there — **flag for human ruling if so, do not add it** (`AGENTS.md`: financial changes need human review).
  - Re-verify `design.md`'s "no event catalogued" claim against `docs/contracts/event-catalog.md`.
  - Boundary note only (do not fix): `cemetery_packages` (CemeteryCapability, `2026_07_26_190200`) versus this module's package concept.

- **Slice 3 — test-coverage adequacy for the AC3–AC6 boundary claims.** Files: all 6 test files. Ask explicitly:
  - **The quote-snapshot vs. quote-expansion boundary (AC3/AC4).** `tasks.md` says the snapshot mechanism is "done… tested" and expansion is "not built." Does `PriceVersioningTest` actually prove a snapshot a quote *could* reference (a stable, immutable, retrievable historical price), or does it only prove the supersede-chain bookkeeping? State precisely which half of AC3 has evidence and which does not.
  - **Substitution-rule approval (AC5).** `tasks.md` marks AC5 `[x]` done. `design.md` says a policy "requires customer approval" puts the item in `pending` and it is "never silently substituted." Is there any code that *applies* a substitution or *obtains* approval — or only code that **stores** a `SubstitutionPolicy` row with a `requires_customer_approval` boolean? Whatever the answer, name it exactly; the `[x]` in `tasks.md` is the claim under test.
  - **Evidence checking (AC6).** Same question: `design.md` says "completion Actions check every required item's `EvidenceRequirement` first." Does a completion Action exist anywhere in this repo? If not, AC6's `[x]` covers authoring only.
  - Spot-check at least 3 test method names against their actual bodies for vacuous passes (a `foreach` with no non-emptiness guard, an assertion that would pass on an empty set, an `expectException` that could be satisfied by an unrelated throw). Both the pilot and `GraveRegistry` retrofits found real instances of this.
  - Does `ServicePackageVersionImmutabilityTest` prove the guard for **both** save and delete, on **both** the version and its items, and does it prove a raw query-builder write *bypasses* it (i.e. is the known limitation itself pinned by a characterization test, the way `Faq`'s retrofit pinned its policy gap)?
  - Does `ServiceCodeDriftTest` genuinely fail if `docs/product/service-catalog.md` and `ServiceCode` diverge, or only if `ServiceCode` and the seeded rows diverge? (Those are different guarantees; the doc-block count discrepancy above suggests the doc↔code direction may be unguarded.)
  - Run `bash ci/verify-docs.sh` and report the real result. Attempting the PHP suite locally is **BLOCKED** on this host (no `vendor/`) — say `BLOCKED`, never `PASS`.

- [ ] **Step 4: Each reviewer writes its report**

Format, one per slice, saved to `.superpowers/sdd/retrofit-servicecatalog/task-1-report-{domain,schema,tests}.md`:

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
git add .superpowers/sdd/retrofit-servicecatalog/
git commit -m "Task 1: task-scoped review, 3 slices (domain, schema, tests)"
```

## Task 2: Whole-module review

**Files:**
- Read: all three Task 1 reports, plus the full module (everything in "Review scope" above), read holistically.
- Create: `.superpowers/sdd/retrofit-servicecatalog/task-2-whole-module-review.md`

- [ ] **Step 1: Dispatch one reviewer over the whole module** — consolidate, dedupe, resolve every "Questions for the whole-module reviewer."
- [ ] **Step 2: Rule explicitly on AC8's disposition** — confirm the admin editor is genuinely absent (not merely undocumented), name `admin-operations` as owner, and record it as a deliberate deferral, **not a defect**. This mirrors the pilot's AC10 disposition in `retrofit-backlog.md` §2.
- [ ] **Step 3: Rule explicitly on the AC5/AC6 `[x]` question** — does `tasks.md` overclaim by marking authoring-only work as fully done? If yes, this is a Task 5 documentation correction, not a fix-wave code change.
- [ ] **Step 4: Rule explicitly on the AC2 enforcement-depth question** — Eloquent-hook-only immutability on already-deployed tables. Expect the same disposition the pilot and `Faq` reached (ledger for human ruling); say so explicitly rather than leaving it implied.
- [ ] **Step 5: Triage every finding Critical/Important/Minor** — same triage rule as all four prior retrofits.
- [ ] **Step 6: Commit the whole-module review.**

## Task 3: Bounded fix wave

**Files:** Whatever Task 2's Critical/Important findings name. **Do not modify any file not named by a Critical or Important finding.** No new migrations (see Global Constraints). No new UI.

- [ ] **Step 1: Write a failing regression test for each Critical/Important finding.**
- [ ] **Step 2: Confirm it fails — or state `BLOCKED` explicitly** (this host cannot run PHPUnit; a fix whose test cannot be run locally must say so and rely on CI as the oracle, never claim a local PASS).
- [ ] **Step 3: Apply the minimal fix.**
- [ ] **Step 4: `php -l` every touched file, and run `bash ci/verify-docs.sh`.**
- [ ] **Step 5: Commit, one logical fix per commit.**
- [ ] **Step 6: Ledger every Minor finding verbatim**, not fixed.
- [ ] **Step 7: If any Critical/Important finding is still open after this one bounded wave, stop and escalate for a human ruling** — do not open a second wave. Max 5 rounds inside this wave; reaching round 4 is itself an escalation trigger.

## Task 4: Scoped re-review

**Files:** Only the files touched in Task 3. Dispatch one reviewer scoped to exactly those files; commit to `.superpowers/sdd/retrofit-servicecatalog/task-4-rereview.md`.

The re-reviewer's specific job — learned from the pilot, where a fix-wave regression test was itself vacuous and only the scoped re-review caught it: for every new test, verify it would actually fail against the pre-fix code. Reason it through explicitly; do not accept "the test asserts the right thing" as sufficient.

## Task 5: Explicit disposition of every self-flagged gap + documentation correction

**Files:**
- Modify: `.kiro/specs/package-and-service-bundles/tasks.md` — correct any overclaim Task 2 ruled on (the AC5/AC6 `[x]` question above is the leading candidate). `AGENTS.md` §Documentation: "Update spec, traceability, screen inventory, API contract, and test when behavior changes."
- Modify: `.kiro/specs/package-and-service-bundles/design.md` — only if Task 2 found a statement that is factually wrong about the shipped code.
- Modify: `docs/planning/sprint-plan.md` — S4-T1's row (line 623) gets an **append-correction** (original text untouched) with this retrofit's real PR number, CI run ID, and finding counts. **This row is shared with the concurrent `Marketplace` retrofit** — append only the ServiceCatalog sentence; a merge conflict on this row is expected and is resolved by whoever merges second.
- Modify: `docs/planning/retrofit-backlog.md` — §1 item 7's **ServiceCatalog portion only** (leave the Marketplace half for its own lane), plus a new §2 disposition entry following the exact format used for items 1–4.

- [ ] **Step 1: Give each self-flagged gap an explicit disposition** in `retrofit-backlog.md` §2 — at minimum: AC8/admin editor, AC3/AC4 quote expansion, the uncatalogued publish/revise event, the discontinued-item hole, AC2's Eloquent-only enforcement depth, plus every finding Task 2 triaged.
- [ ] **Step 2: `tasks.md` / `design.md` corrections.**
- [ ] **Step 3: `sprint-plan.md` S4-T1 append-correction.**
- [ ] **Step 4: Run `bash ci/verify-docs.sh` and commit.**

## Task 6: Finish the branch

- [ ] **Step 1: `php -l` every touched PHP file** (the only executable check available on this host).
- [ ] **Step 2: Run `bash ci/verify-docs.sh`.**
- [ ] **Step 3: Use `superpowers:finishing-a-development-branch`** — push, open a PR against `docs/design-system-and-planning`. **Do not merge.**
- [ ] **Step 4: Once a PR exists and CI is green, fill in Task 5's PR/CI placeholders and push a final correcting commit.**

---

## Self-review

**Spec coverage:** AC1/AC2/AC7 get real review (Slices 1–2, Task 2). AC3/AC4 get boundary review — the snapshot half is reviewed, the expansion half is explicitly ledgered as `booking-and-order-orchestration`'s (Slice 3, Task 5). AC5/AC6 get the authoring-vs-enforcement question ruled on explicitly (Slice 3, Task 2 Step 3). AC8 gets explicit disposition as a deliberate deferral (Task 2 Step 2, Task 5) and is not built.

**Placeholder scan:** the only bracketed placeholders are Task 5's PR number/CI run ID, which do not exist until the PR does — the same accepted exception all four prior retrofits took, closed by Task 6 Step 4.

**Type consistency:** `ServicePackageVersion`, `ServicePackageItem`, `ServiceDefinition`, `PriceVersion`, `SubstitutionPolicy`, `EvidenceRequirement`, `ServiceCatalogQuery`, `ServiceCode`, `ServicePackageVersionStatus`, `ServicePackageItemType`, `FulfillmentOwner`, `ServiceCatalogAuditActions`, `PublishedServicePackageVersionIsImmutableException` — all referenced identically everywhere they appear across tasks, verified against the real source while writing this plan (file inventory read directly, not reconstructed from research notes).

## Verification

- [ ] Plan doc exists, committed before review work starts.
- [ ] Ledger populated with 3 task-scoped briefs + reports, plus a whole-module review that explicitly rules on AC8, the AC5/AC6 `[x]` question, and AC2's enforcement depth.
- [ ] A bounded fix-wave commit exists with every Critical/Important finding closed or escalated; every Minor finding visibly parked.
- [ ] Regression tests exist for whatever the fix wave touched, and the scoped re-review confirms each would have failed pre-fix.
- [ ] AC8, quote expansion, the uncatalogued event, the discontinued-item hole, and AC2's enforcement depth each get an explicit disposition, not silence.
- [ ] No new migration and no new UI landed in this retrofit.
- [ ] A PR against `docs/design-system-and-planning` exists; CI green on PostgreSQL 18.
- [ ] `sprint-plan.md`'s S4-T1 row and `retrofit-backlog.md` §1 item 7 (ServiceCatalog portion) + a new §2 entry are append-corrected, original text untouched.
