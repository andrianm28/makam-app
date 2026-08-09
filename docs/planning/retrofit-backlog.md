# Superpowers Retrofit Backlog

**Version:** v0.1
**Date:** 09 Aug 2026
**Governing methodology:** `AGENTS.md` §Development methodology
**Origin plan:** `docs/superpowers/plans/2026-08-09-retrofit-cemetery-directory-capability.md` (the pilot retrofit that established the recipe this backlog sequences)

This is the durable, single record of (1) which already-shipped modules still need
a real Superpowers SDD retrofit, in what order, and (2) the specific gaps each
completed retrofit disposed rather than built inline. `AGENTS.md` §Documentation
forbids duplicating this in a second hand-maintained document — update this file,
do not create a rival one. Per-spec acceptance-criteria status stays in each Kiro
spec's own `tasks.md`; this file tracks the retrofit program across modules, not
per-AC detail.

---

## §1. Cross-module retrofit sequencing

Order and rationale as decided in the governing plan's "Sequencing" section.
Each module's retrofit gets its own plan doc at
`docs/superpowers/plans/<date>-retrofit-<module>.md`, its own worktree/branch/PR,
and the same recipe the pilot exercised: two-tier review
(task-scoped via `dispatching-parallel-agents`, then one whole-module review),
one bounded fix wave with regression tests, one scoped re-review, explicit
disposition of every self-flagged gap, and an append-correction on the module's
`sprint-plan.md` row.

| # | Module(s) | Status | Notes |
|---|---|---|---|
| 1 | `CemeteryDirectory` + `CemeteryCapability` | ✅ **Done** — pilot, 09 Aug 2026 | See §2 below for disposed gaps. PR # and CI run recorded on `sprint-plan.md`'s S4-T6 row once merged. |
| 2 | `IdentityAccess/Mfa` + `IdentityAccess/Reauthentication` | ✅ **Done** — 09 Aug 2026 | Wired real self-service MFA + `RequireRecentAuthentication`'s first real attachment. See §2 below for the full scope decision and disposition. PR [#7](https://github.com/andrianm28/makam-app/pull/7), CI run [`31299824997`](https://github.com/andrianm28/makam-app/actions/runs/31299824997), also recorded on `sprint-plan.md`'s S3-T2/S3-T3 rows. |
| 3 | `Faq` | Not started | Earliest, least-verified batch. Closes finding N-13 (confirm the `Faq` row already exists in `docs/architecture/overview.md` §5 — likely just needs a correction-append, not new work) and gives the self-fixed N-14/N-15 Blade-compiler bugs a real independent review pass. |
| 4 | `GraveRegistry` | Not started | The "fix its stale stub test" commit message is itself worth reviewing for what it implies about the current test's origin. Disposes the self-flagged `NOT TESTED` AC4 gap. |
| 5 | `Outbox` | Not started | Architecturally sound (real `SELECT...FOR UPDATE SKIP LOCKED` claim-and-retry) but has exactly one real caller that has never fired in any deployed environment (0 rows in `outbox_events`, live-queried). Retrofit wires a second real producer — candidate: extend `FeatureGate`'s `GateActivationRecorder` pattern to a second real trigger. Confirm the specific choice with the user before implementing. |
| 6 | `ServiceCatalog`, `Marketplace`, `Renewal` | Not started | Mid-risk, three separate back-to-back retrofit units (not one combined unit — each gets its own plan/worktree/PR). |
| 7 | `Audit`, `FeatureGate`, `Correlation`, `Analytics`, `IdentityAccess/Scopes` | Not started | Lowest priority — real CI evidence already exists for these; retrofit here is lighter-weight (review + linking the real run IDs into `sprint-plan.md` rather than expecting many findings). |

---

## §2. Per-module disposed gaps

Populated as each module's retrofit completes. Each entry gives every
self-flagged gap an explicit disposition — closed with evidence, or ledgered
with a named owner and reason — rather than silent carry-forward.

### `cemetery-directory-and-availability` (CemeteryDirectory + CemeteryCapability), retrofitted 09 Aug 2026

Source: `.kiro/specs/cemetery-directory-and-availability/tasks.md`'s self-reported
gaps, plus AC10 (confirmed absent by the retrofit's domain-slice reviewer,
per the whole-module review's Ruling §12), reviewed and dispositioned by
`.superpowers/sdd/retrofit-cemetery-directory-capability/task-2-whole-module-review.md`.

| Gap | Disposition | Owner / reason |
|---|---|---|
| AC6/AC7 — optional plot source adapter interface | **Ledgered, not built.** | Owner: `plot-inventory-and-reservation` (unbuilt spec). `design.md`'s table-ownership rule already assigns `blocks`/`plot_units`/`plot_status_events` there; confirmed accurate by this retrofit's schema reviewer (`grep` confirms none of those tables exist anywhere in this repo). |
| AC8 — stale-source monitoring and fallback | **Ledgered, not built.** | Owner: `plot-inventory-and-reservation`. Depends on the AC6/AC7 adapter existing first; there is no freshness timestamp, monitor, or reservation-disabling path to build against yet. |
| AC9 — operator write-scoping + audit | **Ledgered, not built.** | Owner: `admin-operations`. No write-side capability Action exists (`app/Domain/CemeteryCapability/Actions/` contains only `ResolveCemeteryCapabilityProfile`, confirmed by directory listing) — deliberately out of this batch's and this retrofit's scope. |
| AC10 — admin manages cities/content/facilities/prices/capabilities without deployment | **Ledgered, not built. Confirmed absent, not just undocumented.** | Owner: `admin-operations`. `find app/Filament -type f` returns only `Admin/Resources/FaqArticles/**`; `grep -rn "Cemetery" app/Filament` returns nothing. |
| Directory/map query benchmarking (AC2/AC3/AC11) | **Ledgered, not built.** | No owner module yet — needs a benchmark harness this repo doesn't have. Genuinely `NOT TESTED`, not a hedge: no timing/benchmark assertion exists anywhere in the module's 10 test files. |
| 3 missing UI states (§6.6 duplicate/retry-safe, §6.8 success, §6.9 gated-fallback banner) | **Ledgered, correct deferral — not a fix-wave item.** | This module's own future UI work. The retrofit's Review scope explicitly forbids the fix wave from building new UI states; defensible today on a read-only browse surface with no mutation, no gate, and no success outcome. |
| 44px touch targets + focus ring (accessibility §7) | **Ledgered, blocked on tooling.** | No owner module — this repo has no Dusk/Playwright/Cypress harness at all, confirmed by this retrofit's tests-slice reviewer. A repository-level tooling gap, not specific to this module. |
| DB-level closed-list guard (CHECK constraint / partial unique index on `cemetery_capability_profiles`) | **Ledgered — needs a human ruling, not a fix-wave item.** | Found live during this retrofit (validation lives only in Eloquent `saving()` hooks; both query-builder writes and this module's own seed migrations bypass it). Held out of the bounded fix wave because it is a new migration against a table already deployed to `dev.makam.co.id` — `AGENTS.md` §Infrastructure-agent execution requires human review before production-affecting migration changes. |
| `<x-mk.filter-chip>` migration for the directory's filter chips | **Ledgered — needs a design ruling, not a fix-wave item.** | Found live during this retrofit (`index.blade.php` still hand-rolls the chip recipe `ba662d1` migrated everywhere else). Held out because every fix touches a shared single-writer primitive file or changes this page's interaction model (button→link chips) — a design decision, not a mechanical fix. |
| `phpstan.neon` `paths: [app]` excludes all Blade files from static analysis | **Ledgered — program-level, not this module's to fix.** | This is the structural reason `ba662d1`'s `$code => $label` bug reached production invisibly, and the whole-module review found a second, still-live instance (`$cards`, fixed in this retrofit) purely by inspection. Repository-wide config change; out of scope for a single-module retrofit. |

Full evidence trail: `.superpowers/sdd/retrofit-cemetery-directory-capability/` (worktree-local, git-ignored — task briefs, reports, whole-module review, re-review) and the retrofit's own commit history on branch `retrofit-cemetery-directory-capability`.

### `IdentityAccess/Mfa` + `IdentityAccess/Reauthentication`, retrofitted 09 Aug 2026

Unlike the pilot, this was real new feature work on built-but-unreachable security modules, not a review-and-fix pass on already-shipped code — it went through its own `superpowers:brainstorming` pass with the user before any implementation, per `AGENTS.md`'s mandatory-human-review rule for security/authorization-adjacent changes. Full design: `docs/superpowers/specs/2026-08-09-mfa-reauthentication-integration-design.md`. Full plan: `docs/superpowers/plans/2026-08-09-mfa-reauthentication-integration.md`. Executed via `subagent-driven-development`: 7 implementation tasks, each with its own task-scoped review (one fix round each on Tasks 1 and 5), plus a whole-branch synthesis before merge.

| Original row's requirement | Disposition | Owner / reason |
|---|---|---|
| "Mandatory MFA for all privileged roles" (`sprint-plan.md` S3-T2) | **Ledgered, not built — scope narrowed deliberately during the retrofit's `brainstorming` pass.** | No role model exists (`ActorContext::$roles` is still hardcoded `[]`, confirmed unchanged by this retrofit). Owner: whichever future spec resolves K1/K2 role mapping — not named yet. This retrofit ships voluntary self-service MFA instead, which is real progress toward the same end state without inventing a role model that belongs to a different spec. |
| Re-authentication on "the six sensitive action classes" (`sprint-plan.md` S3-T3, `docs/security/authentication-and-mfa.md` §5) | **Ledgered, not built — scope narrowed deliberately.** | None of the six action classes (payout/refund approval, bank-detail change, gate/payment-config change, specific-plot override, certificate issue/revoke/replace, bulk export) has a real controller anywhere in this repo, confirmed unchanged by this retrofit. Owner: each action's own future spec (`admin-operations` and others), which attaches `RequireRecentAuthentication` itself once it ships a real route — exactly as that middleware's own doc block already specified. This retrofit gives `RequireRecentAuthentication` its first real attachment on the one sensitive action that already exists today (disabling one's own MFA), proving the mechanism works end to end. |
| `/operator` and `/vendor` panel MFA/reauthentication | **Ledgered, not built.** | Neither panel exists yet in this repo (`find app/Providers/Filament` returns only `AdminPanelProvider.php`). Owner: whichever spec builds those panels. |

Full evidence trail: retrofit's own commit history on branch `mfa-reauthentication-integration` (merged, branch deleted per this repo's worktree-cleanup convention) and PR #7's description/comments. The plan's own ledger (`.superpowers/sdd/2026-08-09-mfa-reauthentication-integration/progress.md`) was worktree-local and removed with the worktree after merge, per `subagent-driven-development`'s own "Finish" step — git history is the durable record.
