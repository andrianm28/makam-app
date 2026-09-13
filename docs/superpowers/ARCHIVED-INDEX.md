# Archived Superpowers plans and reports — index

**These 60 files are not in the working tree any more. They are not lost.**
Every one of them is still in git history and retrievable by the command
below; nothing was deleted in the sense that matters.

Removed by ADR-0039. See that ADR for why, and for the rule in `AGENTS.md`
§Development methodology that this implements rather than overrides.

## How to read one

```bash
# the commit that removed them
git log --oneline --diff-filter=D -- docs/superpowers/plans | head -1

# read a file as it was, without checking anything out
git show <that-commit>^:docs/superpowers/plans/<name>.md

# or search every archived file at once
git grep -n "<pattern>" <that-commit>^ -- docs/superpowers/
```

`git show` and `git grep` against a commit do not touch your working tree and
do not put the file back. That is the point: retrievable when a human asks,
invisible to the tools that would otherwise sweep it into an agent's context.

## Why these 60 and not the other 75

Two filters, both mechanical:

1. **Nothing living cites them.** 66 of the 135 files under
   `docs/superpowers/` are referenced from `app/`, `database/`, `tests/`,
   `docs/` or `.kiro/` — code doc blocks cite the plan that explains a
   decision. Those 66 stay; they are load-bearing.
2. **The set is closed under linking.** Of the 69 unreferenced files, 9 are
   still linked from a file that stays, so removing them would have broken
   `ci/verify-docs.sh` GATE 4. They stay too. The 60 below link only to each
   other and to files that remain.

## The 60

| Path | ~tokens |
| --- | --- |
| `docs/superpowers/plans/2026-08-08-public-booking-wizard-steps-1-5.md` | 30,088 |
| `docs/superpowers/plans/2026-08-12-platform-booking-completion.md` | 3,814 |
| `docs/superpowers/plans/2026-08-12-platform-marketplace-checkout.md` | 35,391 |
| `docs/superpowers/plans/2026-08-12-platform-vendor-portal.md` | 1,827 |
| `docs/superpowers/plans/2026-08-13-booking-step5-handoff-fix.md` | 358 |
| `docs/superpowers/plans/2026-08-13-cemetery-example-data-dehardcode.md` | 21,095 |
| `docs/superpowers/plans/2026-08-14-vendor-listing-bootstrap.md` | 1,121 |
| `docs/superpowers/plans/2026-08-20-akun-auth-foundation.md` | 6,679 |
| `docs/superpowers/plans/2026-08-20-akun-pesanan.md` | 3,692 |
| `docs/superpowers/plans/2026-08-20-akun-shell-and-drafts.md` | 5,344 |
| `docs/superpowers/plans/2026-08-20-beta-uat-and-governance-closeout.md` | 3,886 |
| `docs/superpowers/plans/2026-08-20-e2e-mkt-customer-journey.md` | 8,238 |
| `docs/superpowers/plans/2026-08-20-full-platform-completion.md` | 3,096 |
| `docs/superpowers/plans/2026-08-21-brand-visual-refresh-phase1-foundation.md` | 6,781 |
| `docs/superpowers/plans/2026-08-21-e2e-admin-vendor-journey.md` | 9,193 |
| `docs/superpowers/plans/2026-08-22-phase1-uat-pass.md` | 9,551 |
| `docs/superpowers/plans/2026-08-23-observability-and-adr-fixes.md` | 10,020 |
| `docs/superpowers/plans/2026-08-23-phase3-engineering-prep.md` | 9,473 |
| `docs/superpowers/plans/2026-08-23-release-gates-engineering-closeout.md` | 12,208 |
| `docs/superpowers/plans/2026-08-24-release-gates-batch3-closeout.md` | 5,582 |
| `docs/superpowers/plans/2026-08-24-release-gates-phase2-closeout.md` | 8,986 |
| `docs/superpowers/plans/2026-08-25-adm-070-payment-verifications-admin-view.md` | 4,766 |
| `docs/superpowers/plans/2026-08-28-orders-dashboard.md` | 33,803 |
| `docs/superpowers/plans/2026-08-28-plot-availability-dashboard.md` | 38,241 |
| `docs/superpowers/plans/2026-09-02-wizard-step-reduction.md` | 26,920 |
| `docs/superpowers/plans/2026-09-03-demo-seed-data.md` | 36,924 |
| `docs/superpowers/plans/2026-09-05-marketing-hero-plot-preview.md` | 7,062 |
| `docs/superpowers/plans/2026-09-05-memorial-visit-checkin.md` | 11,460 |
| `docs/superpowers/plans/2026-09-06-batch2a-stepup-authentication.md` | 1,995 |
| `docs/superpowers/plans/2026-09-06-batch2b-marketplace-idor-money-rendering.md` | 3,265 |
| `docs/superpowers/plans/2026-09-06-batch2d-booking-wizard-step2-validation.md` | 1,531 |
| `docs/superpowers/plans/2026-09-06-batch2f-visitation-policy-display.md` | 652 |
| `docs/superpowers/plans/2026-09-06-sec02-bank-transfer-reauth.md` | 1,409 |
| `docs/superpowers/plans/2026-09-06-uat-pass-competitive-redesign-batch.md` | 5,063 |
| `docs/superpowers/plans/2026-09-07-batchm1a-outbox-event-completeness.md` | 2,230 |
| `docs/superpowers/plans/2026-09-07-batchm1c-outbox-scheduler-robustness.md` | 3,286 |
| `docs/superpowers/plans/2026-09-07-batchm2a-cemetery-scope-authorization.md` | 2,081 |
| `docs/superpowers/plans/2026-09-07-batchm3a-db-integrity-migration-hygiene.md` | 3,706 |
| `docs/superpowers/plans/2026-09-07-batchm3b-plot-reservation-domain-guards.md` | 2,276 |
| `docs/superpowers/plans/2026-09-07-batchm4a-openapi-gate-healthcheck.md` | 1,627 |
| `docs/superpowers/plans/2026-09-07-batchm4b-event-catalogue-reconciliation.md` | 2,278 |
| `docs/superpowers/plans/2026-09-07-batchm4c-contract-doc-reconciliation.md` | 1,968 |
| `docs/superpowers/plans/2026-09-07-batchm7a-query-performance-fixes.md` | 5,762 |
| `docs/superpowers/plans/2026-09-07-batchm7b-infra-config-performance-fixes.md` | 3,905 |
| `docs/superpowers/plans/2026-09-07-batchm8a-observability-pii-leak-fixes.md` | 1,966 |
| `docs/superpowers/plans/2026-09-07-booking-wizard-progressive-reveal-scroll.md` | 1,222 |
| `docs/superpowers/plans/2026-09-07-booking-wizard-remove-contact-channel-field.md` | 843 |
| `docs/superpowers/plans/2026-09-07-plot-reservation-expiry-orphaned-draft.md` | 1,316 |
| `docs/superpowers/plans/2026-09-08-cemetery-card-real-stock-photos.md` | 842 |
| `docs/superpowers/plans/2026-09-08-plot-picker-null-package-regression-fix.md` | 708 |
| `docs/superpowers/plans/2026-09-08-plot-picker-package-scope-fix.md` | 765 |
| `docs/superpowers/reports/2026-08-12-payment-auth-final-fix-round.md` | 5,294 |
| `docs/superpowers/reports/2026-08-12-payment-auth-fix-round.md` | 7,060 |
| `docs/superpowers/reports/2026-08-12-payment-auth-implementation.md` | 5,141 |
| `docs/superpowers/reports/2026-08-12-payment-auth-task-scoped-review.md` | 10,385 |
| `docs/superpowers/reports/2026-08-12-payment-auth-verification.md` | 2,251 |
| `docs/superpowers/reports/2026-08-12-payment-auth-whole-branch-review.md` | 11,436 |
| `docs/superpowers/reports/2026-08-20-beta-uat-pass.md` | 2,324 |
| `docs/superpowers/specs/2026-08-15-host-ram-reclamation-design.md` | 1,731 |
| `docs/superpowers/specs/2026-08-29-sveltekit-rewrite-feasibility-and-plan.md` | 15,037 |
**Total: ~467,000 tokens** removed from the working tree.
