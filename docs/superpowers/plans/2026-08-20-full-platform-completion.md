# Full-Fledged Platform Completion — Makam.co.id

## Context

This is a different, broader task from the just-completed "Close the Beta Verification &
Governance Gap" plan (PRs #104, #115, #116, #117, all merged 20 Aug 2026). That plan closed the
*governance* gap on what was already built. This plan answers a different question the user asked
directly: **what's left to make the whole platform actually complete, and how do we get real
end-to-end UAT coverage for all of it** — not just `/akun`.

Three parallel research passes (read against `origin/docs/design-system-and-planning`, current as
of this session) built the real picture, replacing three stale/unreliable documents:

- **`docs/domain/traceability-matrix.md`** (v0.14, 16 Aug) is the actively-maintained source of
  truth: 59 rows, 49 `Covered`, 2 `Specified (gated fallback)` (correctly gated — not gaps), 8
  `Specified` (genuine gaps, see below).
- **`docs/product/mvp-scope.md`** is **stale and partly wrong**: its §8 exclusion list names
  features (Pre-Need, Visitation, Memorial/QR) that are now built and `Covered` — treat that list
  as obsolete, not authoritative. Its actually-required admin items (reports, audit review,
  payment/manual verification) are **unbuilt with zero traceability row of their own** — a real
  gap in the gap-tracking, not just a feature gap.
- **`.kiro/specs/*/tasks.md` checkbox counts are unreliable** — five specs show 0% checked off
  despite the traceability matrix marking their capabilities `Covered` with passing, named tests
  (plot-inventory, visitation-booking, memorial-and-qr, certificates-and-agreements,
  pre-need-contracting). **Do not use kiro checkbox percentages to plan or report progress** —
  they track a superseded task breakdown. Traceability-matrix.md + screen-inventory.md are
  authoritative for "what's built."
- **`docs/planning/sprint-plan.md`**'s deployment log stops at 09 Aug 2026 — none of the P1–P5a
  batches (15–16 Aug, already shipped and `Covered`) appear in it. Stale by the same margin.

## What's genuinely unbuilt (feature-completion gap)

Everything below is a real gap, not deliberately-gated risk (deliberate gates — the online-payment
fallback on BOOK-08/REN-05, the two `/akun` gate-closed sub-pages — are already honestly recorded
and are **not** in scope for this plan; they're accepted product decisions, not defects):

1. **Marketplace checkout/payment/vendor-processing (`MKT-04`)** — browsing is fully built and
   `Covered`; cart, checkout, payment, and vendor order processing **do not exist at all**, and
   two tests actively assert their absence (`MarketplaceIndexRouteTest::test_the_landing_page_offers_no_cart_or_checkout_affordance`).
   Screens PUB-022 (Cart), PUB-024 (order tracking), PUB-050 (customer order status) are unbuilt.
   This is the single biggest actively-disabled customer-facing gap on the platform: a customer
   can browse the marketplace and cannot buy anything.
2. **Recurring care subscriptions (`CARE-SUB-01`–`07`, the P5b batch)** — 5 of 7 rows are fully
   unbuilt (subscription creation, payment-webhook validation, work-order-from-cycle creation,
   evidence upload, vendor replacement audit). Only cycle generation and customer
   acceptance/complaint have test files, and even those stay `Specified`, not `Covered`.
3. **Admin: Reports (`ADM-090`)**, **Audit/sensitive-action review (`ADM-100`)**,
   **Payment/transaction/manual verification (`ADM-070`)**, **Dashboard summary (`ADM-001`)** —
   all unbuilt. The first three are `mvp-scope.md` §6-required and have no traceability row at
   all, only a disclaimer buried in `ADMIN-01`'s prose excluding them from its `Covered` claim.
4. **Vendor: Profile/account screen (`VND-080`)** — unbuilt, no profile screen in the vendor
   panel at all.
5. **Payment reconciliation *process*** (ADR-0035 item 1) — narrower than it sounds. The
   comparison *engine* is real, tested, well-designed
   (`app/Platform/FinancialLedger/{Actions/RunReconciliation,Jobs/ReconcileStatementJob,Models/Reconciliation}.php`,
   idempotent re-runs, exception recording). What's missing is specifically: (a) a live adapter
   that fetches a real `ProviderStatement` from the payment provider — today it's a plain value
   object with no data source — and (b) scheduling `ReconcileStatementJob` anywhere (nothing
   dispatches it). This is a close-to-shippable gap, not a from-scratch build.

**Deliberately out of scope for this plan** (name, don't silently drop):
- Chasing `.kiro/specs/*/tasks.md` to 100% checked — proven unreliable/superseded this session;
  fixing it is a documentation-maintenance exercise, not platform completion.
- Turning on real online payment in production (`G-PAY-01`) — an accepted, recorded risk
  (ADR-0035 item 1), a business/legal decision, not a build gap.
- Anything in `mvp-scope.md` §8's exclusion list that's already built and `Covered` (Pre-Need,
  Visitation, Memorial/QR, Plot inventory) — already done, needs no further work here.
- Full legal review, DPIA, real MFA enforcement on beta admin — already recorded, accepted
  deviations in ADR-0035 items 6, 9, 10.

## The E2E UAT gap (what the user explicitly asked to cover)

- **Exactly one permanent browser spec exists** (`tests/browser/smoke.spec.ts`, homepage only).
  The 20 Aug `/akun` UAT pass (13 real browser tests, 0 axe violations) was deliberately a
  one-off, not committed to CI.
- **All six planned `E2E-*` suites** (`test-strategy.md` §2: `E2E-HOME`, `E2E-BOOK`, `E2E-MKT`,
  `E2E-REN`, `E2E-FAQ`, `E2E-ADMIN/VENDOR`) are unbuilt as durable specs. Their acceptance
  criteria are already fully defined in that file — this plan does not need to invent them, only
  build against them.
- **0 of 60 `release-gates.md` boxes are checked.**
- Feature/Livewire-level (server-side, no real browser) coverage is genuinely strong for booking,
  marketplace-browse, renewal, FAQ, and cemetery-directory — but stitched across many files per
  journey rather than one continuous test, and a few ACs are explicitly `NOT TESTED` in the
  matrix itself (renewal search-at-100k-records performance; a cemetery/map benchmark).
- `release-gates.md` §C (payment), §D (notifications), §G (security/ops), §H (technical
  production-readiness), §I (host acceptance) are almost entirely infra/ops/security checks, not
  pure UI journeys — several are already partially informed by this session's direct host
  investigation (dev/beta DB state, image digests, host config) and should lean on that rather
  than re-deriving from zero.

**The lesson already paid for, to reuse:** any browser-driven UAT work must target the real
domain (`https://dev.makam.co.id`), never the raw container port — hitting `:8081` directly
breaks Livewire's asset loading (CORS/origin mismatch) and produces false-negative results that
look like product defects but aren't. Baked into every suite from the start this time.

## Priority ordering

**Phase 0 — Documentation truth pass (do first, cheap, unblocks everything else being planned
accurately):**
- Add traceability rows for `ADM-090`, `ADM-100`, `ADM-070` (status: `Specified`, honestly
  unbuilt — this stops the next agent from being misled by `ADMIN-01`'s broad claim).
- Add a dated revision note to `mvp-scope.md` marking §8's exclusion list obsolete, per this
  file's own established self-correcting-note convention (never silently rewrite).
- Add a dated status note to `sprint-plan.md`'s deployment log pointing at
  `docs/domain/traceability-matrix.md` as the current source of truth for "what's shipped."
- Small, single PR, no code.

**Phase 1 — Payment reconciliation process (highest value-to-effort ratio):** build the live
provider-statement adapter and schedule `ReconcileStatementJob`. Closes real ground on ADR-0035
item 1 without touching the (already well-tested) comparison engine.

**Phase 2 — Marketplace checkout (`MKT-04`)**: the biggest real customer-facing gap. Cart →
checkout → payment (online sandbox + manual fallback, mirroring booking's already-proven Step 8
pattern) → vendor order processing → customer order tracking (PUB-022/024/050). Sized like a full
feature build — its own multi-task SDD plan, not a single PR.

**Phase 3 — Recurring care subscriptions (`CARE-SUB`, P5b):** subscription creation → payment
webhook → work-order-from-cycle → evidence upload → vendor replacement audit. Also a full,
separate SDD plan — 5 of 7 capabilities from scratch.

**Phase 4 — Admin completeness:** Dashboard summary (`ADM-001`), Reports (`ADM-090`), Audit
review (`ADM-100`), Vendor profile screen (`VND-080`). Internal tooling, lower customer impact
than Phases 1–3, sequenced last among feature work.

**Phase E (interleaved with Phases 0–4, not sequential-only) — the durable E2E suite:** build all
six `E2E-*` Playwright specs against `test-strategy.md` §2's already-defined ACs, wire them into
CI as permanent specs (today only the homepage smoke test runs), and use them to close
`release-gates.md` §A/§B honestly as each suite lands. Sequencing:
- **Start now, don't wait on Phases 1–4:** `E2E-HOME`, `E2E-FAQ`, `E2E-REN`, and the *built*
  portion of `E2E-BOOK` (Steps 1–9 as they exist today, including the manual-fallback payment
  path) — nothing about these is blocked on unbuilt features.
- **`E2E-ADMIN/VENDOR`:** build against what exists now (login + built modules), extend as Phase 4
  lands.
- **`E2E-MKT`:** build the browse-only portion now (matching what's actually built); extend to
  cover checkout/payment/vendor-processing once Phase 2 ships — building it fully before Phase 2
  exists would just be testing against nothing.
- Each suite becomes its own small SDD task/PR, reusing the `.uat-creds.json`-style throwaway
  account pattern and the `dev.makam.co.id`-not-`:8081` lesson from the 20 Aug pass.

**Phase G — `release-gates.md` closure pass:** once Phase E's suites exist, walk §A/§B against
them properly (check real boxes, not aspirational ones). §C–§I (payment, notifications,
security/ops, technical readiness, host acceptance) need a separate, later pass — most of those
are infra/ops checks this plan doesn't scope in detail; treat as a follow-on plan once Phases 1–4
give the underlying features something to actually gate-check.

## What this plan does NOT commit to yet

This is a roadmap across a genuinely large remaining scope (5 feature areas, 6 new durable test
suites). Per Superpowers SDD's own discipline (plan doc → worktree → one PR per unit of work), no
single execution pass covers all of this. **This plan's immediate, approved-today scope is Phase
0 plus starting Phase E's unblocked suites (E2E-HOME/FAQ/REN/BOOK) and Phase 1** — the
cheap/high-value/low-risk work that needs no further scoping decisions. Phases 2, 3, 4, and the
rest of Phase E/G each get their own follow-up plan doc once their predecessor phase's shape is
clearer, following this same repo's established pattern (see the beta plan's own Lane R: "size
unknown until X runs").

## Verification

| What | How | Pass condition |
|---|---|---|
| Phase 0 traceability additions | `ci/verify-docs.sh` Gate 7 | New `ADM-090`/`100`/`070` rows don't claim `Covered` without a real test — they should be `Specified` |
| Phase 1 reconciliation adapter | Feature test against a fixture provider response | Job runs on schedule, produces the same exception-recording behavior the existing tests already prove for the comparison logic |
| Phase E suites | `npx playwright test` against `https://dev.makam.co.id` (never `:8081`) | Each suite's ACs from `test-strategy.md` §2 pass; wired into `.github/workflows/ci.yml` as a permanent job, not a one-off |
| No regressions | Existing `php artisan test` suite (~2,786 methods) stays green in CI | Every phase's PR passes full CI before merge |

## Execution notes

Superpowers SDD, one PR per phase/lane, worktree-isolated, task-scoped review then whole-branch
review, against `docs/design-system-and-planning`. Commit this plan doc at
`docs/superpowers/plans/2026-08-20-full-platform-completion.md`. Security/authorization/financial
work (Phase 1's reconciliation adapter touches real payment-provider data shape; Phase 2's
checkout touches money) gets human review before merge, same bar as every prior lane.
