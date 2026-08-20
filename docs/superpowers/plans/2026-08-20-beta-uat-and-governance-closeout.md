# Close the Beta Verification & Governance Gap — Makam.co.id

## Context

This is a different, unrelated task from any prior plan in this file — the `/akun` account area
(login/register/logout/password-reset, account shell, drafts, orders) is fully shipped and merged
(PRs #112, #113, #114), and its own follow-up documentation PR (#115, screen-inventory rows) is
open and CI-green.

The user asked for a plan to **accelerate beta release completion**, with **full user-story
coverage via end-to-end UAT**, using **dummy data like dev** where real data isn't ready. Direct
investigation (three parallel research passes plus my own verification against real files, PRs,
and branches — not assumed) surfaced something that reframes the whole request:

**Beta already went live.** `docs/superpowers/plans/2026-08-18-public-beta-release.md` — a
detailed, dated plan with Phase 0 human decisions, Phase 1 engineering Lanes A–E, and a Phase 2
gate — had every engineering lane merged as PRs #96–#109 (async spine, demo-data purge tooling,
identity settings, footer a11y fix, rate limits, observability, CSP, locale, admin settings,
payment-sandbox warning, honesty banner, money-route hardening, noindex). A separate plan doc
(`2026-08-19-homepage-visual-refresh.md`) states outright that the cutover to `makam.co.id`
happened. Engineering then moved straight on to build `/akun` on top of the live cutover.

**But the plan's own two closing gates were never finished:**

1. **ADR-0035 — the formal accepted-risk sign-off — is still unmerged.** Verified directly: it
   exists as **PR #104** (`lane/beta-adr-0035`), **OPEN, MERGEABLE, CI-green** since 19 Aug. Its
   own header reads *"Status: Proposed. Requires human review before merge... this is an
   AI-prepared document covering security/financial/privacy/production-affecting deviations."*
   Its own item 1 text, read directly: *"launch should not proceed on item 1 while reconciliation
   ownership remains unassigned"* — the daily payment-reconciliation owner for sandbox-payment
   risk was never named.
2. **F1 — the mandated scripted manual UAT — has no evidence of ever running.** The beta plan's
   own words: *"2,754 passing tests prove none of this — zero of 60 release gates are checked and
   E2E is one homepage smoke spec."* `docs/testing/release-gates.md` (~60 checkboxes, sections
   A–I) is entirely unchecked. No report exists anywhere recording an executed F1 pass.

So "accelerate beta release completion" is not a build problem — it's a **verification and
governance closeout problem** on something already shipped, extended to cover `/akun` (which
postdates the beta plan and ADR-0035 by two days and appears in neither). This plan proposes zero
new product features: a real UAT pass with recorded evidence, closing ADR-0035 with the user's own
sign-off, and two documentation gaps catching up to what's already true.

## The acceleration lever: decouple "prove it works" from "real data is loaded"

The beta plan's own Lane B already splits `makam_beta` (real launch data, gated on human sourcing
decision H7) from `makam_dev` (the full fictional dataset, installed by ~17 data-writing
migrations — `app/Support/ExampleData/{CemeteryExampleData,VendorExampleData,
VendorListingExampleData,ServiceOperationalExampleData}.php` back all of it: 10 cemeteries,
vendors + listings, 9 grave records, marketplace prices/photos, FAQ content, notification
templates — every row marked `GraveRecordSource::CONTOH`/"Contoh"). Plain `migrate --force` on any
environment gives this full realistic dataset automatically — this **is** "dummy data like dev,"
already built, nothing to construct.

**Run the full UAT pass against dev/staging fixture data, not gated on real-data sourcing at
all.** "All user journeys work end-to-end" and "real cemetery data is live" are independent
claims the beta plan itself already treats as separate lanes (B1/B2 vs B3) — this plan just makes
explicit that verification never needed to wait on data sourcing. This is the single biggest
schedule compression available: UAT can start today.

**One thing to verify first, not assume:** what state the *live* `makam_beta` database is
actually in (has `example-data:purge --force` run there, are there real orders). This determines
whether the live host is even a safe target to look at — UAT should run against dev, never
against a database that might hold real customer bookings.

## Priority ordering

**Must do to honestly call beta "complete":** confirm live-DB state and the reconciliation owner
→ run the full UAT pass and produce recorded evidence → catch up the two documentation gaps →
close ADR-0035 with accurate content and the user's actual sign-off → fix whatever UAT finds.

**Defer past this pass (name, don't silently drop):** building out a durable 6-suite Playwright
E2E harness (real, valuable, but slower and not what's blocking "complete" this week); PUB-050
(the still-unbuilt per-order detail page — `/akun/pesanan` deliberately doesn't link to it yet);
re-litigating any risk the user already accepted on 18–19 Aug (PITR, capacity evidence, UU PDP
audit depth — all already recorded with reversal paths in the ADR-0035 draft).

## Phase 0 — Human decisions (today, no code, gates everything else)

| # | Decision | Unblocks |
|---|---|---|
| P0-1 | Confirm the live `makam_beta` DB's actual state (purged of fixtures? any real orders?) — UAT runs against **dev**, not this DB, but the answer determines whether the live host needs any attention in parallel | Scoping Lane U's target env |
| P0-2 | Name the daily payment-reconciliation owner (ADR-0035 item 1) — the ADR's own text says launch should not proceed while this is open | Lane G merge |
| P0-3 | Review and give (or withhold) sign-off on the ADR-0035 update this plan proposes — no agent can do this per `AGENTS.md` §Infrastructure-agent execution | Lane G |
| P0-4 | Confirm UAT execution mode (this plan recommends agent-driven browser walkthrough via the `claude-in-chrome` skill — see Lane U) | Lane U |

## Lane G — Close ADR-0035 (human review mandatory; ~0.5 day agent prep + review latency)

PR #104 already exists and is CI-green — this finishes a document in flight, not a new one.

- **G1 — Refresh ADR-0035's content for currency.** On `lane/beta-adr-0035`
  (`docs/adr/0035-beta-launch-accepted-risks.md`): resolve item 1's reconciliation-owner line
  using P0-2's answer; if the cutover to `makam.co.id` has in fact already happened, say so
  explicitly with a date, rather than leaving the doc's framing as if launch is still pending;
  update item 7 ("60 release gates unverified") only **after** Lane U runs, to read "N of 60
  verified, M formally excepted" — don't touch this item before the UAT pass produces real
  numbers; add one addendum item noting customer auth (`/akun`, /masuk, /daftar) shipped after
  this ADR's last update and touches a security-relevant surface, so its absence from the
  original risk list is a stated fact, not a silent gap.
- **G2 — Human review and merge.** The user's own action, per `AGENTS.md`'s explicit mandate —
  this plan gets the document accurate and ready, and stops there.

## Lane H — Traceability closeout (parallel to Lane U; ~0.5 day)

`docs/product/screen-inventory.md` already has PUB-097–PUB-105 (PR #115, open, CI-green,
mergeable — done, just needs a merge decision). `docs/domain/traceability-matrix.md` §B has zero
rows for `/akun` — its own §1 already names this exact class of gap for the homepage/booking/etc.
suites; `/akun` is the same gap, one PR-series newer.

- **H1 — Merge PR #115** once reviewed (documentation-only, describes already-shipped code).
- **H2 — Add `/akun` rows to `traceability-matrix.md` §B**, following that file's own strict
  discipline: read each test file against its claim before marking anything `Covered`, never
  trust a filename. Exact test paths (verified this session, not placeholders):

  | ID | Screen | Test file | What to verify before marking `Covered` |
  |---|---|---|---|
  | AKUN-01 | PUB-097 `/masuk` | `tests/Feature/Livewire/Public/Auth/LoginPageTest.php` | lockout, remember-me, identical error for wrong-password vs unknown-email |
  | AKUN-02 | PUB-098 `/daftar` | `tests/Feature/Livewire/Public/Auth/RegisterPageTest.php` | the documented duplicate-email exception to no-enumeration |
  | AKUN-03 | PUB-099/100 `/lupa-password`, `/reset-password/{token}` | `tests/Feature/Livewire/Public/Auth/PasswordResetTest.php` | byte-for-byte identical confirmation for known/unknown email; no auto-login after reset |
  | AKUN-04 | PUB-101 `/akun` | `tests/Feature/Livewire/Public/Akun/AkunIndexRouteTest.php`, `AkunIndexTest.php` | guest redirect preserves intended URL; tile counts scoped per-user |
  | AKUN-05 | PUB-102 `/akun/draft` | `tests/Feature/Livewire/Public/Akun/DraftListTest.php`, `tests/Feature/Livewire/Public/Booking/BookingWizardDraftBindingTest.php` | the ownership-rescue resume path, own-drafts-only scoping |
  | AKUN-06 | PUB-103 `/akun/pesanan` | `tests/Feature/Livewire/Public/Akun/OrderListTest.php`, `tests/Feature/Domain/OrderWorkflow/OrderForUserScopeTest.php` | own-orders-only scoping, `StatusIntent`-driven badge (not label text alone) |
  | AKUN-07/08 | PUB-104/105 `/akun/perpanjangan`, `/akun/dokumen` | `tests/Feature/Livewire/Public/Akun/DeferredSubPagesTest.php` | honest gate-closed state, never a raw 403/404 |

- **H3 — Add a dated v0.15 revision note** to `traceability-matrix.md` in its own established
  style (self-correcting, never silently rewriting a prior note).
- **H4 — Record PUB-050 as a named, deliberate gap**, not a silent one — Lane U's walkthrough
  should confirm nothing in `/akun/pesanan` or the booking-confirmation flow implies a working
  order-detail page exists where none does.

## Lane U — Full UAT execution pass (the core of this plan; ~2–3 days)

**Scaffolding to reuse, not reinvent:** `docs/testing/release-gates.md`'s ~60 checkboxes as the
skeleton; `docs/domain/traceability-matrix.md` §B (post-Lane-H) as the AC-level detail behind
each box; the beta plan's F1 journey list as the walkthrough script, **expanded** since `/akun`
postdates F1's authorship:

> booking 9 steps → order reference → confirmation email received → tracking page → marketplace
> browse/cart/checkout → renewal → FAQ → admin order transition → vendor portal
> **+ register → login (wrong-password, lockout) → password reset → `/akun` dashboard tile
> counts → resume a draft from `/akun/draft` → view `/akun/pesanan` → confirm the two gate-closed
> tiles render an honest explanation, not a dead end → logout.**

**Data:** existing dev fixture data — no new seeding. Any account/booking the walkthrough itself
creates uses throwaway synthetic names, never real ones, per `docs/testing/test-strategy.md` §5.

### Tasks

- **U0 — Confirm target environment** (0.25d). Dev/staging reachable, fixture data intact (not
  purged); if drifted, `migrate --force` a scratch copy first. Never walk this against `makam_beta`.
- **U1 — Walk every journey above**, recording evidence per step (what was clicked, what
  rendered, pass/fail against the traceability matrix's actual AC text) — including the
  gate-closed/fallback paths explicitly, since those are named requirements
  (`Specified (gated fallback)` rows, release-gates §C "Manual fallback"), not edge cases to skip.
- **U2 — Check off `release-gates.md`** as each gate is verified, or mark it explicitly
  `NOT TESTED`/`BLOCKED` with a reason (e.g. §H's PITR row — already an accepted ADR-0035
  deviation, cite it rather than leaving the box unexplained). Never mark PASS for anything not
  executed — `AGENTS.md`'s own instruction, written for exactly this discipline.
- **U3 — Produce a dated UAT report** at `docs/superpowers/reports/2026-08-20-beta-uat-pass.md`,
  matching this repo's own dated-evidence convention — the artifact that's been missing this whole
  time. Per journey: pass/fail/blocked-with-reason, final tally against the ~60 gates.
- **U4 — File findings as scoped follow-up work**, one small Lane-R task per finding with its own
  PR — never patch ad hoc mid-walkthrough; an unrecorded fix is exactly what this plan exists to
  stop doing.

### Execution mode — recommendation, not left open

Three real options: **(a) agent-driven browser walkthrough** via the `claude-in-chrome` skill,
clicking through every journey against the running dev app and writing U3 directly; **(b)**
expand the Playwright suite from its current single homepage spec to the six `E2E-*` suites
`test-strategy.md` §2 already specifies (durable, closes a real standing `AGENTS.md` requirement,
but 1–2 weeks of work, not days); **(c)** a written script a human runs manually — the *original*
F1 design, which is exactly the step that's sat undone since 18 Aug.

**Recommended: (a) now.** Fastest path from "zero of 60 gates checked" to a real evidenced pass,
no new tooling, runs on data that already exists. Treat (b) as a genuinely valuable **separate,
lower-priority follow-on plan** afterward — don't conflate it with what's blocking "complete" this
week. (c) isn't the primary mechanism, but the same script/skeleton serves it too if the user
prefers to run part of it personally.

## Lane R — Remediation (scoped per finding; size unknown until Lane U runs)

Each finding gets its own small plan/PR per Superpowers SDD. Anything touching
security/authorization/financial/privacy gets human review before merge, same bar every other
beta lane already applied. A finding that isn't a quick fix becomes a new named, accepted-risk
item — folded into ADR-0035 if launch-blocking, tracked as a follow-up if not — never silently
left unresolved and unrecorded.

## Verification

Nothing here is satisfied by the existing test suite — everything below runs on the live
walkthrough, not `php artisan test`:

| What | How | Pass condition |
|---|---|---|
| Dev fixture data intact | `SELECT` a known `CONTOH` cemetery/grave row before U1 starts | Rows present, matching `ExampleData` generators |
| Every journey in the expanded script | Lane U's browser walkthrough | Each step renders the expected state (success or documented gate-closed/fallback), evidence recorded in U3 |
| `/akun` traceability rows | Read `traceability-matrix.md` §B post-H2 against the actual test files | Each `Covered` row's cited test genuinely asserts the row's claim |
| `release-gates.md` tally | Count checked boxes after U2 | Every one of ~60 is checked, `NOT TESTED`, or `BLOCKED`-with-reason — none silently blank |
| ADR-0035 currency | Diff the merged version against the 19 Aug draft | Item 1 names a real owner or explicitly reaffirms the accepted risk with sign-off; item 7 reflects Lane U's real tally |
| No fake data leaked past dev | Confirm on the **live** host only (not part of Lane U) | `example-data:purge --force` state matches P0-1's answer |

## Execution notes

Superpowers SDD, matching this project's own established convention: commit this plan doc at
`docs/superpowers/plans/2026-08-20-beta-uat-and-governance-closeout.md`, worktree-isolated, one PR
per lane (H's traceability update, U's UAT report, each Lane-R fix) against
`docs/design-system-and-planning`. Lane G stops at "prepared, human review requested" — no agent
merges ADR-0035 or makes the go/no-go call. No feature rebuilding anywhere in this plan.
