# Kiro Specs Analysis — `.kiro/specs/`

**Version:** v0.1
**Date:** 25 Juli 2026
**Analysed:** every file under `/home/ubuntu/makam-app/.kiro/specs/` at commit `05f6f4d`
**Corpus:** **19** spec folders × 3 files = **57 files**, 928 lines total
**Method:** all 57 files read in full; every quantitative claim below produced by an executed command, reproduced inline

---

## 0. Executive summary

### 0.1 Count correction

The request referred to **21 specs**. The verified count is **19**:

```
$ ls -1d .kiro/specs/*/ | wc -l
19
$ find .kiro/specs -name '*.md' | wc -l
57            # 19 × (requirements + design + tasks)
```

The `21` almost certainly comes from the directory link count in `ls -la .kiro/`, which reads `drwxrwx--- 21 ubuntu ubuntu … specs` — that is 19 subdirectories plus `.` and `..`. No spec is missing; the corpus is complete.

### 0.2 Overall verdict

These specs are **unusually disciplined for their length**. In ~16 lines each they encode real domain invariants — idempotency keys, forward-only states, immutable quote versions, query-level authorization, gate-closed negative criteria. Nine of the 19 carry explicit **negative criteria** ("No paid state from browser return"), which is rare and valuable. All thirteen RKS codes K23–K35 are covered with no orphans.

**But they are specifications for feature verticals only, and the project is not blocked on feature verticals.** The eight cross-cutting foundations that every vertical depends on — identity/MFA, payment, notifications, document vault, audit, feature gates, transactional outbox, financial ledger — are **consumed by many specs and owned by none.** There is no spec folder for any of them.

### 0.3 Top 10 issues

| # | Sev | Issue | Evidence |
|---|-----|-------|----------|
| 1 | **Critical** | **8 cross-cutting foundations have no owning spec.** No folder for auth/identity, payment, notification, document vault, audit, feature gate, outbox, or ledger. | §2.2, §6.2 |
| 2 | **Critical** | **`outbox` appears 0 times in the entire `.kiro/` tree** — yet `AGENTS.md` makes the transactional outbox mandatory for critical domain events (ADR-0019). | §2.3 |
| 3 | **High** | **`loading` appears 0 times across all 19 specs.** `AGENTS.md` requires every transactional screen to have loading/empty/error/pending/success/support states. | §3.2 |
| 4 | **High** | **MFA/TOTP: 0 mentions. Re-authentication: 0 mentions.** `AGENTS.md` mandates TOTP MFA for privileged roles and recent re-auth for six named action classes. | §2.3 |
| 5 | **High** | **Marketplace `tasks.md` contradicts its own AC and `AGENTS.md`**: task says "multi-vendor order decomposition" while AC4, its `design.md`, and `AGENTS.md` all say one vendor per checkout for MVP. | §2.4, §5.2 |
| 6 | **High** | **5 database tables are claimed by two specs each** — `agreements`, `agreement_versions`, `blocks`, `plot_units`, `plot_status_events`. Migration ownership is undefined. | §5.1 |
| 7 | **High** | **Zero design-system awareness.** 0 hits for `design-system`, `tokens.css`, `Tailwind`, `spacing`, `typography` across all 57 files. | §3.1 |
| 8 | **Medium** | **~30 % of acceptance criteria have no corresponding task.** Mean AC→task ratio 0.70; worst is `booking-and-order-orchestration` at **0.43** (14 AC → 6 tasks). | §4.2 |
| 9 | **Medium** | **No `tasks.md` declares a dependency, estimate, or definition of done.** All three apparent matches are false positives ("customer acceptance", "quote acceptance"). | §4.3 |
| 10 | **Medium** | **Canonical-doc linking is almost absent.** Only 4 distinct `.md` references across 57 files; `marketplace-catalog.md` and `mvp-scope.md` are referenced by **0** specs — and the marketplace catalog is instead **duplicated inline**, which `AGENTS.md` forbids. | §4.4, §2.4 |

**No broken links.** Every referenced file exists (§4.4). That is worth stating plainly, because it was one of the things asked and the answer is clean.

### 0.4 Readiness in one line

**0 of 19 specs are executable today** — there is no code and no database ([prior analysis](sprint-plan.md) §1). Judged on *spec quality* rather than environment, **4 are ready to build the moment Sprint 1 converges**, 3 need spec repair first, 6 are blocked behind closed governance gates, and 6 are blocked behind foundation specs that do not yet exist. Full table in §7.

---

## 1. Inventory — 19 specs

Grouped by the classification in [`docs/specs/README.md`](../specs/README.md), which names **8 MVP-required public specs**.

### 1.1 MVP-required (8)

| # | Spec | Authority | In one line |
|---|---|---|---|
| 1 | `public-home-and-navigation` | Stakeholder MVP — Home | Homepage with the four primary menus in exact order, truthful Urgent state, CS CTA, gate-explanatory pages. |
| 2 | `public-booking-wizard` | Stakeholder MVP — Steps 1–9 | The nine-step Pemesanan Makam wizard: presentation, autosave/resume, branch handling. |
| 3 | `booking-and-order-orchestration` | K25–K28 + Steps 1–9 | Domain engine behind booking: drafts, product-type routing, quote versioning, payment guard, forward-only order states. |
| 4 | `cemetery-directory-and-availability` | K23–K24 + Steps 1–2 | TPU/TPS directory, versioned capability profiles, safe-default availability, `Perlu konfirmasi` labelling. |
| 5 | `funeral-marketplace-and-vendor-portal` | K29–K30 | Marketplace catalogue + cart + checkout **and** the entire vendor portal (products, orders, calendar, evidence, payouts). |
| 6 | `renewal-and-grave-registry` | K31–K32 | Six-step Perpanjangan, grave registry, `pg_trgm` fuzzy search < 500 ms at 100k, 10k-row import, duplicate-period guard, reminders. |
| 7 | `public-faq` | Stakeholder MVP — FAQ | Six required categories, filter/search/detail, admin CMS with publish/unpublish, no draft leakage. |
| 8 | `admin-operations` | K35 | Admin dashboard: master data, orders, payments, FAQ CMS, reports, exception queues, audited sensitive actions. |

### 1.2 RKS-derived, not listed in the stakeholder MVP dashboards (3)

| # | Spec | Authority | In one line |
|---|---|---|---|
| 9 | `cemetery-operator-dashboard` | **K34** | Dedicated operator panel scoped to assigned cemeteries; advisory only, admin stays final authority, non-blocking on adoption. |
| 10 | `package-and-service-bundles` | K26 + B03 | Immutable published package versions, quote expansion with price snapshots, substitution rules, evidence requirements. |
| 11 | `recurring-care-subscriptions` | K33 | Monthly/quarterly/6-month/annual care billing; one invoice per cycle, webhook-driven paid state, tokenization behind a flag. |

### 1.3 Benchmark-derived / proposed (3)

| # | Spec | Status | In one line |
|---|---|---|---|
| 12 | `funeral-case-management` | Proposed **P0** | FuneralCase aggregate: tasks, deadlines, escalation, handover, communications, empathetic customer timeline. |
| 13 | `at-need-booking` | K25/K27 + benchmark | Lightweight urgent intake — minimum data first, FuneralCase before non-critical fields, progressive documents. |
| 14 | `grave-care-fulfillment` | K29/K33 + benchmark | Care work orders separate from billing: schedule, checklist, before/after evidence, acceptance/complaint/make-good. |

### 1.4 Gated / optional — explicitly **not** MVP acceptance (5)

| # | Spec | Gate | In one line |
|---|---|---|---|
| 15 | `pre-need-contracting` | **G-LEGAL-01** | Interest/consultation only while gate closed; full paid contract lifecycle modelled but unreachable. |
| 16 | `plot-inventory-and-reservation` | **G-PLOT-01** | Authoritative plot inventory with atomic hold/confirm/release/expire and stale-source circuit breaker. |
| 17 | `certificates-and-agreements` | **G-CERT-01** | Versioned agreements, acceptance evidence bound to exact version, certificate issue/revoke/replace with numbering. |
| 18 | `visitation-booking` | **G-VISIT-01** | Information-only or bookable visitation with hours, capacity, blackout dates, duplicate guard. |
| 19 | `memorial-and-qr` | **G-MEM-01** | Private-by-default memorial, four privacy modes, opaque revocable QR token, moderation and abuse reporting. |

### 1.5 Structural completeness

```
$ for d in */; do for f in requirements.md design.md tasks.md; do [ -f "$d$f" ] || echo MISSING; done; done
(no output — all 57 files present)
```

**19/19 complete triads. No missing or empty file.** `tasks.md` length ranges 7–14 lines; `visitation-booking` and `certificates-and-agreements` are the thinnest, `public-booking-wizard` the fullest.

### 1.6 RKS coverage

```
$ grep -rhoE 'K[0-9]{1,2}' .kiro/specs/*/requirements.md | sort -uV
K23 K24 K25 K26 K27 K28 K29 K30 K31 K32 K33 K34 K35
$ for k in 23..35; do grep -rq "K$k" .kiro/specs/ || echo "K$k NOT REFERENCED"; done
(no output)
```

**All 13 RKS codes K23–K35 are referenced. No orphan RKS requirement.** This matches [`traceability-matrix.md`](../domain/traceability-matrix.md) §A, which maps each K code to an owning spec.

Note: 8 of 19 specs carry **no** K code — the six gated/proposed ones plus `public-booking-wizard` and `public-faq`, which cite "Stakeholder Workflow MVP" instead. That is correct under `AGENTS.md` precedence (stakeholder MVP is rank 2), but it means K-code grep alone under-reports coverage.

---

## 2. Consistency with `AGENTS.md`

### 2.1 What the specs get right

Verified present and correctly stated:

| `AGENTS.md` rule | Where honoured |
|---|---|
| Homepage has exactly four primary services in order | `public-home-and-navigation` AC1, AC4 ✅ |
| Booking exposes Steps 1–9 exactly as documented | `public-booking-wizard` AC1; `booking-and-order-orchestration` AC1 ✅ |
| Launch locations: Jakarta, Bogor, Depok, Tangerang, Bekasi | 3 specs (directory, wizard, renewal) ✅ |
| **Never mark paid from browser return URL** | `booking-and-order-orchestration` negative criteria; `recurring-care-subscriptions` design ("cannot be inferred from notification or browser return") ✅ |
| Never create payment before valid confirmation/reservation + accepted quote + authorized opening | `booking-and-order-orchestration` AC6 **and** an explicit `design.md` payment-guard pseudocode block ✅ |
| Closed payment gate uses manual fallback in Step 8 | wizard AC9; orchestration AC7 ✅ |
| Quote immutable; revision creates new version | orchestration AC8; `package-and-service-bundles` AC2–AC3 ✅ |
| At-Need/Urgent creates or uses a FuneralCase | orchestration AC5; `at-need-booking` AC3; `funeral-case-management` AC1 ✅ |
| Paid Pre-Need impossible while legal gate closed | `pre-need-contracting` AC1 + negative criteria + design ("All paid states are unreachable while gate closed") ✅ |
| Package/class confirmation is default; specific plot needs authoritative inventory | directory AC5–AC7; `plot-inventory-and-reservation` AC1 ✅ |
| Operator silence preserves admin/manual fallback | `cemetery-operator-dashboard` AC6 + "Non-blocking workflow"; orchestration AC12 ✅ |
| Service payment and fulfilment are separate states | `grave-care-fulfillment` AC2, AC6; marketplace AC12 ✅ |
| One reminder per grave/window, one invoice per cycle | renewal AC15; `recurring-care-subscriptions` AC2 + unique constraint ✅ |
| Policies and query-level scope mandatory | marketplace AC9; operator AC2; case-management "Security" ✅ |
| MVP is one vendor per checkout | marketplace AC4 + design ✅ (**but see §2.4**) |
| FAQ: six required categories, no draft leakage | `public-faq` AC2, AC6 ✅ |
| Domain logic outside controllers/Livewire/Filament Resources | `admin-operations` design: *"Filament Resources provide forms/tables only. Domain mutations are delegated to Actions/Services"* ✅ |
| Do not claim WhatsApp/email delivery without delivery state | wizard AC10; orchestration AC13 ✅ |

That is a strong compliance record on the **commercial and domain invariants** — the ones most expensive to get wrong.

### 2.2 CRITICAL — foundations consumed but owned by nobody — ✅ RESOLVED 25 Jul 2026

> **Status update.** All eight foundation specs were authored on 25 Jul 2026, taking the corpus from 19 to **27 specs (81 files)**: `platform-identity-and-access`, `platform-payment-adapter`, `platform-notifications`, `platform-document-vault`, `platform-audit`, `platform-feature-gate`, `platform-outbox`, `platform-financial-ledger`. Each has a complete requirements/design/tasks triad (10–14 acceptance criteria, 11–16 tasks). They are registered in `docs/specs/README.md` and `.kiro/steering/project.md`.
>
> The `AGENTS.md` mandates measured at **0 mentions** in §2.3 are now present: `outbox` 0 → 36 mentions across 14 files · MFA/TOTP 0 → 3 files · re-authentication 0 → 14 files · five-minute signed URL 0 → 3 files.
>
> Still thin: expand/contract migration appears in only 1 file. It is properly owned by `ci-cd-and-release.md` §4 rather than by a feature spec, so this is arguably correct — but no spec references that rule.
>
> The diagnosis below is kept for the record.

Command:

```
$ ls -d .kiro/specs/*auth* *identity* *payment* *notification* *audit* *document* *outbox* *ledger*
ls: cannot access … : No such file or directory   (all eight)
```

Cross-referenced against the 24 modules in [`overview.md`](../architecture/overview.md) §5:

| Module (`overview.md` §5) | Owning spec | Consumed by |
|---|---|---|
| `IdentityAccessAdapter` | ❌ **none** | every authenticated surface |
| `DocumentVaultAdapter` | ❌ **none** | wizard Step 7, marketplace evidence, care evidence, memorial media, renewal import |
| `PaymentAdapter` | ❌ **none** | 7 files / 4 specs |
| `NotificationAdapter` | ❌ **none** | 6 files / 5 specs |
| `AuditAdapter` | ❌ **none** | **13 files / 9 specs** |
| `FeatureGate` | ❌ **none** | 7 files / 5 specs |
| Transactional outbox (`queue-and-outbox.md`, ADR-0019) | ❌ **none** | mandatory for all critical events |
| Financial ledger (`financial-ledger-and-settlement.md`, ADR-0020) | ❌ **none** | 5 specs |

`AuditAdapter` is the sharpest illustration: **the word `audit` appears in 13 files across 9 specs, and no spec defines what audit is.** Each of those will invent its own, and `AGENTS.md` requires append-only audit with specific referenced data.

> **Correction, 25 Jul 2026.** The counts in this table are `grep -ril` **file** counts, and an earlier revision mislabelled them as **spec** counts — the audit row read "13 specs" when the measurement was 13 files across 9 specs. Surfaced by a subagent writing ADR-0029, which found this table contradicting `sprint-plan.md` §3.4 and reported it rather than picking a number.
>
> These are **keyword-mention counts, not dependency counts**, and they under-report: a spec needing an admin panel needs identity whether or not it writes the word "MFA". They were a useful discovery heuristic — they are what surfaced the gap — but the reasoned per-spec dependency, which is what sequencing rests on, is in [`sprint-plan.md`](sprint-plan.md) §3.4. Note also that these counts are now **self-contaminated**: the `## Design system` sections appended to all 19 `tasks.md` contain the words *audit*, *authorization*, and *pending*, so re-running the old patterns partly measures those additions.

**Impact:** every transactional vertical is spec-blocked, not just code-blocked. Building `public-booking-wizard` Step 7 requires document-vault behaviour that no spec defines; Step 8 requires payment-adapter behaviour that no spec defines; Step 9 requires notification behaviour that no spec defines.

**Recommendation:** author six foundation specs — `platform-identity-and-access`, `platform-payment-adapter`, `platform-notifications`, `platform-document-vault`, `platform-audit`, `platform-feature-gate` — plus `platform-outbox` and `platform-financial-ledger`. The source material already exists in `docs/security/`, `docs/contracts/`, and `docs/domain/`; this is consolidation into the Kiro format, not new design work.

### 2.3 HIGH — `AGENTS.md` mandates with zero spec presence

Each row is a rule stated in `AGENTS.md` with **0 matches** across all 57 files:

| `AGENTS.md` requirement | Spec mentions |
|---|---|
| *"Critical domain events are inserted into the transactional outbox in the same database transaction as state mutation"* | **`outbox` = 0 in the entire `.kiro/` tree** |
| *"mandatory TOTP MFA for privileged roles"* | `MFA` / `TOTP` = **0** |
| *"Require recent re-authentication for financial, gate, bank-detail, certificate, plot-override, and bulk-export actions"* | `re-authenticat` = **0** |
| *"Signed deceased-document URLs expire within five minutes"* | explicit 5-minute / 300-second figure = **0** (orchestration AC10 says only "short-lived") |
| *"Email/WhatsApp never contains private attachments"* | = **0** |
| *"Use the queue names and priorities in `queue-and-outbox.md`"* | `Horizon` / `queue priorit` / `critical queue` = **0** |
| *"Migrations follow expand/contract"* | `expand/contract` = **0** |
| *"Imports/reports/media must not starve critical or urgent queues"* | not stated — though renewal has a 10k async import that is precisely this risk |

Reproduce:

```
$ grep -ric 'outbox' .kiro/     → 0 everywhere
$ grep -rilE 'MFA|TOTP' .kiro/specs/ | wc -l      → 0
$ grep -rilE 're-?authenticat' .kiro/specs/ | wc -l → 0
$ grep -rilE 'five minutes|300 second' .kiro/specs/ | wc -l → 0
```

These are not oversights in *documentation* — the underlying rules are all properly documented in `docs/`. They are **gaps in the specs an agent will actually execute from**, which is worse: an agent following `.kiro/specs/` faithfully will build a system that violates `AGENTS.md`.

### 2.4 HIGH — active contradictions

**(a) Marketplace multi-vendor — a task that contradicts its own spec.**

```
funeral-marketplace-and-vendor-portal/tasks.md:
  - [ ] Implement cart and multi-vendor order decomposition.

funeral-marketplace-and-vendor-portal/requirements.md AC4:
  "Initial checkout may be one vendor per checkout"
funeral-marketplace-and-vendor-portal/design.md:
  "MVP checkout is one vendor per checkout."
AGENTS.md:  "MVP is one vendor per checkout."
mvp-scope.md §8: "Multi-vendor partial refund automation" — NOT required for MVP
```

The task also pre-empts AC14, which lists what multi-vendor *first requires*: splitting, partial cancellation/refund, fee/tax allocation, dispute, reconciliation. An agent executing `tasks.md` literally would build multi-vendor decomposition against explicit MVP constraints.

**Fix:** reword to *"Implement cart with single-vendor checkout constraint and separate-checkout UX; keep `vendor_orders` allocation shape so multi-vendor can be added later."* That is what `design.md` actually says.

**(b) Canonical catalogue duplicated instead of referenced.**

```
$ grep -rl 'marketplace-catalog.md' .kiro/specs/ | wc -l
0
```

Yet marketplace AC1 restates the catalogue inline: *"flower board, flower-petal package, granite gravestone, marble gravestone, calligraphy gravestone, and grave-care plans for monthly, three-month, six-month, and annual periods."*

`AGENTS.md`: *"Do not duplicate canonical catalog data in multiple hand-maintained documents or code locations."* This is a second, hand-maintained copy that can drift from [`marketplace-catalog.md`](../product/marketplace-catalog.md) — which additionally carries the product **codes** (`FLOWER_BOARD`, `GRAVESTONE_GRANITE`, …) that the spec omits.

By contrast `public-booking-wizard` AC5 does it correctly: *"all basic and additional services in `service-catalog.md`"* — reference, not copy. Apply that pattern.

**(c) `mvp-scope.md` is authority rank 2 and referenced by zero specs.**

```
$ grep -rl 'mvp-scope' .kiro/specs/ | wc -l
0
```

Specs say "Stakeholder Workflow MVP" in prose but never link the canonical document. `AGENTS.md` precedence puts `mvp-scope.md` second only to RKS. Without the link, an agent cannot resolve a scope question from the spec alone.

### 2.5 MEDIUM — authority tension needing a decision

`cemetery-operator-dashboard` cites **K34**, which is RKS — authority rank 1. But [`mvp-scope.md`](../product/mvp-scope.md) §6 lists only **Admin** and **Vendor** dashboards as required modules; no operator dashboard. Under `AGENTS.md` precedence RKS outranks the stakeholder MVP, so K34 appears to be required — while the MVP acceptance baseline omits it.

ADR-0008 makes it non-blocking, and the spec itself is careful (advisory input, admin stays final authority, workflow must not depend on adoption). So the *design* is safe either way. The open question is purely **whether it is in MVP acceptance scope**. `AGENTS.md` also says: *"Never remove a stakeholder MVP item merely because an external gate is closed"* — it does not say what to do with an RKS item the stakeholder MVP omits. **Needs a product ruling.**

---

## 3. Consistency with the design system

### 3.1 Zero design-system awareness

```
$ for kw in design-system tokens.css Tailwind spacing typography hex colour; do
    printf "%-14s %s\n" "$kw" "$(grep -rio "$kw" .kiro/specs/ | wc -l)"; done
design-system   0
tokens.css      0
Tailwind        0
spacing         0
typography      0
hex             0
colour          0
color           1     ← "no color-only status" in public-home-and-navigation
token          10     ← all domain tokens (QR token, draft token, payment token) — NOT design tokens
```

**No spec references the design system, and none hardcodes a design value either.** That second half matters: the specs are pitched at behaviour, not appearance, so there is nothing to *un*-hardcode. This is a **gap of omission, not of contradiction** — the cheapest kind to close.

Chronology explains it: the specs were committed **23 July 2026**; [`design-system.md`](../design/design-system.md) was written **25 July 2026**. No spec could have referenced it.

The one genuine design statement is good and correctly placed — `public-home-and-navigation` design.md: *"Semantic navigation, visible focus, skip link, proper headings, alt text, touch targets, and no color-only status."* Every one of those maps to a design-system rule (§7.2, §7.3, §7.5).

### 3.2 HIGH — required UI states are largely absent

`AGENTS.md`: *"Every transactional screen has loading, empty, error, pending, success, and support states."*
[`screen-inventory.md`](../product/screen-inventory.md) §D expands this to ten required states.

Measured mentions per keyword across all 19 specs:

| State | Specs mentioning | Notes |
|---|---:|---|
| **loading** | **0** | ❌ **Not one spec mentions a loading state** |
| empty | 2 | `public-faq`, `renewal-and-grave-registry` |
| error | 3 | orchestration, wizard, renewal |
| pending | 2 | `cemetery-operator-dashboard`, `pre-need-contracting` |
| success | 1 | `renewal-and-grave-registry` |
| support | 11 | best-covered state — CS CTA is well embedded |
| responsive | 2 | `public-faq`, `public-home-and-navigation` |
| accessibility | 3 | `public-faq`, `public-home-and-navigation`, `visitation-booking` |

```
$ grep -ric 'loading' .kiro/ | grep -v ':0'
(no output — confirms 0 occurrences in the entire .kiro tree)
```

Nine specs mention **none** of the eight keywords: `admin-operations`, `cemetery-directory-and-availability`, `grave-care-fulfillment`, `memorial-and-qr`, `plot-inventory-and-reservation`, and others. `admin-operations` covers 11 admin screens with no state requirement at all.

Several specs describe *failure behaviour* well — orchestration ("No loss of draft when … provider failure"), at-need ("Capacity closed returns truthful status and alternative contact"), wizard ("Provider/payment/upload errors preserve draft and show retry/support"). That is genuinely good, and it is **error/support**, not loading/empty/pending/success.

**Recommendation:** add one line to every spec that owns a screen — *"All screens implement the ten required UI states per `docs/design/design-system.md` §6 and `screen-inventory.md` §D"* — and add the corresponding task. This is ~19 one-line edits and closes issue #3.

### 3.3 Design-system status mapping is unreachable from the specs

[`design-system.md`](../design/design-system.md) §3.7 defines the normative status → visual-intent mapping for all 13 order-lifecycle states and all 8 vendor-processing states, and requires a single `StatusIntent` resolver. The specs define the state machines (orchestration, `funeral-case-management`, `recurring-care-subscriptions`, marketplace) but no spec points at the mapping, so an agent will style badges ad hoc — including the `DIBAYAR` ≠ `SELESAI` distinction that marketplace AC12 explicitly requires.

---

## 4. Completeness

### 4.1 Acceptance criteria — present and numbered everywhere

All 19 `requirements.md` carry a numbered `## Acceptance criteria` block: 7–16 criteria, **191 total**. Nine also carry `## Negative criteria` — 20 additional prohibitions. That is above-average rigour; negative criteria are what stop an agent doing the wrong thing confidently.

Best-specified: `renewal-and-grave-registry` (16 AC), `funeral-marketplace-and-vendor-portal` (15), `public-booking-wizard` (15 AC, and the only one where nearly every AC maps to a task).

### 4.2 MEDIUM — ~30 % of acceptance criteria have no task

| Spec | AC | Tasks | Ratio |
|---|---:|---:|---:|
| `booking-and-order-orchestration` | 14 | 6 | **0.43** ⚠️ |
| `cemetery-directory-and-availability` | 12 | 7 | 0.58 |
| `renewal-and-grave-registry` | 16 | 10 | 0.62 |
| `certificates-and-agreements` | 8 | 5 | 0.62 |
| `grave-care-fulfillment` | 8 | 5 | 0.62 |
| `memorial-and-qr` | 8 | 5 | 0.62 |
| `package-and-service-bundles` | 8 | 5 | 0.62 |
| `at-need-booking` | 9 | 6 | 0.67 |
| `plot-inventory-and-reservation` | 9 | 6 | 0.67 |
| `public-faq` | 9 | 6 | 0.67 |
| `visitation-booking` | 7 | 5 | 0.71 |
| `admin-operations` | 11 | 8 | 0.73 |
| `funeral-marketplace-and-vendor-portal` | 15 | 11 | 0.73 |
| `pre-need-contracting` | 8 | 6 | 0.75 |
| `funeral-case-management` | 9 | 7 | 0.78 |
| `public-home-and-navigation` | 9 | 7 | 0.78 |
| `public-booking-wizard` | 15 | 12 | 0.80 |
| `cemetery-operator-dashboard` | 8 | 8 | **1.00** ✅ |
| `recurring-care-subscriptions` | 8 | 8 | **1.00** ✅ |
| **Total / mean** | **191** | **133** | **0.70** |

A ratio below 1.0 is not automatically wrong — one task can satisfy several AC. But `booking-and-order-orchestration` at 0.43 is the **most invariant-dense spec in the corpus** (14 AC including the payment guard, exactly-once webhook effects, forward-only transitions, operator-silence fallback) compressed into 6 tasks. Several AC have no visible task: AC11 (forward-only commercial transitions separate from case/work/certificate states), AC12 (operator-silence fallback), AC13 (Step 9 content), AC14 (notification matrix).

**No task carries an AC reference.** Tasks are free-text imperatives; nothing links `- [ ] Preserve immutable quote/version acceptance.` to AC8. Adding `(AC8)` style back-references would make coverage auditable by grep.

### 4.3 MEDIUM — no dependencies, estimates, or DoD in any `tasks.md`

```
$ grep -rilE 'depends|prerequisite|blocked|estimate|effort|definition of done|acceptance' */tasks.md
booking-and-order-orchestration/tasks.md
grave-care-fulfillment/tasks.md
certificates-and-agreements/tasks.md
```

All three are **false positives** — inspected:

```
booking-…/tasks.md:6:  - [ ] Preserve immutable quote/version acceptance.
grave-care-…/tasks.md:5: - [ ] Add customer acceptance/complaint/make-good.
certificates-…/tasks.md:4: - [ ] Implement versioned agreement/acceptance.
```

All three are "acceptance" in the domain sense. So: **0 of 19 `tasks.md` declare a dependency, an estimate, or a definition of done.**

This is defensible under `AGENTS.md` — *"`tasks.md` is planning only; issue tracker owns progress"* — but it means the specs cannot be sequenced from their own contents. Every dependency in §5 and §7 of this report had to be inferred by cross-reading, not read off the page. For an agent-executed workflow that is a real hazard: nothing in `public-booking-wizard/tasks.md` says *"blocked until the payment adapter spec exists"*.

### 4.4 Broken references — none found ✅

```
$ grep -rhoE '`[a-z0-9./-]+\.md`|docs/[a-z/-]+\.md' .kiro/specs/ | sort | uniq -c
      2 booking-wizard-fields.md
      1 service-catalog.md
      1 faq-catalog.md
      1 docs/product/information-architecture.md

$ for f in booking-wizard-fields service-catalog faq-catalog information-architecture; do
    find docs -name "$f.md"; done
docs/product/booking-wizard-fields.md
docs/product/service-catalog.md
docs/product/faq-catalog.md
docs/product/information-architecture.md
```

**All 4 referenced documents exist. Zero broken links.**

The finding is the opposite problem — **only 4 distinct references across 57 files, and only 1 cross-spec reference in the whole corpus**:

```
$ grep -rnE '`(one of the 19 spec names)`' .kiro/specs/
recurring-care-subscriptions/requirements.md:3: Fulfillment behavior is detailed in `grave-care-fulfillment`.
```

Notably unreferenced despite being directly relevant: `marketplace-catalog.md` (0 — §2.4b), `mvp-scope.md` (0 — §2.4c), `notification-matrix.md` (0, though 6 specs need it), `order-lifecycle.md` (0, though several specs restate its states), `rbac-matrix.md` (0, though nearly every spec needs authorization), `assumptions-and-gates.md` (0, though 7 specs reference gates in prose).

The `.kiro/steering/project.md` file lists 17 canonical documents, so the linkage exists at steering level. But an agent working inside a single spec folder sees almost none of it.

### 4.5 Module naming diverges from the architecture

The specs largely do not use the canonical module names from `overview.md` §5. `ServiceCatalog`, `PlotReservation`, `Quotation`, `AgreementCertificate`, `VendorFulfillment`, `GraveRegistry`, `CareSubscription` all score **0 mentions** — even though their *behaviour* is covered by an owning spec. This is cosmetic relative to §2.2 but it defeats automated traceability between architecture and spec.

---

## 5. Cross-spec conflicts

### 5.1 HIGH — five tables claimed by two specs each (verified)

```
$ (extract table names from each design.md, group by name, report count > 1)
agreements               claimed by: certificates-and-agreements  pre-need-contracting
agreement_versions       claimed by: certificates-and-agreements  pre-need-contracting
blocks                   claimed by: cemetery-directory-and-availability  plot-inventory-and-reservation
plot_status_events       claimed by: cemetery-directory-and-availability  plot-inventory-and-reservation
plot_units               claimed by: cemetery-directory-and-availability  plot-inventory-and-reservation
```

**(a) `agreements` / `agreement_versions` — genuine ownership conflict.**
`certificates-and-agreements` design lists `agreements`, `agreement_versions`, `agreement_acceptances`. `pre-need-contracting` design lists `agreements`, `agreement_versions` in its own aggregate. Neither defers to the other. Whichever is built first defines the schema; the second will either duplicate or conflict. **Resolution: `certificates-and-agreements` should own the tables; `pre-need-contracting` should reference them.** Needs an explicit statement in both.

**(b) `blocks` / `plot_units` / `plot_status_events` — softer, and nearly resolved.**
`cemetery-directory-and-availability` marks them `(optional)` and describes an *"optional `PlotInventory` adapter"`, which reads as deference. `plot-inventory-and-reservation` owns them outright. The intent is probably right; it is just not stated. **Resolution: one sentence in the directory spec — "these tables are owned by `plot-inventory-and-reservation`; the directory consumes a read projection."**

### 5.2 HIGH — marketplace task vs marketplace AC

Covered in §2.4a. It is both an `AGENTS.md` violation and an internal contradiction within the same spec folder — `tasks.md` disagrees with `requirements.md` AC4 and with `design.md`.

### 5.3 MEDIUM — same concept, two schemas: care work orders

Not caught by exact-name matching, found by reading:

| `recurring-care-subscriptions` design | `grave-care-fulfillment` design |
|---|---|
| `care_work_orders` | `work_orders` |
| `care_evidence` | `work_evidence` |
| `subscription_cycles` | `care_cycles` |
| `subscription_invoices` | — |
| — | `work_order_tasks`, `service_acceptances`, `service_complaints`, `make_good_orders` |

Two specs model the same lifecycle with different table names. `recurring-care-subscriptions` does declare *"Fulfillment behavior is detailed in `grave-care-fulfillment`"* (the corpus's only cross-reference) — so the intent is a split: subscriptions own billing, fulfilment owns work. But then `recurring-care-subscriptions` should **not** define `care_work_orders`/`care_evidence` at all.

Also note both `care_cycles` and `subscription_cycles` exist for what appears to be one concept. **Resolution: subscriptions own `subscriptions` + `subscription_cycles` + `subscription_invoices`; fulfilment owns `work_orders` + `work_evidence`; remove the duplicates from the subscriptions design.**

### 5.4 MEDIUM — undeclared boundary: wizard vs orchestration

`public-booking-wizard` (15 AC) and `booking-and-order-orchestration` (14 AC) both cover the nine steps. The boundary is *implied* — wizard = presentation/draft, orchestration = domain/quote/payment/state — and both `design.md` files are consistent with it. But **neither states it**, and the AC overlap:

| Concern | Wizard | Orchestration |
|---|---|---|
| Nine-step presentation | AC1 | AC1 |
| Autosave / resume | AC11, AC12 + task | AC2 + task |
| Immutable quote | AC6 | AC8 |
| Private documents | AC8 | AC10 |
| Payment gate / manual fallback | AC9 | AC6, AC7 |
| Step 9 confirmation content | AC10 | AC13 |
| Admin/operator notification | AC15 | AC14 |
| Server prevents step skipping | AC13 | AC3 |

Eight duplicated concerns. Both `tasks.md` also contain an autosave task. **Risk: double implementation or, worse, each assuming the other did it.** Resolution: add a "Boundary" section to both — *"the wizard owns presentation, draft persistence, and step navigation; orchestration owns product-type routing, quote versioning, payment guard, and order state."*

### 5.5 MEDIUM — unresolved tension: lightweight At-Need vs mandatory nine-step

| Source | Statement |
|---|---|
| `AGENTS.md` | *"Booking exposes Steps 1–9 exactly as documented."* |
| `public-booking-wizard` AC14 | Urgent may branch internally but keeps *"a clear progress/outcome consistent with the nine-step entry."* |
| `at-need-booking` negative criteria | *"No long Pre-Need wizard imposed on urgent family."* |
| `at-need-booking` design | *"A lightweight intake component…"* with its own sequence: `Intake → triage → case owner → availability/reservation → quote → payment policy → service coordination → completion` |

`at-need-booking/design.md` **never mentions the nine-step entry.** Both positions are individually right — urgent families should not face a long wizard, and the nine-step framing is a product contract. `booking-wizard-fields.md` §Branching resolves it correctly (*"The UI retains the stakeholder's nine-step framing. Internal workflow may shorten operational data collection for Urgent"*), but `at-need-booking` does not cite that resolution, so an agent reading only that folder will build a parallel intake outside the nine-step entry — violating `AGENTS.md`.

**Resolution: cite `booking-wizard-fields.md` §Branching in `at-need-booking`, and state that the entry point remains the nine-step wizard.**

Related, smaller: `at-need-booking` AC4 permits required documents *after* service, while wizard AC8 has Step 7 capturing uploads. Not contradictory, but the wizard needs an explicit conditional-requirement mode for the Urgent branch, and neither spec says so.

### 5.6 LOW — payout appears in three specs

Manual/outgoing payment or payout recording appears in `admin-operations` (AC5, and "Record manual vendor payout" as a sensitive action), `funeral-marketplace-and-vendor-portal` (AC11 + `vendor_payouts` + task), and indirectly `grave-care-fulfillment` (compensation/refund). `vendor_payouts` is declared only by marketplace, so the schema is unambiguous — but *who owns the workflow* is not. Low risk because both agree on finance-role restriction and audit.

### 5.7 No conflicts found

Checked and clean: memorial vs grave registry (memorial AC7 explicitly separates lifecycles ✅); visitation vs directory capability (both route through `CemeteryCapability` ✅); operator vs admin authority (operator AC5 explicitly cedes final authority ✅); package bundles vs service catalog (bundles reference versions rather than redefining ✅).

---

## 6. Gap vs MVP scope

### 6.1 Stakeholder MVP items → spec coverage

Against [`mvp-scope.md`](../product/mvp-scope.md):

| MVP item | Spec | Status |
|---|---|---|
| §1 Four public entry points | `public-home-and-navigation` | ✅ |
| §2 Booking Steps 1–9 | `public-booking-wizard` + `booking-and-order-orchestration` | ✅ spec-complete; ⚠️ Steps 7–9 depend on missing foundation specs |
| §3 Marketplace catalogue + cart + checkout + vendor processing | `funeral-marketplace-and-vendor-portal` | ✅ but see §2.4a |
| §4 Renewal 6 steps + fuzzy search + honest empty + external marking | `renewal-and-grave-registry` | ✅ strongest spec in the corpus |
| §5 FAQ 6 categories | `public-faq` | ✅ |
| §6 Admin dashboard modules | `admin-operations` | ✅ |
| §6 Vendor dashboard modules | `funeral-marketplace-and-vendor-portal` | ✅ (per `specs/README.md`) |
| §7 Gated fallback rules (6 gates) | scattered across 7 specs | ⚠️ no spec owns `FeatureGate` |

**Every stakeholder MVP item has an owning spec. No MVP item is unspecified at the vertical level.** That is a real strength.

### 6.2 CRITICAL gaps — MVP-required behaviour with no spec

These are required by `mvp-scope.md` / `AGENTS.md` / `release-gates.md` and owned by no spec:

| Missing spec | Required by | Consequence |
|---|---|---|
| **Notifications** | `mvp-scope.md` §2 Step 9, §7 (WhatsApp gate); `notification-matrix.md`; wizard AC15; orchestration AC14 | 6 specs depend on it; Step 9 cannot be built |
| **Payment adapter / webhook** | `mvp-scope.md` §2 Step 8, §3, §4; `payment-webhook.md`; `release-gates.md` §C | 7 specs depend on it; Step 8 cannot be built |
| **Identity / auth / MFA** | `AGENTS.md` (session auth + TOTP MFA); ADR-0024; `release-gates.md` §H | every panel depends on it |
| **Document vault / upload pipeline** | `mvp-scope.md` §2 Step 7; ADR-0023; `file-upload-pipeline.md` | Step 7 + all evidence flows |
| **Audit** | `AGENTS.md`; K8; ADR-0005 | **13 files / 9 specs** mention audit; reasoned dependency is ~18 of 19 |
| **Feature gate** | ADR-0006; `feature-flag-registry.md`; 17 gates in `assumptions-and-gates.md` | all gated fallbacks |
| **Transactional outbox** | ADR-0019; `outbox-event-contract.md`; `queue-and-outbox.md` | **0 mentions anywhere** in `.kiro/` |
| **Financial ledger / settlement** | ADR-0020; `financial-ledger-and-settlement.md`; `release-gates.md` §H | 5 specs reference journal/reconciliation |

Two smaller screen-level gaps: **PUB-050 Customer order status** (timeline, next step, support) and **PUB-060 Help/contact** (channels, hours, emergency disclaimer) are in `screen-inventory.md` but owned by no spec — order tracking is implied by orchestration AC13, help is implied by the CS CTA, neither is specified.

### 6.3 Over-scope — correctly labelled, with one exception

`mvp-scope.md` §8 explicitly excludes nine capabilities from MVP acceptance. Five specs cover excluded ground, and **all five are correctly labelled optional/gated**:

| Spec | `mvp-scope.md` §8 exclusion | Labelling |
|---|---|---|
| `plot-inventory-and-reservation` | Public specific-plot selection | ✅ "Optional/gated B05" |
| `memorial-and-qr` | Memorial/QR | ✅ "Optional/privacy-gated P2/B07" |
| `visitation-booking` | Visitation booking | ✅ "Optional P1/B06" |
| `pre-need-contracting` (paid path) | Paid Pre-Need | ✅ gate G-LEGAL-01, paid states unreachable |
| `recurring-care-subscriptions` (tokenization) | Card-on-file | ✅ behind feature flag; raw PAN prohibited |

**This is exactly the discipline `AGENTS.md` asks for** — benchmark extensions stay `Proposed`/`Optional`/`Gated` and do not leak into MVP.

The one exception is the marketplace multi-vendor task (§2.4a), which pulls `mvp-scope.md` §8 "Multi-vendor partial refund automation" into an MVP-required spec's task list without a gate label.

Two specs are neither MVP-required nor labelled optional: `funeral-case-management` ("Proposed P0") and `at-need-booking`. Since Urgent **is** in MVP (Step 3 `URGENT_TODAY`) and `AGENTS.md` requires At-Need/Urgent to create a FuneralCase, these are effectively MVP-required despite the "Proposed" label. **Worth reclassifying.**

---

## 7. Readiness

### 7.1 Environment blocker applies to all 19

Per the [repository analysis](sprint-plan.md) §1: zero application code, `makam_dev`/`makam_stg` do not exist, `pg_trgm`/`unaccent` not created, no CI. **No spec is executable today.** The table below therefore reports readiness *after Sprint 1 convergence* (`php artisan migrate` succeeds), and grades **spec quality** separately from **execution blockers**.

### 7.2 Readiness table

| Spec | Spec quality | Blockers | Verdict |
|---|---|---|---|
| `public-faq` | Good — 9 AC, clear, mentions empty/responsive/accessible | scaffold + DB | 🟢 **READY** — cheapest complete vertical slice; build first |
| `public-home-and-navigation` | Good — 9 AC, only spec with real a11y design notes | scaffold + DB; needs `FeatureGate` for AC6 explanatory pages | 🟢 **READY** (stub the gate) |
| `cemetery-operator-dashboard` | **Best-formed** — 8 AC ↔ 8 tasks, metrics, non-blocking design | scaffold + DB + identity spec | 🟡 **READY-ish** — needs auth foundation |
| `cemetery-directory-and-availability` | Strong — 12 AC + negative criteria | scaffold + DB; §5.1b table ownership | 🟢 **READY** after one-line ownership note |
| `public-booking-wizard` | Strong — 15 AC, 12 tasks, 0.80 ratio | Steps 7–9 need document vault + payment + notification specs | 🟡 **PARTIAL** — Steps 1–5 ready; 6–9 blocked |
| `renewal-and-grave-registry` | **Strongest** — 16 AC, perf targets, idempotency keys, privacy modes | `pg_trgm` (Sprint 1); payment spec for AC8; data gate G-DATA-01 | 🟡 **PARTIAL** — registry + search ready; payment blocked |
| `package-and-service-bundles` | Good — 8 AC, versioning discipline | scaffold + DB; quote ownership vs orchestration | 🟡 **READY-ish** |
| `booking-and-order-orchestration` | **Needs repair** — 14 AC → 6 tasks (0.43); boundary vs wizard undeclared (§5.4) | payment + document + notification + outbox specs | 🔴 **SPEC WORK FIRST** |
| `funeral-marketplace-and-vendor-portal` | **Needs repair** — multi-vendor task contradicts AC4 (§2.4a); catalogue duplicated (§2.4b) | payment + payout + identity + document specs | 🔴 **SPEC WORK FIRST** |
| `at-need-booking` | **Needs repair** — nine-step tension unresolved (§5.5) | `funeral-case-management`; ops gate G-OPS-01 | 🔴 **SPEC WORK FIRST** |
| `admin-operations` | Adequate — 11 AC, good Actions/Resources boundary; **no UI states at all** | nearly every foundation spec; identity + audit | 🟡 **BLOCKED on foundations** |
| `funeral-case-management` | Good — 9 AC, state machine, escalation keys | identity + notification + audit specs; scheduler/Horizon | 🟡 **BLOCKED on foundations** |
| `grave-care-fulfillment` | Adequate — 8 AC; schema overlaps subscriptions (§5.3) | vendor portal + payment; §5.3 resolution | 🟡 **BLOCKED** |
| `recurring-care-subscriptions` | Good — 8 AC ↔ 8 tasks, explicit idempotency key | payment + webhook specs; G-TOKEN-01 | 🟡 **BLOCKED** |
| `certificates-and-agreements` | Thin — 8 AC, 5 tasks, terse design | **G-CERT-01 closed**; §5.1a ownership | 🔴 **GATE-BLOCKED** |
| `pre-need-contracting` | Good — correctly disciplined about closed gate | **G-LEGAL-01 closed**; 3 legal/accounting decisions open | 🔴 **GATE-BLOCKED** (interest path buildable) |
| `plot-inventory-and-reservation` | Strong — 9 AC, atomic locking, circuit breaker | **G-PLOT-01 closed**; needs authoritative source + reservation TTL decision | 🔴 **GATE-BLOCKED** |
| `visitation-booking` | Thin — 7 AC, terse design | **G-VISIT-01 closed**; excluded by `mvp-scope.md` §8 | 🔴 **GATE-BLOCKED** |
| `memorial-and-qr` | Good — 8 AC, strong privacy model | **G-MEM-01 closed**; consent model undecided; excluded by §8 | 🔴 **GATE-BLOCKED** |

### 7.3 Tally

| Verdict | Count | Specs |
|---|---:|---|
| 🟢 **READY** after Sprint 1 | **3** | `public-faq`, `public-home-and-navigation`, `cemetery-directory-and-availability` |
| 🟡 **PARTIAL / READY-ish** | **7** | wizard (1–5), renewal (registry+search), operator dashboard, bundles, admin-ops, case-management, care×2 |
| 🔴 **SPEC WORK FIRST** | **3** | orchestration, marketplace, at-need |
| 🔴 **GATE-BLOCKED** | **5** | certificates, pre-need, plot-inventory, visitation, memorial |

### 7.4 Recommended order of work

1. **Author 8 foundation specs** (§2.2) — the largest unblocker in the corpus. Source material already exists in `docs/`; this is consolidation, not design.
2. **Repair the 3 red specs** (§2.4a, §5.4, §5.5) — small edits, high value.
3. **Resolve 5 duplicate table ownerships** (§5.1, §5.3) — one sentence each.
4. **Add the ten-UI-states line + task to all 19** (§3.2) — ~19 one-line edits, closes issue #3.
5. **Add canonical-doc references** (§4.4) — especially `marketplace-catalog.md`, `mvp-scope.md`, `notification-matrix.md`, `rbac-matrix.md`, `design-system.md`.
6. **Then build**: `public-faq` → `public-home-and-navigation` → `cemetery-directory` → wizard Steps 1–5.

Steps 1–5 are documentation work executable **today**, in parallel with Sprint 1 infrastructure — no code or database required.

---

## 8. NOT TESTED / NOT VERIFIED

Per `AGENTS.md`: *"Never report `PASS` for a check that was not executed; use `BLOCKED` or `NOT TESTED` explicitly."*

### Verified — executed, evidence inline

| Check | Result |
|---|---|
| Spec count = 19 (not 21); 57 files; all triads complete | **PASS** — `ls`/`find` output §0.1, §1.5 |
| All K23–K35 referenced, no orphan | **PASS** — grep §1.6 |
| AC and task counts per spec (191 AC / 133 tasks) | **PASS** — counted §4.2 |
| No broken file references (4/4 targets exist) | **PASS** — §4.4 |
| Design-system keyword counts (all 0 except 1 `color`) | **PASS** — §3.1 |
| UI-state keyword counts; `loading` = 0 corpus-wide | **PASS** — §3.2 |
| `outbox`, MFA/TOTP, re-auth, 5-minute URL, Horizon, expand/contract = 0 | **PASS** — §2.3 |
| 5 tables claimed by 2 specs each | **PASS** — extraction §5.1 |
| `marketplace-catalog.md` and `mvp-scope.md` referenced by 0 specs | **PASS** — §2.4 |
| No `tasks.md` declares dependency/estimate/DoD (3 hits inspected, all false positives) | **PASS** — §4.3 |
| 6 architecture modules with 0 spec mentions | **PASS** — §2.2 |

### NOT TESTED

| Item | Status |
|---|---|
| Whether any spec is **implementable** | **NOT TESTED** — no code exists; nothing was built from any spec |
| Whether ACs are **technically achievable** (e.g. renewal < 500 ms at 100k) | **NOT TESTED** — no database, no dataset, no benchmark |
| Whether the AC set is **sufficient** to produce correct software | **NOT TESTED** — that is only knowable by building |
| Conformance to RKS K23–K35 **content** | **BLOCKED** — the RKS source document is not in the repository; only K-code labels could be checked, never the requirements behind them |
| Whether §5 conflicts are **real defects or intentional layering** | **PARTIALLY VERIFIED** — table collisions are mechanically confirmed; *intent* is inferred from reading and may be wrong. Confirm with the spec authors. |
| Kiro tooling compatibility of the spec format | **NOT TESTED** — no Kiro agent was run; structural conformance judged against `docs/specs/README.md` only |
| Whether `design.md` files are internally consistent with `requirements.md` beyond the checks above | **PARTIAL** — read in full, but no systematic AC-by-AC cross-check |
| Whether the 20 negative criteria are enforceable as written | **NOT TESTED** — no tests exist |
| `traceability-matrix.md` "Covered" claims | **CONFIRMED FALSE** (prior finding H-3) — 32 items marked `Covered` with zero tests in existence |

### Judgement calls, flagged as such

The **severity ratings** in §0.3 are my assessment, not a project-agreed scale. The **readiness verdicts** in §7.2 combine measured spec quality with inferred dependencies — the inference is mine and should be reviewed. The **recommended order** in §7.4 is a recommendation, not a validated plan.

Where I judged something to be a "gap of omission" rather than a violation (notably §3.1, design-system absence explained by chronology), I have said so rather than scoring it as non-compliance.
