# Retrofit: Faq — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the `Faq` module (Kiro spec `public-faq`) the two-tier Superpowers SDD review it never got, close any Critical/Important findings with a bounded fix wave and real regression tests, and give explicit disposition to every self-flagged gap already on record (N-13, N-15/OQ-05, the responsive/accessibility NOT TESTED items).

**Architecture:** Review-and-fix retrofit, not new-feature construction — matches the recipe `docs/planning/retrofit-backlog.md`'s pilot (`CemeteryDirectory`+`CemeteryCapability`) already exercised. Work happens in an isolated worktree/branch off `docs/design-system-and-planning`, fans task-scoped review out by functional slice via `dispatching-parallel-agents`, runs one whole-module reviewer over the combined findings plus the code holistically, then applies one bounded fix wave. Minor findings get ledgered and parked, not chased.

**Scope difference from the pilot, stated up front:** `Faq` is a substantially larger module than the pilot — it has a real write-side lifecycle (draft → publish → unpublish → reorder, versioned) and a full Filament admin CRUD resource, not just a read-only public surface. The review fans out across **five** slices, not four, to give the write-lifecycle/admin-CRUD dimension its own reviewer rather than folding it into "domain."

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4, Filament 5.7.3, PostgreSQL 18 (CI oracle) / SQLite (local), Pest via PHPUnit, `ci/verify-docs.sh`.

## Global Constraints

- Never hardcode a design value or use a Tailwind arbitrary value — `ci/verify-docs.sh` gates 2/3/11/12.
- Every public read goes through `FaqPublicQuery` (the module's public read entry point), matching the pattern the pilot retrofit's own finding established for `CemeteryPublicQuery` — verify this invariant actually holds here too, don't assume it transferred.
- `AGENTS.md` §Testing: every Critical/Important fix in this retrofit gets a real regression test — CI (PostgreSQL 18) is the oracle, not a local SQLite green run.
- `AGENTS.md` §Infrastructure-agent execution: never report PASS for a check not executed; use BLOCKED or NOT TESTED explicitly. This host cannot run PHP locally (no `vendor/`, `CLAUDE.md` forbids `composer install` here) — every reviewer/implementer in this retrofit states this plainly, same as every prior retrofit this session.
- `AGENTS.md` §Documentation: do not duplicate canonical catalogue data (`FaqCategoryCode`, `FaqArticlePublishState` stay the one PHP-side source).
- Kiro spec `public-faq` (`requirements.md`/`design.md`/`tasks.md`) is the "what to build" authority for this retrofit; this plan does not restate its ACs, it reviews shipped code against them.

---

## Current shipped state

**Modules under review:**
- `app/Domain/Faq/**` — `FaqPublicQuery` (public read entry point: categories, published articles, search, related articles), `FaqCategoryCode`/`FaqArticlePublishState` (closed lists), `FaqAuditActions`, `Actions/{CreateFaqArticleDraft,PublishFaqArticle,UnpublishFaqArticle,UpdateFaqArticleContent,ReorderFaqArticles}` (the write-side lifecycle), `Models/{FaqArticle,FaqArticleVersion,FaqCategory}`.
- `app/Filament/Admin/Resources/FaqArticles/**` — full CRUD: `FaqArticleResource.php`, `FaqArticleStatusBadge.php`, 4 Pages (List/Create/Edit/View), `Schemas/{FaqArticleForm,FaqArticleInfolist}.php`, `Tables/FaqArticlesTable.php`.
- `app/Livewire/Public/Faq/**` — `FaqIndex.php`, `FaqArticleDetail.php`, `Support/FaqCategorySlug.php`.
- Migrations (5): `create_faq_categories_table`, `create_faq_articles_table`, `create_faq_article_versions_table`, `create_faq_article_related_article_table`, `seed_faq_categories_and_articles`.
- Consumers outside the module: `App\Livewire\Public\HomePage.php:8,106` (`FaqPublicQuery::allPublished()->take(4)`, wrapped in the same try/catch discipline `FaqIndex::render()` uses), `App\Livewire\Public\Support\HelpCentre.php` (references Faq — not yet characterized in detail, Task 1's UI-slice reviewer confirms the exact seam).

**History (why this is next in the retrofit backlog):** S4-T2 (26 Jul 2026, "agent-team" batch per `sprint-plan.md:613-615`'s now-superseded execution methodology) shipped this module as the FIRST real vertical slice built under that batch process — before the booking wizard, before the pilot retrofit's CemeteryDirectory. It carries three self-documented findings from that period:

- **N-13** (`sprint-plan.md:896`) — `docs/architecture/overview.md` §5's module table had no `Faq` row when this batch needed one, because three tables (`faq_categories`/`faq_articles`/`faq_article_versions`) needed a schema-owning module directory and none of the 23 existing rows covered FAQ content. The batch created `app/Domain/Faq/**` anyway (correct call — `app/Domain/README.md`'s own rule), flagged the missing table row as future work for whoever owns that document. **Verified directly: the row now exists** (`docs/architecture/overview.md:94`, exact suggested text: "Public FAQ categories, articles, versioning, and publish lifecycle"). N-13 itself is already closed in the code/docs; `sprint-plan.md`'s own entry for it just hasn't been told yet — a correction-append, not new work (Task 5 below).
- **N-14** (`sprint-plan.md:897`, "High (resolved)") — a real Blade-compiler bug, not Livewire-specific as first suspected: `BladeCompiler::compileString()`'s `storeUncompiledBlocks()` runs before `compileComments()`, so the literal substring `@php` inside a `{{-- --}}` doc-comment's prose (present in 7 `mk.*` primitives, including `header.blade.php`, rendered on every page via `layouts/app.blade.php`) got misparsed as a real `@php` block, silently swallowing the real `@props([...])` declaration and corrupting the compiled output. Root-caused and reproduced against a real installed Blade compiler, fixed by rewording the 7 affected comments, verified by a full repo-wide sweep. **Reads as complete and correct** — the retrofit's job is to confirm this holds under real independent review, not re-diagnose it.
- **N-15** (`sprint-plan.md:898`, "Medium") — fixing N-14 unblocked real page renders for the first time, which surfaced design-system.md's still-open **OQ-05** ("which icon set?") as a real CI failure: `header.blade.php`'s hamburger button unconditionally calls `<x-dynamic-component :component="'icon.bars-3'">`, and no `resources/views/components/icon/**` directory existed yet. Fixed by adding exactly one icon (`icon/bars-3.blade.php`, real Heroicons v2 outline glyph), deliberately scoped — the batch's own note says "do that as its own batch once OQ-05 is actually decided, not piecemeal per CI failure." **This retrofit does not resolve OQ-05** — it is a program-wide open question, out of this module's scope; ledger it (Task 5), don't attempt it.

**`sprint-plan.md` S4-T2 row** (verbatim, line 624): "✅ Done (26 Jul 2026), CI green. **Deployed to dev.makam.co.id 26 Jul 2026**" — **no CI run ID cited**, unlike later rows (S4-T4, S4-T6) which link a real GitHub Actions run. Spot-check finding for Task 5: either find the real run ID from around 26 Jul 2026 and cite it, or state plainly that it could not be recovered.

**Kiro spec self-reported gaps** (`tasks.md`, quoted not summarized):
- Responsive verification (§4.3, part of AC9): "**NOT done** — responsive verification is NOT done" among an otherwise 5/6-done top-level task list.
- 9 of 10 required UI states implemented; the 10th (unspecified which in the research pass — Task 1's UI-slice reviewer confirms which) not built.
- Accessibility: "token/class-level compliance only... not verified with a real browser/axe/screen reader" — same class of gap the pilot retrofit already found and ledgered for CemeteryDirectory (no Dusk/Playwright/Cypress harness exists anywhere in this repo — a repository-level tooling gap, not Faq-specific).
- **AC7 ("FAQ content must reflect active payment/Urgent gates, never an unsupported claim") has no enforcement mechanism.** **Correction, made during Task 1's task-scoped review (three independent reviewers caught this):** this plan originally attributed the claim below to a "design.md Open decisions" section. That section does not exist — `.kiro/specs/public-faq/design.md` is 15 lines (`## Data`/`## Search`/`## Routes` only), one commit in its history (`05f6f4d`, the baseline import), never revised. The attribution was a research error carried from this plan's own drafting, not a real citation. **The underlying claim is independently confirmed true** by three Task 1 reviewers via direct grep of the real code: no FAQ code path resolves a gate (`grep -rn -i "FeatureGate\|ModeResolver\|GateResolver\|gate" app/Domain/Faq/ app/Livewire/Public/Faq/ app/Filament/Admin/Resources/FaqArticles/` returns only prose in doc comments, zero executable references), and no test references AC7. What this changes: the question for Task 2 is **not** "should we override design.md's recorded decision" (no such decision was ever recorded) but **"should this editorial-review posture be formally recorded now, for the first time, and where."** Task 2's whole-module reviewer also has a real constraint the original framing missed: AC7's subject is prose stored in `faq_articles.body` — no resolver can inspect whether a paragraph of free text makes an unsupported claim, so "add a code guard" cannot mean "validate the claim against the gate." The realistic choices are (a) formally document editorial review as the accepted enforcement, with a stated cadence/owner, or (b) a narrower mechanism — e.g. recording which gate each article's content depends on and flagging for review when that gate's mode changes. Task 2 rules between (a) and (b), not between "guard" and "no guard."

**What a design-time `brainstorming` pass would have asked, had one run before S4-T2 started** (reconstructed retrospectively, per the retrofit recipe — this module predates even the pilot's own history, so this section is speculative in a way the pilot's wasn't, but still useful for the whole-module review to weigh):
1. "Does `docs/architecture/overview.md` §5's module table need a new row before this batch starts, or after?" (Would have prevented N-13 outright — a one-line check before scaffolding.)
2. "Do any of the shared `mk.*` primitives' doc comments contain a literal `@php` substring in their prose?" (Would not plausibly have been asked in advance — N-14 is a genuinely obscure compiler-ordering bug, not something a design review would catch; recorded for completeness, not as a real miss.)
3. "AC7 requires FAQ content to reflect active gates — what does 'reflect' mean concretely: a real-time code check, or an editorial process with a documented cadence?" (Not asked — this is exactly the kind of ambiguous requirement a brainstorming pass exists to pin down before implementation, and its absence is why AC7 now rests on an implicit, undocumented editorial process.)

## Review scope

**In scope:**
- `app/Domain/Faq/**` (all files).
- `app/Filament/Admin/Resources/FaqArticles/**` (all files).
- `app/Livewire/Public/Faq/**` (all files).
- `resources/views/livewire/public/faq/{index,article-detail}.blade.php` and any Filament-side Blade views under `resources/views/filament/admin/**faq**` if they exist (Task 1's UI-slice reviewer confirms).
- The 5 migrations listed above.
- All 14 test files across `tests/Feature/Domain/Faq/**` (6), `tests/Feature/Filament/Admin/Faq/**` (6), `tests/Feature/Livewire/Public/Faq/**` (2).

**Out of scope (do not touch — belongs to a different, separately-tracked unit of work):**
- `docs/design/design-system.md` OQ-05 (icon library choice) — N-15's own note already says this explicitly. Ledger it, don't resolve it.
- `HomePage.php`/`HelpCentre.php` — these consume `FaqPublicQuery` but are separate modules with their own place in the retrofit backlog. Reviewers may note a finding that touches a call site in one of these files, but the fix (if any) stays inside Faq's own public interface, not inside the consumer.
- No new UI states, no new accessibility test harness (no Dusk/Playwright/Cypress exists in this repo — a program-level gap already ledgered by the pilot retrofit, not re-litigated here), no AC7 gate-integration code unless Task 2's whole-module review explicitly rules it belongs in the bounded fix wave (see the AC7 question above — this is a real open call, not pre-decided).

---

## Task 1: Draft the review briefs and dispatch task-scoped review

**Files:**
- Create (ledger, git-ignored, inside the worktree): `.superpowers/sdd/retrofit-faq/task-1-brief-{schema,domain-lifecycle,public-ui,admin-ui,tests}.md`
- Read only: everything under "Review scope" above.

**Interfaces:**
- Produces: five independent review reports (schema/migrations, domain write-lifecycle actions, public Livewire UI, admin Filament UI, tests), each graded against `AGENTS.md`, the `public-faq` Kiro spec, and `codebase-design`'s deep-module vocabulary (module/interface/implementation/seam/adapter/depth/locality — not "component"/"service").

- [ ] **Step 1: Create the worktree and branch**

```bash
git worktree add .worktrees/retrofit-faq -b retrofit-faq origin/docs/design-system-and-planning
cd .worktrees/retrofit-faq
```

- [ ] **Step 2: Commit this plan doc first, before any review work starts**

```bash
git add docs/superpowers/plans/2026-08-09-retrofit-faq.md
git commit -m "Add retrofit plan doc: Faq"
```

- [ ] **Step 3: Dispatch five task-scoped review agents in parallel via `dispatching-parallel-agents`**

Each agent reviews ONLY its slice, against: (a) `AGENTS.md` in full, (b) `.kiro/specs/public-faq/{requirements,design,tasks}.md`, (c) `mattpocock-skills:codebase-design` vocabulary, (d) this plan's "Current shipped state" section above (so reviewers don't re-discover facts already established here — they verify and extend them).

- **Slice: schema/migrations** — the 5 migrations, plus `FaqArticle`/`FaqArticleVersion`/`FaqCategory` model `$fillable`/`casts()`/`booted()` blocks. Ask explicitly: does every column have a closed-list guard where the domain requires one; is the version history (`faq_article_versions`) actually append-only and enforced, or only documented (this exact question is what the pilot retrofit found a real gap on for `cemetery_capability_profiles` — check whether the same class of gap exists here); does the seed migration write via `DB::table()->insert()` (bypassing model events, same bug class the pilot found) or via the real Actions.
- **Slice: domain write-lifecycle** — `Actions/{CreateFaqArticleDraft,PublishFaqArticle,UnpublishFaqArticle,UpdateFaqArticleContent,ReorderFaqArticles}`, `FaqPublicQuery`, `FaqCategoryCode`/`FaqArticlePublishState`. Ask explicitly: does every public-read method genuinely exclude unpublished/draft articles (AC6's negative-space requirement — "unpublished never in any public view/search"), with the same "starts from a `published()`-equivalent scope" pattern the pilot retrofit verified for `CemeteryPublicQuery`; does `ReorderFaqArticles` handle a concurrent-reorder race safely; is there a real audit trail (`FaqAuditActions`) for every write Action, not just some; what does the actual code do for AC7 (gate-reflection) — confirm or refute design.md's own claim that "no FAQ code path resolves a gate today."
- **Slice: public UI (Livewire + Blade)** — `FaqIndex.php`, `FaqArticleDetail.php`, `Support/FaqCategorySlug.php`, both Blade views. Ask explicitly: does every array-shaped contract crossing the Livewire→Blade seam have a precise, checkable shape (the exact defect class the pilot retrofit found live in `CemeteryDirectoryIndex`'s `$cards` — check whether Faq's index/detail pages have the same undeclared-shape risk); is the empty-search fallback (AC8: "related categories + CS path, not a dead end") actually implemented and tested; run `bash ci/verify-docs.sh` yourself and confirm gates 1-3, 11-12 pass on this slice specifically.
- **Slice: admin UI (Filament CRUD)** — `FaqArticleResource.php`, `FaqArticleStatusBadge.php`, the 4 Pages, `Schemas/{FaqArticleForm,FaqArticleInfolist}.php`, `Tables/FaqArticlesTable.php`. This slice has no precedent in the pilot retrofit (CemeteryDirectory had no admin CRUD) — read it with fresh eyes. Ask explicitly: does the admin resource correctly call the domain Actions for every mutation (create/publish/unpublish/reorder), or does it write directly to the model (bypassing the versioning/audit the Actions provide — this would be a real, serious finding given `AGENTS.md`'s "written only through the service" pattern established elsewhere); does `FaqArticleStatusBadge` resolve through `StatusIntent` (design-system.md §3.7) rather than switching on a raw status string; is authorization/scoping present (can any authenticated admin edit any article, or is there a scoping concern — note AC5/AC6 don't mention operator-scoping for FAQ the way `admin-operations` does for other modules, so "no scoping" may be correct-by-spec here, not a gap — confirm against the actual AC text, don't assume a gap exists just because scoping exists elsewhere in the codebase).
- **Slice: tests** — all 14 test files. Ask explicitly: does every claim in `tasks.md`'s "done" sections have a real assertion backing it (spot-check at least 3 cited test method names against their actual bodies, matching the pilot retrofit's own methodology, not just names); is the AC6 negative-space requirement (unpublished never reachable) tested at the HTTP-route level, not just the query level; run the full local suite if possible (state clearly if BLOCKED — this host has no `vendor/`) and report real PASS/FAIL counts, never PASS without running it.

- [ ] **Step 4: Each reviewer writes its report**

Format, one per slice, saved to `.superpowers/sdd/retrofit-faq/task-1-report-{schema,domain-lifecycle,public-ui,admin-ui,tests}.md`:

```markdown
# Task 1 report — <slice>

## Findings
- [Critical|Important|Minor] <file:line> — <what's wrong> — <why it matters, citing the AGENTS.md/spec rule violated>

## Confirmed correct (worth stating, not just silence)
- <thing that was checked and holds>

## Questions for the whole-module reviewer
- <anything that needs cross-slice context to judge>
```

- [ ] **Step 5: Commit the five briefs + five reports to the ledger**

```bash
git add .superpowers/sdd/retrofit-faq/
git commit -m "Task 1: task-scoped review, 5 slices (schema, domain-lifecycle, public-ui, admin-ui, tests)"
```

(The `.superpowers/sdd/` path has its own nested `.gitignore` inside the worktree — verify with `git check-ignore -v` before assuming this commit does anything; if ignored, the ledger is worktree-local only, matching every prior retrofit this session.)

## Task 2: Whole-module review

**Files:**
- Read: all five Task 1 reports, plus the full module (everything in "Review scope" above), read holistically.
- Create: `.superpowers/sdd/retrofit-faq/task-2-whole-module-review.md`

**Interfaces:**
- Consumes: the five Task 1 reports' findings and "Questions for the whole-module reviewer" sections.
- Produces: one consolidated, deduplicated findings list, each graded Critical/Important/Minor, plus an explicit ruling on the AC7 gate-reflection question this plan flags above.

- [ ] **Step 1: Dispatch one reviewer over the whole module**

Reads all five Task 1 reports first, then the full module code holistically, produces one consolidated list — deduplicating any finding two slices independently raised, resolving every "Questions for the whole-module reviewer."

- [ ] **Step 2: Rule explicitly on the AC7 gate-reflection question**

**Corrected framing (Task 1 caught the original text misattributing this to design.md — no such section exists there; see "Current shipped state" above):** no FAQ code path resolves a gate today (confirmed independently by three Task 1 reviewers), and no test references AC7 — but this was never previously documented or decided, only true in practice. Rule on: (a) formally document editorial review as the accepted enforcement for AC7, with a stated cadence/owner, or (b) a narrower mechanism recording which gate each article depends on and flagging review when that gate's mode changes. AC7's subject is free-text prose in `faq_articles.body` — no resolver can inspect whether a paragraph makes an unsupported claim, so a real-time code guard validating content against the gate is not an available option; don't invent one. Don't just default to (a) without reasoning — Task 1's domain-lifecycle reviewer frames this as a genuine (a)-vs-(b) call, not "guard vs. no guard."

- [ ] **Step 3: Triage every finding Critical/Important/Minor**

Same triage rule as the pilot retrofit: Critical = violates an `AGENTS.md` MUST/SHALL, a negative criterion in `requirements.md`, or produces incorrect data on the public surface. Important = a real bug or spec gap that isn't yet user-visible or isn't a hard rule violation. Minor = everything else.

- [ ] **Step 4: Commit the whole-module review**

```bash
git add .superpowers/sdd/retrofit-faq/task-2-whole-module-review.md
git commit -m "Task 2: whole-module review, consolidated findings + AC7 ruling"
```

## Task 3: Bounded fix wave

**Files:** Whatever Task 2's Critical/Important findings name — cannot be enumerated in advance. **Do not modify any file not named by a Critical or Important finding.**

**Interfaces:**
- Consumes: Task 2's triaged findings list.
- Produces: one commit per logically-independent fix, each with its own regression test.

- [ ] **Step 1: For each Critical/Important finding, write the failing regression test first**
- [ ] **Step 2: Run the test, confirm it fails** (or state NOT TESTED/BLOCKED if this host cannot run it — matching every prior task this session)
- [ ] **Step 3: Apply the minimal fix**
- [ ] **Step 4: Run the test, confirm it passes; run the full module test suite, confirm no regression**
- [ ] **Step 5: Commit**
- [ ] **Step 6: For every Minor finding, ledger it verbatim rather than fixing it**

```
minor (deferred, retrofit): <finding> — <why parked>
```

- [ ] **Step 7: If any Critical/Important finding is still open after this one bounded wave, stop and get a human ruling**

## Task 4: Scoped re-review

**Files:** Only the files touched in Task 3.

- [ ] **Step 1: Dispatch one reviewer scoped to exactly the touched files**
- [ ] **Step 2: Commit the re-review** to `.superpowers/sdd/retrofit-faq/task-4-rereview.md`

## Task 5: Explicit disposition of every self-flagged gap + sprint-plan.md correction

**Files:**
- Modify: `docs/planning/sprint-plan.md` — TWO append-corrections, both following the file's established convention (do not edit original row/finding text):
  1. N-13's entry (line 896): append a correction stating the `Faq` row now exists at `docs/architecture/overview.md:94`, closing the finding.
  2. S4-T2's row (line 624): append a correction with the retrofit's real PR number, CI run ID, and finding counts (placeholders filled in at Task 6 Step 4, matching the pilot retrofit's own pattern) — plus the spot-check finding about the row's own missing original CI run ID if it could not be recovered.
- Modify: `.kiro/specs/public-faq/tasks.md` — only if Task 2/4 closed anything currently marked `[ ]`; otherwise leave as-is (already honestly self-reported).
- Modify: `docs/planning/retrofit-backlog.md` — mark item 3 done, add a §2 disposition entry for every self-flagged gap (responsive verification, the 1 missing UI state, accessibility, AC7 per Task 2's ruling, N-15/OQ-05).

- [ ] **Step 1: Give each self-flagged gap an explicit disposition** in `retrofit-backlog.md` §2, same format as the pilot's own entry: "closed by this retrofit, evidence: `<test>`" or "ledgered, owner: `<module/spec>`, reason: `<why>`."
- [ ] **Step 2: N-13 correction-append** on `sprint-plan.md:896`.
- [ ] **Step 3: S4-T2 row correction-append** on `sprint-plan.md:624`, with the CI-run-ID spot-check finding.
- [ ] **Step 4: Commit**

## Task 6: Finish the branch

- [ ] **Step 1: Run the full test suite** (via CI push — this host cannot run it locally)
- [ ] **Step 2: Run `ci/verify-docs.sh`**
- [ ] **Step 3: Use `superpowers:finishing-a-development-branch`** — base branch `docs/design-system-and-planning`
- [ ] **Step 4: Once a PR exists and CI is green, fill in Task 5's PR/CI placeholders and push a final correcting commit**

---

## Self-review

**Spec coverage:** Every AC in `public-faq/requirements.md` gets either real review (AC1-AC6, AC8-AC9 via Tasks 1-4) or explicit disposition of an already-self-flagged gap (AC7's editorial-review posture via Task 2 Step 2, responsive/accessibility NOT TESTED items via Task 5).

**Placeholder scan:** Only bracketed placeholders are Task 5 Step 3's PR number/CI run ID (don't exist until the PR does) and Task 6 Step 4's fill-in — both explicitly justified, matching the pilot retrofit's own accepted exception.

**Type consistency:** `FaqPublicQuery`, the five write-side Actions, `FaqCategoryCode`/`FaqArticlePublishState` are referenced identically everywhere they appear across tasks — verified against the actual current source during this plan's research, not assumed.

## Verification

- [ ] Plan doc exists, committed before review work starts.
- [ ] Ledger populated with 5 task-scoped briefs+reports+findings, plus a separate whole-module review that explicitly rules on the AC7 gate-reflection question.
- [ ] A bounded fix-wave commit exists with every Critical/Important finding closed; every Minor finding visibly parked.
- [ ] Regression tests exist for whatever the fix wave touched.
- [ ] N-13, N-15/OQ-05, responsive verification, the 1 missing UI state, and accessibility each get an explicit disposition, not silence.
- [ ] A PR against `docs/design-system-and-planning` exists and merges.
- [ ] `sprint-plan.md` gets both append-corrections (N-13, S4-T2 row) with real PR number, CI run ID, and finding counts.
