# Design — seven days to the Dinas Pemakaman demo

**Date:** 18 September 2026
**Demo:** 27 September 2026, to Dinas Pemakaman
**Owner's ready-by date:** 25 September 2026
**Status:** approved by the owner, 18 Sep 2026, through the questions recorded below

## What this is, and what it is not

This is a delivery plan for a demo, not a step toward MVP acceptance. Those are
different targets and conflating them would waste the week.

`docs/testing/release-gates.md` holds 65 acceptance checkboxes, of which 42 are
met. Of the 23 open, three need Business/Legal/Finance sign-off, nine need
owner or ops action, seven are engineering work, and four are statements that
need rewriting. **Even if every engineering item were closed in seven days,
twelve gates would remain and none of them is engineering.** MVP acceptance by
25 September is not achievable, and it is not achievable for reasons that do
not respond to effort.

The owner's instruction is explicit: focus technical, set legal and FIN-DEC
aside. This plan does that.

## What "selesai" means here

Not "everything works" — that cannot be checked. Instead:

> **The demo script runs end to end on `makam.co.id`, twice in a row, without
> intervention.**

The owner writes that script on Day 1. It becomes the single reference for
every priority decision in the days after, which is what converts an
unmeasurable target into a checkable one.

## The decision that dominates everything

**Nothing built in the last two months has reached a single user.**

Measured 18 Sep 2026 against the live containers:

| Environment | Running commit | Behind trunk |
| --- | --- | --- |
| dev | `9de0e907` | **128 commits** |
| beta | `7d3bcb81` | **126 commits** |

Every security fix, the whole brand rebase, the refund system, the recovery of
three stranded PRs — none of it is live. If 25 September arrives and the runner
runbook has not been executed, the answer to "what did the last two months
deliver?" is: nothing anyone can see.

That makes deployment, not development, the critical path for this week.

## Three decisions the owner made

### D1 — scope: public journeys AND the operator/admin panel

The Dinas sees both the citizen's side and their own. The checkbox tracking
suggested this was impossible — `admin-operations` reads 1/15 tasks,
`cemetery-operator-dashboard` reads 0/15 — but the checkboxes are wrong, and
measurably so. The repository holds **28 Filament resources across 252 files
and three panels**: Admin, Operator, Vendor. `/operator` already registers a
Dashboard, InAppNotifications, a cemetery-scoped **PlotFloorMap**, and
CemeteryOrderResource.

The work is therefore deploy, verify and fix — not build.

### D2 — data: example data, clearly marked

Beta holds ten fabricated cemeteries named like real places — "TPU Jakarta
Menteng", "TPS Depok Cinere". PR #330 appends "(pemakaman contoh)" to each
name and is ready to merge.

The reasoning is not squeamishness. Showing fabricated TPUs as real to the
agency that administers real TPUs is a risk with no upside, and the alternative
— loading the Dinas's own cemetery data — requires firm package prices the
owner does not yet have, without which the booking journey cannot complete at
all.

### D3 — environment: beta, with the runner activated on Day 1

The demo runs on `makam.co.id`. The runner runbook executes on Day 1 so that
every later merge flows to dev and then beta automatically.

`ci.yml` already enforces the order: `deploy-beta` carries
`needs: [build-image, deploy-dev]`, so dev is always the rehearsal. Both jobs
additionally require the repository variable `MAKAM_DEPLOY_RUNNER_ACTIVE` to
equal `true`; the runbook's Step 7 sets it. Zero runners are registered today
and the variable is unset, which is why every trunk push has been skipping both
jobs safely rather than hanging.

**Amendment to the runbook, for this week only.** Step 6 as written sets
`required_approving_review_count=1` with `enforce_admins=true`. That would put
the owner on the critical path of every fix, with no bypass for anyone. For
this week, run Step 6 with `required_approving_review_count=0`, keeping all ten
required status checks — nothing merges without green CI, which is the part
that actually protects a compressed week — and raise it to 1 after the demo.
That also closes `COORD-17` one week later than it otherwise would.

## The spine — eight calendar days, 18 to 25 September

18 to 25 September inclusive is eight days, not seven. The spine below uses
seven working days and leaves **25 September as spare**, which is also the
owner's stated ready-by date. Spare time before a demo is not slack to be
spent; it is the only defence against the day something takes twice as long as
expected.

**Day 1 — 18 Sep. The owner holds the key.**
Owner: execute the runner runbook with an operator present, Step 6 amended as
above. In parallel, write the demo script — exactly what the Dinas will be
shown, click by click.
Agent: prepare PR #330 for merge, confirm the trunk image built, and turn the
script into a UAT checklist.

**Merge authority must be settled on Day 1, explicitly.** Removing the
review-count requirement stops GitHub from blocking a merge; it does not grant
the agent permission to merge. This week needs many small merges on short
notice, so the owner either grants standing approval for merges to trunk
between 18 and 25 September for changes inside this plan's scope, or approves
each one and accepts being on the critical path. Either answer works. No answer
is the one that stalls the week, so it is asked on Day 1 rather than discovered
on Day 3.

**Day 2 — first deploy, first surprises.**
128 commits reach dev, then beta. Four migrations, none destructive in `up()`.
The agent walks the entire script on dev first and records everything broken
**without fixing any of it** — inventory before triage, so priority comes from
data rather than from the order of discovery.

**Day 3 — the SumoPod smoke test, and blocker fixes.**
The riskiest day. Sandbox credentials are present on beta
(`SUMODOP_SANDBOX_API_KEY`, 64 characters; webhook secret, 38), and the two
empty variables fall back to correct defaults — but ADR-0033 records the live
sandbox call as `NOT TESTED` and nobody has ever made one from this system.
If checkout fails and cannot be fixed that day, close `G-PAY-01` and run the
demo on the manual path. **That decision is taken on Day 3, not Day 6.**

**Days 4-5 — fix in script order, deploying each fix.**
The operator panel enters here. `/operator` has never been walked as a journey
by anyone; it is the largest pocket of uncertainty in this plan.

**Day 6 — browser E2E for the script path only**, so the rehearsal can be
repeated without manual clicking. Then the first full rehearsal.

**Day 7 — 24 Sep. Two clean rehearsals, then freeze.**
No code changes after the second rehearsal passes, whatever remains open.
Fixing on demo day is how demos fail.

**25 Sep — spare, and the owner's ready-by date.** If the spine held, this day
is used for nothing but a third rehearsal. If it did not, this is the day that
absorbs the overrun — and it is the last one that can.

## The freeze list

**PR #334 is held until 28 September.** Not because it is wrong — it is
reviewed, mutation-verified and CI-green — but because its D5 gate closes the
plot picker entirely when the selected package has no firm price, and on beta
**one cemetery is granular and zero packages carry a firm price version**.
Merging it would remove a demoable feature.

The general rule for the week: **accept only changes that make something work,
never changes that make something refuse to work.**

## The cut-line, decided now rather than under pressure

Dropped from the bottom if Day 5 shows the week does not fit:

1. **Day 6's browser E2E.** It is a rehearsal aid, not part of the demo. Two
   manual rehearsals remain valid.
2. **The operator panel beyond one flow.** If `/operator` is broken more deeply
   than expected, show one working flow — most likely PlotFloorMap, being the
   most visual and the most relevant to a cemetery agency — and open nothing
   else.
3. **Marketplace.** Of the four public journeys it is the furthest from a
   cemetery agency's concerns.
4. **Payment checkout.** If Day 3's smoke test fails unrecoverably, close
   `G-PAY-01`. `PaymentMode::fromGateOpen()` flips the whole flow to
   `ManualCoordination`, which is built and covered by
   `VerifyManualPaymentTest` and `VerifyManualPaymentRouteTest`. The demo stays
   whole through "order received".

**Never cut: grave booking through to a created order, and renewal.** Both are
the Dinas's own business. If either cannot be saved by Day 5, that is no longer
a scoping question — it is a signal that the date needs renegotiating, and the
agent says so on Day 5 rather than Day 7.

## Baseline measured on the live beta, 18 Sep

Today, on 126-commit-old code:

| Route | Status |
| --- | --- |
| `/` · `/pemesanan-makam` · `/marketplace` · `/perpanjangan` · `/faq` | HTTP 200 |
| `/operator` · `/admin` | HTTP 302 to login, as expected |

`/perpanjangan` renders the real "Cari Makam" search rather than a gated
fallback — `G-DATA-01` is open. `/pemesanan-makam` renders the full wizard.

**The wizard's four screens are the complete journey, not a truncation.** PR
#218 regrouped the original nine steps into four screens on 31 Aug 2026
(`specs/2026-08-29-wizard-screen-consolidation-design.md`), and it ends at
Konfirmasi.

Feature gates on beta: `G-PAY-01` (online payment) and `G-DATA-01` (grave
search) are **open**; all fifteen others are closed. The plot picker is **not**
gated on `G-PLOT-01` — that identifier appears nowhere in `app/`. It is gated
on `plot_tracking_mode === GRANULAR`, so it is demoable today.

## Risks and their owners

| Risk | Owner | When it is decided |
| --- | --- | --- |
| Runbook not executed → nothing deploys, whole plan slips a day | **Owner** | Day 1 |
| SumoPod sandbox has never been called from this system | Agent tests, **owner decides fallback** | Day 3 |
| 128 commits carry the entire brand rebase — **beta will look substantially different**; anything previously shown to the Dinas will not match | Owner should know before Day 2 | Day 2 |
| `/operator` has never been walked as a journey; no browser test covers it | Agent | Days 4-5 |
| #334 merged by mistake closes the plot picker | Agent | standing |

## Explicitly out of scope

- MVP acceptance and the 23 open release gates, except where one happens to
  block the demo script.
- FIN-DEC approvals, `G-PAY-01`'s business gate, and every legal decision —
  set aside on the owner's instruction.
- C1 (the plot line never reaching a production quote) and PR #334's
  activation.

  > **Correction, 19 Sep 2026 — C1 is no longer undiagnosed.** It was confirmed
  > empirically (a regression test seen red before any fix) and closed by
  > PR #336, which targets `feat/plot-quote-lines` rather than trunk. The line
  > above still stands as written: #336 lands *into* the held PR #334, so
  > neither reaches the demo, and C1 remains out of scope for this week. What
  > changed is only that it is now a solved problem waiting on a hold, not an
  > open design question. PR #334's hold reason moved with it — from "C1
  > undecided" to beta carrying 0 priced cemetery packages (measured 19 Sep).
- The full browser-test suite `AGENTS.md` requires. Only the demo script's path
  gets E2E coverage this week.
- Seeding the Dinas's real cemetery data, which D2 decided against.
