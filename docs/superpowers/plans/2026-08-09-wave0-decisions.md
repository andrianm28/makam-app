# Wave 0 — MVP Acceleration Decisions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record the four human-approved rulings that unblock Wave 1's parallel platform-core lanes (document-vault, notifications, payment-adapter, financial-ledger) and update the affected canonical documents.

**Architecture:** No production code changes. Four decisions are recorded as durable artifacts — a precedence ruling, a provider ADR, a money-contract decision, and a capacity/cleanup action — then propagated into the canonical documents that currently contradict them (`docs/planning/sprint-plan.md`, `docs/product/mvp-scope.md` cross-refs, `docs/governance/assumptions-and-gates.md`, `docs/planning/retrofit-backlog.md`).

**Tech Stack:** None — documentation-only. The money-contract decision is verified (no money stored in any deployed DB yet, so an integer-minor-unit contract is additive, not a data migration).

---

## Scope of this plan

Wave 0 is the decision/secret/capacity step of the approved acceleration plan. It produces:

| Deliverable | File | Approval |
|---|---|---|
| 0a cart/checkout precedence ruling | recorded here + append-corrections to `sprint-plan.md` | user: "approved, proceed" |
| 0b SumoPod sandbox provider ADR | `docs/adr/0033-choose-sumpod-sandbox-for-dev-payment.md` | user: "approved, proceed" |
| 0c integer minor-unit money contract + F15 resolution direction | recorded here | user: "approved, proceed" |
| 0d capacity baseline + worktree cleanup | recorded here + `sprint-plan.md` S4-T9 row | user: "approved, proceed" |

The Wave 1 lane plans (L1 document-vault, L2 notifications, L3 payment-adapter, L4 financial-ledger) are separate plan files: `2026-08-09-platform-{document-vault,notifications,payment-adapter,financial-ledger}.md`.

---

## Task 1: Record the cart/checkout precedence ruling (0a)

**Files:**
- Modify: `docs/planning/sprint-plan.md:162` (append-correction to the deferral bullet)
- Modify: `docs/planning/sprint-plan.md:779` (Sprint 11–12 row append-correction)
- Read: `docs/product/mvp-scope.md:34-39`

**Interfaces:**
- Produces: the canonical statement that `mvp-scope.md` governs cart/checkout for the MVP; subsequent Marketplace work (Wave 3 L7) cites this ruling instead of re-litigating it.

**Ruling (approved 09 Aug 2026):** `docs/product/mvp-scope.md:35` (`Cart dan checkout`) is a stakeholder **MUST IMPLEMENT** item under the unconditional framing of `mvp-scope.md:5`. Per `AGENTS.md` §Source precedence — `mvp-scope.md` ranks above sprint plans, and "Never remove a stakeholder MVP item merely because an external gate is closed. Implement the documented fallback." — **cart/checkout is in MVP scope**. `docs/planning/sprint-plan.md:162`'s outright deferral ("Feature specs deferred entirely: … cart, checkout, **vendor portal**") and the Sprint 11–12 row (`sprint-plan.md:779`) are append-corrected to match, byte-for-byte preserving the existing correction convention.

The lifting of the "second Marketplace retrofit" prohibition (`retrofit-backlog.md` §2 Marketplace row) is a **consequence of this ruling**, recorded here: the next Marketplace retrofit (cart/checkout review, Wave 3) MAY start once L7's build plan is approved; the precedence conflict is resolved in `mvp-scope.md`'s favour.

- [ ] **Step 1: Append-correction to `sprint-plan.md:162`**

Add below the "Feature specs deferred entirely" bullet (appending, not rewriting, per the file's own convention):

```
**Correction, 09 Aug 2026 (Wave 0 ruling 0a):** the full deferral above of
`funeral-marketplace-and-vendor-portal` cart/checkout/vendor-portal to
Sprint 11–12 is overridden by `docs/product/mvp-scope.md:35` (`Cart dan
checkout`) — a stakeholder MUST IMPLEMENT item per `mvp-scope.md:5` and
`AGENTS.md` §Source precedence. Cart/checkout is in MVP scope; the Sprint
11–12 deferral below is a resourcing note, not a scope decision. See
`docs/superpowers/plans/2026-08-09-wave0-decisions.md` Task 1.
```

- [ ] **Step 2: Append-correction to the Sprint 11–12 row (`sprint-plan.md:779`)**

Add to that row: `**(corrected 09 Aug 2026, Wave 0 ruling 0a)** — cart/checkout is in MVP scope per `mvp-scope.md:35`; this row's "vendor portal (9 screens)" remainder is still the vendor-panel work and stays here, but the marketplace cart/checkout half is pulled forward to Wave 3 (L7).`

- [ ] **Step 3: Commit**

```bash
git add docs/planning/sprint-plan.md
git commit -m "docs(planning): Wave 0 ruling 0a — mvp-scope governs marketplace cart/checkout"
```

---

## Task 2: Record the SumoPod provider decision + ADR (0b)

**Files:**
- Create: `docs/adr/0033-choose-sumpod-sandbox-for-dev-payment.md`
- Read: `docs/adr/0004-gate-shared-money-path.md`, `docs/contracts/payment-webhook.md`

**Interfaces:**
- Produces: `SUMODOP_SANDBOX_API_KEY` (name only — the value is never written to the repo) as the dev/staging provider credential; L3 (`platform-payment-adapter`) consumes the ADR as its provider-authority citation.

**Ruling (approved 09 Aug 2026):** SumoPod sandbox (`https://api-pay-sandbox.sumopod.com`) is the chosen dev/staging payment provider. Chosen against the sandbox evidence supplied by the user (QRIS, `payment_method_type_code`, ≤ 24 h `expires_in_hours`, hosted `payment_link_url`, Svix-signed webhooks + `X-Webhook-Token`, events `payment.completed/failed/expired/test`, 10 s ack deadline) and its 1:1 fit with `docs/contracts/payment-webhook.md`'s pipeline and the repo's webhook invariants.

Sandbox activation is **not** production `G-PAY-01` open. `PaymentMode` stays gate-resolved; dev uses `Online` against the sandbox; the manual-fallback path (`MANUAL_COORDINATION`) is implemented regardless per `mvp-scope.md` §7. Production activation still requires FIN-DEC, merchant setup, and human sign-off.

- [ ] **Step 1: Write `docs/adr/0033-choose-sumpod-sandbox-for-dev-payment.md`**

Follow the ADR-0004 shape: Status, Context (provider was unchosen, `OQ-5`/`G-PAY-01` blocked `platform-payment-adapter`), Decision (provider + exact endpoints + events + secret-variable name, no secret value), Consequences (what unblocks, what does NOT change — production gate, FIN-DEC, manual fallback mandate), Revisit criteria.

- [ ] **Step 2: Commit**

```bash
git add docs/adr/0033-choose-sumpod-sandbox-for-dev-payment.md
git commit -m "docs(adr): 0033 choose SumoPod sandbox for dev/staging payment"
```

---

## Task 3: Inject the SumoPod sandbox key into `.env.dev` (0b — protected)

**Files:**
- Modify: `/opt/makam/compose/.env.dev` (host file, NOT in the repo)

**Interfaces:**
- Produces: `SUMODOP_SANDBOX_API_KEY=<value>` present in the dev container's environment for L3. Never committed, never logged.

**Safety rules (AGENTS.md §Authentication and uploads, §Infrastructure-agent execution):** the value is a secret. It is written only to the host `.env.dev` file via a one-time `sed` append; it is never echoed to stdout, never added to any git-tracked file, and never placed in `.env.example`. The container reads `env_file: .env.dev` (`compose.yml`), so the running `dev-web` picks it up on its next recreate.

- [ ] **Step 1: Verify the variable is not already present, then append**

```bash
grep -q "^SUMODOP_SANDBOX_API_KEY=" /opt/makam/compose/.env.dev \
  || printf '\n# SumoPod sandbox (ADR-0033, Wave 0b) — secret, never commit\nSUMODOP_SANDBOX_API_KEY=__USER_PROVIDED_VALUE__\n' >> /opt/makam/compose/.env.dev
```

The value is substituted at execution time from the user-supplied key; this plan records only the variable name and the protected-injection procedure, never the value.

- [ ] **Step 2: Verify the key is readable by the compose user and uncommitted**

```bash
grep -c "^SUMODOP_SANDBOX_API_KEY=" /opt/makam/compose/.env.dev
grep -rn "SUMODOP" /home/ubuntu/makam-app --include="*.env*" 2>/dev/null   # must return nothing under the repo
```

---

## Task 4: Capacity baseline + S4-T9 row update (0d)

**Files:**
- Modify: `docs/planning/sprint-plan.md:631` (S4-T9 row append-correction)

**Interfaces:**
- Produces: the measured baseline that caps Wave 1 at 4 concurrent worktrees with staggered CI; L3/L4's test-heavy runs use it to schedule.

**Ruling (approved 09 Aug 2026):** the S4-T9 capacity review runs now (measurement, not deferral). The Wave 1 budget is **4 concurrent worktrees, staggered `build-image`/test runs**, with `free -m` checked before each wave and the ADR-0027 exit criteria re-evaluated.

- [ ] **Step 1: Measure current host memory**

```bash
free -m
ps aux --sort=-%mem | head -12
docker stats --no-stream --format '{{.Name}}\t{{.MemUsage}}' 2>/dev/null | head -8
```

- [ ] **Step 2: Append-correction to the S4-T9 row (`sprint-plan.md:631`)**

Append: `**Wave 0 (09 Aug 2026): capacity baseline measured — see `2026-08-09-wave0-decisions.md` Task 4. Wave 1 budget set at 4 concurrent worktrees with staggered CI; ADR-0027 exit criteria re-checked at each wave boundary. Decision pending on upgrade/split/accept, still ⚠️ HUMAN.**`

- [ ] **Step 3: Commit**

```bash
git add docs/planning/sprint-plan.md
git commit -m "docs(planning): S4-T9 capacity baseline recorded (Wave 0d)"
```

---

## Task 5: Stale worktree cleanup (0d)

**Files:**
- Delete (git worktree remove): `.worktrees/retrofit-marketplace`, `.worktrees/retrofit-outbox`, `.worktrees/retrofit-renewal`, `.worktrees/retrofit-servicecatalog`, `.worktrees/booking-wizard-steps-1-5`, `.claude/worktrees/agent-abc80a3308890824a`

**Interfaces:**
- Produces: a clean worktree list; Wave 1 lanes create fresh worktrees without naming collisions.

**Safety rule:** before removing each worktree, verify its branch's tip is an ancestor of `origin/docs/design-system-and-planning` (all retrofit branches merged 09 Aug; `booking-wizard-steps-1-5` merged via PR #2; the `agent-abc80a...` worktree is a stale agent scratch with no branch ref on the trunk). Use `git worktree remove --force` only after the ancestor check passes; if any branch is not merged, STOP and ask.

- [ ] **Step 1: Verify merged status**

```bash
git -C /home/ubuntu/makam-app worktree list
for b in retrofit-marketplace retrofit-outbox retrofit-renewal retrofit-servicecatalog booking-wizard-steps-1-5; do
  echo "$b: $(git -C /home/ubuntu/makam-app merge-base --is-ancestor $b origin/docs/design-system-and-planning 2>/dev/null && echo MERGED || echo NOT-MERGED)"
done
```

- [ ] **Step 2: Remove confirmed-merged worktrees, then prune**

```bash
git -C /home/ubuntu/makam-app worktree remove --force /home/ubuntu/makam-app/.worktrees/retrofit-marketplace
git -C /home/ubuntu/makam-app worktree remove --force /home/ubuntu/makam-app/.worktrees/retrofit-outbox
git -C /home/ubuntu/makam-app worktree remove --force /home/ubuntu/makam-app/.worktrees/retrofit-renewal
git -C /home/ubuntu/makam-app worktree remove --force /home/ubuntu/makam-app/.worktrees/retrofit-servicecatalog
git -C /home/ubuntu/makam-app worktree remove --force /home/ubuntu/makam-app/.worktrees/booking-wizard-steps-1-5
git -C /home/ubuntu/makam-app worktree remove --force /home/ubuntu/makam-app/.claude/worktrees/agent-abc80a3308890824a
git -C /home/ubuntu/makam-app worktree prune
```

---

## Task 6: Record the money-contract decision (0c)

**Files:**
- Read: `docs/domain/financial-model.md`, `docs/planning/retrofit-backlog.md` §2 ServiceCatalog (F15, S-Q3)
- Produce: the ruling text below, cited by L3/L4 plans and `retrofit-backlog.md` disposition updates

**Interfaces:**
- Produces: the shared money contract (integer minor units) that L3 (`platform-payment-adapter` AC10) and L4 (`platform-financial-ledger` AC11) both implement; the F15 resolution direction consumed by Wave 2's Booking work.

**Ruling (approved 09 Aug 2026):**
1. **Money is stored and carried as integer minor units** (IDR amounts × 100, i.e. rupiah as cents) everywhere from the ledger outward — matching `platform-financial-ledger` AC11 ("never a float"). No money value is stored as a floating-point number.
2. **F15 (`(float) $priceVersion->amount` at the Booking seam) is resolved as part of L4/L3**: `BookingDraftQuery`'s `(float)` cast is replaced by integer minor-unit handling at the quote/total seam. `AGENTS.md` §Domain and financial invariants ("must be resolved before any real payment amount is derived") is satisfied before L3 derives any sandbox payment amount.
3. **S-Q3** (`PRICE_VERSION_RECORDED` in `SensitiveActions`): the ruling is to ADD `PRICE_VERSION_RECORDED` to `SensitiveActions::ACTIONS` — a financial change, human-approved here, executed by L4's plan. The "correctable by publishing a new version" argument is not a full answer because an issued quote holds the old snapshot.
4. **Verification:** no money is stored in any deployed DB today (`outbox_events` 0 rows; ledger/journal tables don't exist yet — `app/Platform/FinancialLedger` is `.gitkeep` only). Adopting integer minor units now is **additive and conversion-free**, so this ruling creates no destructive migration.

- [ ] **Step 1: Record the ruling verbatim** as a section in this plan (Task 6 above is that record).

- [ ] **Step 2: Update `retrofit-backlog.md` §2 ServiceCatalog dispositions for F15 and S-Q3** to point at this ruling (append-correction: `— RULED 09 Aug 2026 (Wave 0c): integer minor units; resolved by L4/L3 plans`).

- [ ] **Step 3: Commit**

```bash
git add docs/planning/retrofit-backlog.md
git commit -m "docs(planning): Wave 0 ruling 0c — integer minor-unit money contract; F15/S-Q3 resolved"
```

---

## Global constraints

- Append-corrections preserve the original text byte-for-byte and add dated correction notes — never rewrite in place.
- Secret values never enter the repo, logs, Pulse, or Horizon tags (AGENTS.md §Observability).
- Financial/authorization changes (F15, S-Q3, ledger, payment) require human review at the plan and merge boundary — recorded approvals above.
- No destructive migration is created by this wave; all ruling-related schema work is additive and lands in L3/L4 plans.
