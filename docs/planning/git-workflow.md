# Git Version Control Plan

**Version:** v0.1
**Date:** 25 Juli 2026
**Purpose:** bring this repository's git and GitHub workflow to industry-standard practice, grounded in this repo's actual verified settings — not a generic checklist.
**Companion to:** [`sprint-plan.md`](sprint-plan.md) §10 (human review gates) and [`agent-execution-plan.md`](agent-execution-plan.md) §1 (never-agentize rules)

---

## 0. The constraint that shaped this plan when it was written — now closed

**Update (6 Sep 2026, CI-05 remediation,
docs/superpowers/plans/2026-09-06-batch2g-cicd-hardening.md):** this
section's premise is stale. The repo is now confirmed **public**:

```
$ gh repo view --json visibility
{"visibility":"PUBLIC"}
```

That reopens both classic branch protection and the repository-rulesets
API — neither is gated behind a paid plan for a public repo. **Branch
protection has not been enabled yet**; that is a separate, human-executed
action (`gh api --method PUT`, per
`docs/operations/runbooks/setup-cicd-self-hosted-runner.md:149-166`, tracked
as Task 0.4 in the remediation plan above) — AI agents prepare this kind of
change but do not execute it themselves (`AGENTS.md` §Infrastructure-agent
execution). Until that human step runs, §4's discipline-only substitute
below is still the operative process. Secret scanning availability was not
re-verified as part of this correction; treat that specific line item as
unconfirmed under the new visibility, not as still-blocked.

The original private-repo evidence this section quoted (`isPrivate → true`,
the two 403s) is kept below purely as a historical record of what was true
on 25 Jul 2026 — it no longer describes this repository's current state:

```
$ gh api repos/andrianm28/makam-app/branches/master/protection   # 25 Jul 2026, when private
{"message":"Upgrade to GitHub Pro or make this repository public to enable this feature.", "status":403}

$ gh api repos/andrianm28/makam-app/rulesets   # 25 Jul 2026, when private
{"message":"Upgrade to GitHub Pro or make this repository public to enable this feature.", "status":403}

$ gh repo view --json isPrivate → true   # 25 Jul 2026
$ gh api repos/andrianm28/makam-app/secret-scanning/alerts   # 25 Jul 2026
{"message":"Secret scanning is disabled on this repository.", "status":404}
```

**Consequence, until Task 0.4 lands:** every control below that would
normally be *enforced by GitHub* still has to be enforced by **process
discipline** instead — CI status visible in the PR UI, a PR template that
structurally forces the checks `AGENTS.md` already requires, and a human
actually looking before merging. Once Task 0.4's branch protection is live,
§4's table should be revisited row by row and the enforced columns adopted
in place of the discipline-only substitutes.

---

## 1. What is already good — keep it

Verified from this repo's own history; this plan builds on these, it does not replace them.

| Practice | Evidence |
|---|---|
| Detailed, reasoned commit messages | Every commit in this session's history states *why*, not just *what*, and includes explicit **NOT TESTED** sections — matching `AGENTS.md`'s *"never report PASS for a check not executed."* |
| AI-authorship trailers | Every commit carries `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>` — this is GitHub's own recommended pattern for AI-assisted commits, already followed correctly. |
| No secrets in git history | Verified repeatedly by `ci/verify-docs.sh` GATE 10 and by hand before every commit touching `.claude/settings.json`, compose files, or nginx config. |
| `.gitattributes` exists | `* text=auto eol=lf` plus per-extension diff drivers, from the Laravel skeleton — a reasonable baseline already. |
| CI on every push and PR | `.github/workflows/ci.yml`, verified green (run `30147330644` and others) after two real fix cycles. |
| Rollback discipline | Every infrastructure change (nginx, compose, secrets) is preceded by a timestamped backup and a stated rollback command, before the change is made. |

---

## 2. Branching model: GitHub Flow, not GitFlow

**Recommendation: GitHub Flow** — short-lived feature branches off `master`, opened as a PR immediately, merged once CI is green and (per §4) a human has looked at it.

**Why not GitFlow:** GitFlow's `develop`/`release`/`hotfix` branch hierarchy exists to support parallel maintenance of multiple shipped versions. This project has shipped nothing yet — `sprint-plan.md` §9 puts the first real release around Sprint 16. Adding GitFlow's ceremony now would slow down a pre-release project for a problem it doesn't have. Revisit if/when this project needs to maintain a stable production branch alongside active development.

**Why not trunk-based-with-direct-commits:** `AGENTS.md` requires human review before security/authorization/financial/DNS/firewall/production changes. A PR is the natural unit that review attaches to; committing straight to `master` has nowhere for that review to happen.

### 2.1 The current PR is oversized, and that is a one-time situation, not a pattern to repeat

```
$ git log --oneline master..docs/design-system-and-planning | wc -l
14
```

PR #1 spans the design system, 8 platform specs, planning docs, the Laravel scaffold, CI, `dev.makam.co.id`, and three UI primitives — a coherent "bootstrap the project" arc, but far larger than one reviewable unit by normal standards.

**Recommendation:** merge PR #1 as-is. Splitting 14 already-committed, already-CI-verified commits into separate PRs retroactively would cost more effort than it returns, and the commits are individually well-scoped even though the PR bundling them is not. **Every PR after this one should be scoped to a single sprint task or a single tightly-related group** (§3).

---

## 3. PR scope and naming — extend this project's own ID system into git

This repo already numbers everything: `S1-T6`, `S2-T2a`, `ADR-0030`, finding IDs like `N-4`. Git should use the same vocabulary rather than inventing a separate one.

**Branch naming:** `<type>/<task-id>-<short-slug>`, e.g. `feature/s2-t2b-primitives`, `fix/n4-egress-network`, `docs/adr-0031-payment-provider`.

**PR title:** `[<task-id>] <imperative summary>`, e.g. `[S2-T2b] Add field, card, modal, table, badge, alert, stepper, header primitives`.

**One task, one PR, wherever practical.** When a batch of concurrent subagents produces several related files (as in `agent-execution-plan.md`'s batches), that whole batch is one PR — the batch, not the individual task, is the reviewable unit, since that is how the work was actually planned and gated.

---

## 4. Until branch protection is actually enabled: the process substitute

**Update (6 Sep 2026, CI-05/COORD-17 remediation):** as of §0, this repo is
confirmed public and branch protection is no longer plan-gated — but it has
not been switched on yet (Task 0.4 in
`docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md` is the
human-executed `gh api --method PUT` that does it). This section's table
describes today's interim state, not a permanent limitation. Once Task 0.4
lands, replace the right-hand column's discipline-based substitutes with the
real enforced controls the left-hand column names, starting with "Human
review before merge" — the recommended `gh api` payload's
`required_pull_request_reviews[required_approving_review_count]=1` closes
that row and COORD-17 (who reviews security/financial changes) in the same
action.

Each row is a control classic branch protection enforces automatically once
enabled; the right column is what holds it in place in the meantime.

| Control | Would be (branch protection enabled) | Actual substitute (until Task 0.4 lands) |
|---|---|---|
| CI must pass before merge | Required status check, merge button disabled otherwise | CI result is visible on the PR page; **do not merge a red PR**. This is a discipline rule, not a gate — write it into the PR template (§5) so it cannot be silently skipped. |
| No direct push to `master` | Protected branch rejects the push | Same discipline: always branch, always PR. `master`'s only history should be merge commits. |
| Human review before merge | Required approving review | The ten `AGENTS.md`-mandated human gates (`sprint-plan.md` §10) already require a human to look at anything sensitive. Extend that: **every PR gets a human look before merge**, not just gated ones — cheap to hold as a norm, expensive to recover from if skipped. |
| No force-push to shared history | Protected branch rejects force-push | `AGENTS.md`'s existing rule already covers this: never force-push to `master`. Force-push on a personal feature branch before it's reviewed is fine. |
| Secret scanning / push protection | GitHub Advanced Security | `ci/verify-docs.sh` GATE 10 plus manual review already do this locally; §6 adds a client-side pre-commit hook as defence in depth. Secret scanning's availability was not re-verified under the new public visibility as part of this correction — confirm separately before relying on it. |

**Status:** no longer an open question about plan/visibility (§0 resolved
that) — now a pending human execution step. Tracked as Task 0.4 in the
remediation plan, superseding **OQ-G1**'s original framing (§8).

---

## 5. PR template — mechanise what this project already does by habit

This repo's commit messages already, consistently, state what changed, how it was verified, and what was **NOT TESTED**. That is exactly `AGENTS.md`'s own required shape. Turning it into a PR template makes it structural instead of a habit that can lapse.

**Action:** add `.github/PULL_REQUEST_TEMPLATE.md`:

```markdown
## Task
<!-- Sprint task ID(s) and/or finding ID(s), e.g. S2-T2b, N-4 -->

## What changed


## Verified
<!-- Exact commands run and their output. "It should work" is not verification. -->


## NOT TESTED
<!-- Per AGENTS.md: never claim PASS for a check you did not execute. -->


## Human gate
<!-- If this touches anything in sprint-plan.md §10 (security, auth, financial,
     DNS, firewall, production, live infrastructure): which gate, and confirm
     a human — not an agent — is approving this merge. Otherwise: N/A. -->
```

---

## 6. Commit conventions

### 6.1 Adopt Conventional Commits for the subject line — keep the detailed body

Current commit bodies are already better than what most Conventional-Commits-only projects write. What's missing is a **structured, machine-parseable subject line**, which is what unlocks automated changelogs later without changing what already works.

```
<type>(<scope>): <summary>              e.g. feat(design-system): add x-mk.button reference primitive
                                              fix(infra): restore egress network for dev/stg placeholders
                                              docs(adr): record fresh-skeleton scaffold decision
```

Types: `feat`, `fix`, `docs`, `chore`, `refactor`, `test`, `ci`, `infra` (non-standard but useful here, given how much of this repo's history is infrastructure work distinct from application code).

**Do not rewrite existing history to fit this** — apply it going forward only. Rewriting merged history for a formatting preference is not worth the disruption.

### 6.2 Commit signing

No GPG or SSH signing key is configured (`commit.gpgsign` unset). Every commit in this repo's history is currently unverified on GitHub. Given a meaningful share of commits here are agent-authored on a human's behalf, a **Verified** badge is a real, non-cosmetic signal of provenance — it is what lets someone later distinguish "this really came from this machine/key" from "the author field says so." Recommended: generate an SSH signing key, add it to the GitHub account, set `commit.gpgsign = true` and `gpg.format = ssh` in this repo's local config. **Not done in this plan** — it requires a human-controlled key, the same boundary this session has held for every other credential.

---

## 7. Two concrete, low-risk repo-config actions

Both reversible, neither touches application logic or live infrastructure. Listed here as ready-to-execute recommendations, not yet applied — say the word and they take one command each.

**7.1 — `deleteBranchOnMerge`.** Currently `false` (verified via `gh repo view`). Standard hygiene: turn it on so merged feature branches don't accumulate. `gh api -X PATCH repos/andrianm28/makam-app -f delete_branch_on_merge=true`.

**7.2 — Dependabot.** `docs/operations/ci-cd-and-release.md` §9 already states the policy this would implement: *"Use automated pull requests where available, but never auto-deploy dependency changes... Group low-risk patches; isolate framework/Filament/Livewire and security-sensitive package changes."* Add `.github/dependabot.yml` for the `composer`, `npm`, and `github-actions` ecosystems, weekly, grouped by risk tier, **no auto-merge** (the existing doc is explicit that dependency changes are never auto-deployed). This is a direct implementation of a rule the project already committed to in writing, not a new policy.

---

## 8. Explicitly deferred, and why

| Item | Why not now |
|---|---|
| **Formal SemVer git tags** | This project has zero shipped releases (`sprint-plan.md` puts the first around Sprint 16). Tagging now would be tagging nothing meaningful. Start tagging at the first demonstrable milestone (e.g. `v0.1.0` when the Sprint 5 "walking skeleton" is reachable), not before. |
| **CODEOWNERS** | Meaningful once there is more than one human reviewer with distinct areas of authority. Today there is one. Revisit alongside team growth. |
| **Release/hotfix branches** | Same reasoning as GitFlow in §2 — there is no production branch yet to protect or hotfix. |
| **Git LFS** | No large binary assets in this repo currently (docs, code, no media). Revisit if design assets or seed fixtures grow large. |
| **Squash-vs-merge-vs-rebase policy** | All three are currently allowed (`mergeCommitAllowed`/`rebaseMergeAllowed`/`squashMergeAllowed` all `true`). Recommend **squash merge** as the default going forward — it turns a batch's several work-in-progress commits (like the six-bug CI fix sequence) into one clean unit on `master`, while the PR itself still preserves the full commit-by-commit history for anyone who needs it. This is a norm rather than an enforced repo setting for now — enforcing it is a separate repo-settings change from the branch-protection one Task 0.4 covers, not itself blocked by plan/visibility. |

---

## 9. A note on version-number collision

This project already has **two** independent versioning axes that use the same-looking numbers: the documentation package version (`v0.6` in `CHANGELOG.md`/`README.md`/`AGENTS.md`) and **per-document** versions established by the L-4 fix (`docs/specs/README.md` is `v0.7`, `technology-baseline.md` is `v0.5`, deliberately different — see that document's own §"Document versioning convention"). **Git tags will be a third axis.** When tagging starts (§8), do not reuse a number that coincides with either existing axis by accident — a git tag `v0.7` sitting next to a documentation package at `v0.6` and a spec README at `v0.7` would be genuinely confusing. Recommend prefixing git release tags distinctly, e.g. `release/v0.1.0`, so the three axes stay visually distinguishable in tooling and in conversation.

---

## 10. Summary — what to actually do, in order

1. **Now:** merge PR #1 as the bootstrap unit it is (§2.1). Do not split it retroactively.
2. **Next PR onward:** one task/batch per PR, named per §3, using GitHub Flow (§2).
3. **Add `.github/PULL_REQUEST_TEMPLATE.md`** (§5) — mechanises the NOT TESTED discipline already in use.
4. **Adopt Conventional Commits subject lines** going forward only (§6.1).
5. **On confirmation:** flip `delete_branch_on_merge` to `true` and add `.github/dependabot.yml` (§7) — both reversible, both already implied by existing project policy.
6. **When there's a human-controlled signing key available:** enable SSH commit signing (§6.2).
7. **Done (6 Sep 2026):** the repo is confirmed public (§0). **Still pending, human-executed:** enable real branch protection (Task 0.4 in `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`) and then replace §4's discipline-only substitutes with the enforced controls it turns on.

---

## NOT TESTED

- No repo setting has been changed by this plan — §7's two actions are described, not executed.
- **OQ-G1 resolved 6 Sep 2026:** the repo owner made the repo public (`gh repo view --json visibility` → `PUBLIC`), settling the pricing/visibility question this item originally recorded. What remains NOT TESTED is the branch-protection API call itself (Task 0.4) — prepared, not executed by an agent, per `AGENTS.md` §Infrastructure-agent execution.
- `.github/PULL_REQUEST_TEMPLATE.md` has not been created; §5 shows its proposed content, not a shipped file.
- Conventional Commits adoption has not been retrofitted to any existing commit and this plan explicitly recommends against doing so.
- ~~Whether `master` currently contains any commit that bypassed a PR~~ — **checked.** `git rev-list --count master` → 1; `master`'s only commit is `05f6f4d`, the original baseline import. No history to reconcile before treating "always PR" as the norm going forward.
