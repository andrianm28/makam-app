# ADR-0044: Approve a five-tab bottom navigation, superseding IA §2's mobile-nav rule and resolving OQ-04

## Status

Accepted — 23 Sep 2026. **Supersedes
[`docs/product/information-architecture.md`](../product/information-architecture.md)
§2's mobile-navigation rule** ("logo + hamburger + persistent Bantuan," no
persistent tab bar) for mobile viewports specifically — desktop's header
nav (§2's desktop row) is untouched. **Resolves OQ-04**
(`docs/design/design-system.md` §11, §3.11), which tracked mobile bottom
navigation as "proposed, not approved" pending exactly this decision.
**Does not adopt** `design-system.md` §3.11's own draft 4-item proposal —
that draft is superseded by the 5-item form below, which matches
[ADR-0043](0043-ffi-full-visual-clone-supersedes-system-layer-only.md)'s
already-approved FFI page-structure decision and the design spec built
from it.

Records a decision made 23 Sep 2026, surfaced while resolving Stage 2
ticket 02 (`<x-mk.bottom-nav>`) of the FFI visual-clone work: three of
this repository's own documents disagreed with each other about whether a
bottom nav is approved at all, and if so, with how many tabs.

## Context

Three specs existed for mobile navigation at the moment this was noticed,
none of them reconciled with the others:

1. **`information-architecture.md` §2 (approved, binding).** Mobile
   navigation is "logo + hamburger navigation + persistent 'Bantuan'... menu
   labels tetap sama dengan desktop." No persistent tab bar of any kind.
2. **`design-system.md` §3.11 (drafted, explicitly marked "⚠️ PROPOSED,
   NOT APPROVED," tracked as OQ-04 since its own §11 open-questions table).**
   A 4-item bottom nav, blocked on exactly the product-approval step this
   ADR now provides. Its own text is direct: *"This component is not in the
   approved IA... `AGENTS.md` forbids inventing alternate navigation or
   labels without product change approval... Do not ship it without a
   product decision recorded in `mvp-scope.md` or an ADR."*
3. **`docs/superpowers/specs/2026-09-22-ffi-full-visual-clone-design.md`
   §3 (already approved, under ADR-0043's authority), Stage 2 ticket 02
   (`.scratch/ffi-clone-stage2-components/issues/02-mk-bottom-nav.md`).**
   A 5-item bottom nav — Beranda (`/`), Pemesanan (`/pemesanan-makam`),
   Perpanjangan (`/perpanjangan`), Akun (`/akun`), Bantuan (`/bantuan`) —
   written from ADR-0043's FFI-page-structure decision without
   cross-checking it against either of the two documents above. That gap
   is what this ADR closes.

ADR-0043 already established that the owner wants FFI's visual identity
and page structure, including its navigation pattern, having been told
directly that FFI's codebase is an unrebranded Kitabisa clone and having
confirmed wanting it regardless — twice. Put the specific three-way
conflict above to the owner; the answer was to approve the 5-item version,
matching ADR-0043's own direction, over both the IA's no-bottom-nav
default and `design-system.md` §3.11's own unapproved 4-item draft.

## Decision

1. **A five-tab bottom navigation is approved for mobile viewports** (below
   the `lg` breakpoint), with the canonical labels and routes: Beranda
   (`/`), Pemesanan (`/pemesanan-makam`), Perpanjangan (`/perpanjangan`),
   Akun (`/akun`), Bantuan (`/bantuan`) — matching the FFI full-visual-clone
   design spec's own §3, not `design-system.md` §3.11's 4-item draft.
2. **`information-architecture.md` §2's mobile-navigation rule is
   superseded for mobile specifically.** The persistent-Bantuan requirement
   is satisfied by the Bantuan tab; the hamburger-menu requirement is
   satisfied by whatever secondary/overflow navigation the implementation
   plan for `<x-mk.bottom-nav>` and its surrounding header decides —
   **not fixed by this ADR**, since that is an implementation detail for
   `writing-plans`, not a product-scope one. Desktop's header row (§2's
   desktop navigation) is untouched.
3. **OQ-04 is resolved: approved**, not "IA-compliant header only" as
   `design-system.md` §11's table currently records it. `design-system.md`
   §3.11's "⚠️ PROPOSED, NOT APPROVED" warning and its own 4-item draft are
   superseded by this ADR — the implementation plan for ticket 02 should
   update that section to describe the approved 5-item form (additive
   supersession, this repo's established convention for this document —
   see ADR-0041/ADR-0043's own precedent there — not a silent rewrite).
4. **This does not re-open ADR-0043's four-services-vs-six-tiles
   question**, which remains a separate, still-unresolved conflict between
   `AGENTS.md`'s four-primary-service-card MUST and FFI's own six-tile
   quick-action grid. Bottom navigation is a navigation-chrome decision;
   the homepage service-card count is a content-scope decision. Nothing
   here settles the latter.

## Consequences

- **Stage 2 ticket 02's plan can now proceed** without stopping to resolve
  a product-scope question mid-plan — the specific blocker
  (`design-system.md` §3.11's own explicit "do not ship without a product
  decision" instruction) is satisfied by this ADR.
- **`design-system.md` needs a real edit**, not just this ADR sitting
  beside it: §3.11's warning banner, its item list (4 → 5, with the correct
  routes/labels), and §11's OQ-04 row all need to reflect "resolved,
  approved" rather than "proposed, not approved." This ADR names what must
  change; the edit itself belongs to ticket 02's implementation plan,
  matching how ADR-0043 itself deferred its own token-value edits to
  `writing-plans` rather than making them inline.
- **`information-architecture.md` §2 needs the same treatment** — its
  mobile-nav bullet list currently states a rule this ADR supersedes for
  mobile. Also deferred to ticket 02's plan, not made here.
- **The hamburger-menu question is now open, not closed.** IA §2's mobile
  row listed "hamburger navigation" as one of three elements; a 5-tab
  bottom nav does not obviously need one too (FFI's own reference site
  doesn't pair a bottom nav with a hamburger). Whether Makam's mobile
  header keeps a hamburger alongside the new bottom nav, drops it, or
  replaces it with something else is genuinely undecided — named here so
  it is not silently assumed either way when ticket 02's plan (or a later
  header-focused ticket) reaches it.
- **No `tokens.css` or component-code change follows directly from this
  ADR.** `--mk-z-bottomnav` and `--mk-z-sticky-cta` already exist
  (`tokens.css` §2.9); `<x-mk.bottom-nav>` itself is ticket 02's own
  implementation work, unblocked but not started by this decision.

## What this ADR deliberately does not do

- It does not design the hamburger-menu / bottom-nav coexistence on
  mobile — flagged above as newly open, not resolved.
- It does not touch desktop navigation.
- It does not resolve ADR-0043's four-services-vs-six-tiles conflict.
- It does not edit `design-system.md` or `information-architecture.md`
  itself — both need real edits, deferred to ticket 02's implementation
  plan, the same way ADR-0043 deferred its own token-value edits.
- It does not approve or design any navigation item beyond the five named
  here (no sixth tab, no per-tab badge/notification affordance, etc.) —
  those are separate decisions if and when they come up.

## Amendment 1 (23 September 2026, Stage 2 ticket 02 plan-writing): hamburger-menu coexistence — decided, not left open

**Finding.** This ADR's own "What this ADR deliberately does not do"
section states plainly that it "does not design the hamburger-menu /
bottom-nav coexistence on mobile," naming it newly open. That question was
in fact put to and answered by the owner while writing Stage 2 ticket 02's
implementation plan, the same day this ADR was accepted — but the answer
was never recorded here. In the meantime, both `design-system.md` §3.11
and `information-architecture.md` §2 were edited (ticket 02, Task 2) to
describe the bottom nav coexisting with a kept hamburger and to cite this
ADR as that decision's authority. That citation was wrong at the time it
was written: this document, as originally accepted, explicitly declines to
make that call. A later reader tracing either doc's citation back here
would find this ADR contradicting the very claim it was cited for.

**Decision (this amendment).** While planning ticket 02, the 5 canonical
tabs (Beranda, Pemesanan, Perpanjangan, Akun, Bantuan) were found not to
cover two real desktop navigation items — Layanan Pemakaman and FAQ — that
`information-architecture.md` §2's desktop row still requires reachable on
every viewport ("menu labels tetap sama dengan desktop"). Put to the
owner as a choice between dropping those two items from mobile entirely,
folding them into one of the five existing tabs, or keeping
`<x-mk.header>`'s existing hamburger menu alongside the new bottom nav
specifically to reach them: **the owner chose to keep the hamburger
menu alongside the bottom nav.** This resolves the open question this
ADR originally left standing — `design-system.md` §3.11 and
`information-architecture.md` §2 may now cite ADR-0044 for the
hamburger-coexistence decision without qualification; the "deliberately
does not do" bullet above is superseded by this amendment and kept only
for its historical record of what this ADR did not settle on 23 September
2026 before ticket 02's plan was written.

## Alternatives considered

- **Adopt `design-system.md` §3.11's own 4-item draft instead of the
  5-item FFI form.** Rejected by the owner: it predates ADR-0043's
  FFI-fidelity direction and doesn't match FFI's actual navigation, which
  is exactly what ADR-0043 already committed to matching.
- **Hold ticket 02 and ship Stage 2 with only the other two tickets
  for now.** Considered and not chosen — the owner elected to resolve the
  conflict now rather than defer it, since the FFI-fidelity direction was
  already the standing instruction and this ADR is a small, bounded
  decision (which of three existing drafts wins), not a new design
  question requiring its own brainstorming round.
