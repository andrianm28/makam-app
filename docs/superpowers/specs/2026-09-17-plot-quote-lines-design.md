# Design — a grave plot as a quote line

**Date:** 17 September 2026
**Stage:** Tahap 1 of [`plans/2026-09-13-bayar-penuh-di-muka-online-saja.md`](../plans/2026-09-13-bayar-penuh-di-muka-online-saja.md)
**Status:** approved by the owner, 17 Sep 2026, through the questions recorded below

## The problem

The pay-first plan charges the customer before an operator confirms anything.
That only works if the system can compute the whole amount by itself, and the
largest component of a booking — the plot — has never appeared on a quote.
Today `ComposeQuoteLinesFromBookingDraft` maps only `selected_services`.

Tahap 1 states the requirement in one line: **"Total penawaran = petak +
layanan."** One quote, both.

## The constraint it runs into

`IssueQuote` accepts exactly one line family per quote:

- a PACKAGE line, carrying `service_package_version_id`
- a SERVICE line, carrying `service_definition_id`

`lineFamilyOf()` pins the family from the first line and rejects any later line
that differs, "because a quote snapshots ONE kind of pricing universe". That is
recorded as the P0 ruling of 14 August 2026 — and it is recorded ONLY in
`IssueQuote`'s doc block. No ADR states it.

So the approved plan and a recorded architectural decision contradict each
other, and the contradiction has to be resolved deliberately rather than by
whichever file gets edited first.

## Decisions

Each was put to the owner and answered. The reasoning is kept because it is
what justifies the shape, not decoration.

### D1 — PLOT becomes a third line family; combinations are enumerated

The set-level rule changes from "every line matches the first" to "the set of
families present must be one of an enumerated list":

| Combination | Meaning |
| --- | --- |
| `{PACKAGE}` | marketplace / operator quotes — untouched |
| `{SERVICE}` | services only |
| `{PLOT}` | a plot with no additional services |
| `{PLOT, SERVICE}` | the pay-first path: a plot and its funeral services |

Anything else is refused, naming the combination found.

This is a sharpening, not a loosening. The old rule's stated reason is that a
quote snapshots one pricing universe; this states explicitly that a plot and
the funeral services bought with it ARE one universe — one order, one currency,
one customer, one moment — while PACKAGE stays exclusive, so the marketplace
invariant is untouched. The legal combinations become a list somebody can read
instead of a rule somebody has to remember.

Rejected alternatives:

- **Drop the family concept entirely.** Fewest lines of code, but nothing would
  then stop a marketplace PACKAGE line from being mixed into a booking quote.
  Removing a guard because it blocks one case is how guards die.
- **Model the plot as a synthetic `ServiceDefinition`.** No schema change and no
  ruling to amend, but `IssueQuote`'s own doc block already refuses synthesized
  versions, and it would move plot pricing into the service catalogue — against
  Tahap 0's two-tier package/plot pricing design, and filling the service
  catalogue with entries that are not services.

### D2 — a PLOT line carries BOTH the plot and the pricing package

`quote_lines` gains two nullable columns, `grave_plot_id` and
`cemetery_package_id`, both `restrictOnDelete`, written once at issuance and
frozen — the same treatment as the three reference columns already there.

Recording both is the point. A quote is a frozen snapshot: it must state what
was sold AND which pricing vehicle applied. Deriving the package from the plot
at read time is the defect, not the safeguard — if the plot's package link
changes after issuance, a derived read returns a price version that was never
charged, and the amount becomes unexplainable. Freezing both keeps the quote
self-describing.

### D3 — the pricing vehicle is the DRAFT's package, never the plot's

`grave_plots.cemetery_package_id` is NOT used for pricing.

The migration that created it says why, in its own words: the link is "an
indicative convenience reference, **not the plot's identity**; a package row
being deleted must not take plots with it". It is `nullOnDelete`, so it can be
emptied at any time by an unrelated deletion. A charge must not rest on a
column this repository itself calls non-binding.

Measured 17 Sep 2026 on beta: 9 of 9 plots carry no package at all, and 124 of
138 drafts carry none either. A "plot first, draft as fallback" rule would have
been a priority rule whose first branch almost never fires.

The draft's package is the customer's **commercial choice** — what they agreed
to buy. The plot's link is an operator's **generation artifact**. Charging
someone against the generation artifact when they chose something else is wrong
even when the two amounts happen to match.

The line's `cemetery_package_id` therefore freezes the vehicle actually used,
which is exactly what D2 asks it to mean.

### D4 — selecting a package becomes a precondition of selecting a plot

A direct consequence of D3, stated rather than hidden. `pickerBlocks()` works
today with no package selected — `$packageId !== null` merely guards its
filters. Under D3 a plot chosen without a package cannot be charged, so the
picker must refuse to run before a package is chosen and say why, rather than
render blocks that lead to an unbillable order.

### D5 — the picker offers nothing when the selected package has no firm price

This is ONE gate, not a per-plot filter, and the difference follows from D3.
Because the pricing vehicle is the draft's package, it is the same vehicle for
every plot in the picker: either that package has a current firm `PriceVersion`
and every plot is priceable, or it has none and no plot is. A per-plot filter
would imply plots differ in price today; under D3 they do not.

(They will under Tahap 0 tier 2, when a plot may carry its own price. At that
point this gate splits into a per-plot one. It is written as a single gate now
because that is what is true now.)

**Today this gate closes the picker entirely**, because no package has a firm
price — Tahap 0's operator step has not been done. That is correct and
deliberate: offering a plot that cannot be charged is the worse defect. It does
mean Tahap 1 is unusable until an operator fills in real prices, and it means
the picker needs an empty state that says so rather than blocks that silently
vanish.

## Validation, in four layers

Which layer holds which guarantee matters more than the list.

### 1. Per row, in the database

A three-armed CHECK on `quote_lines`, one arm per family:

```
(service_package_version_id NOT NULL, other three NULL)            -- PACKAGE
(service_definition_id      NOT NULL, other three NULL)            -- SERVICE
(grave_plot_id NOT NULL AND cemetery_package_id NOT NULL,
 both service columns NULL)                                        -- PLOT
```

The third arm makes D2 structural instead of habitual: a plot line cannot be
written carrying only one of its two columns. Left to the application alone, a
stray `DB::table('quote_lines')->insert()` could write a half-formed plot line
and nothing would notice until an amount could not be explained.

This follows the established convention — `refund_obligations` uses CHECK
constraints for equivalent invariants, and its doc block records what happened
when that backstop was absent.

Verified before proposing: all 101 existing `quote_lines` rows on beta satisfy
the SERVICE arm, and none violate any arm, so the constraint applies to live
data without a data fix.

### 2. Per row, in the application

`lineFamilyOf()` rejects any line that is not exactly one key group. This
deliberately duplicates layer 1: the application produces a readable message
naming the offending line index, the database provides the guarantee.

### 3. Per set, in the application only

The enumerated combinations of D1 cannot be expressed per row — they are about
a set of rows sharing a quote. This layer has **no database backstop**, and
that is stated plainly rather than assumed away.

### 4. The frozen-snapshot cross-check for a PLOT line

Mirrors the service branch, and is the easiest part to under-build. The named
`PriceVersion` must:

- exist;
- be **current** (not superseded);
- belong to the named `CemeteryPackage` — `price_versions` is polymorphic and
  holds rows for `ServiceDefinition` and `ServicePackageVersion` too, so a
  caller could name any of them;
- and the package must belong to the same cemetery as the plot, compared
  through the plot's own path — `grave_plots.block_id` ->
  `cemetery_blocks.cemetery_id` — against `cemetery_packages.cemetery_id`.
  Without this a draft could freeze another cemetery's package price onto this
  plot, a silent defect visible only when somebody asks why the amount is what
  it is.

The caller-supplied `unit_amount` / `currency` / `price_version_number` are then
cross-checked against the version's own stored anchors; a contradicting anchor
is refused. `description` is derived from the plot and its package; a
caller-supplied value is neither accepted nor required, so no line description
can drift from the catalogue.

## Data flow

```
BookingDraft
  -> PlotReservation::activeForDraft($draft)        at most one hold per draft
  -> grave_plot_id
  -> BookingDraft::cemetery_package_id              the pricing vehicle (D3)
  -> CemeteryPackage::currentPriceVersion()         Priceable, PR #296
  -> one PLOT line
```

`PlotReservation::activeForDraft()` returns `?self`, so a quote carries zero or
one plot line. No quantity question arises: a plot line is always quantity 1.

## Error handling

`UnpricedBookingPlotException`, parallel to the existing
`UnpricedBookingServiceException`, thrown when the draft has no package, the
package has no current price version, the package belongs to another cemetery,
or the hold has disappeared between selection and composition.

The reasoning is the composer's own, already written for services: this feeds a
financial WRITE, so silently dropping the plot would underquote the order by its
largest component. The read path (`BookingDraftQuery::summary()`) may still
degrade to "harga belum tersedia"; the write path may not.

## Testing

Beyond the happy path:

- a draft with no package is refused;
- a package with no current price version is refused;
- a price version belonging to a different package is refused;
- a package belonging to a different cemetery than the plot is refused;
- a `unit_amount` anchor contradicting the stored version is refused;
- `{PLOT, SERVICE}` is accepted, `{PLOT, PACKAGE}` is refused;
- the database CHECK refuses a plot line carrying only one of its two columns;
- the picker offers nothing when the selected package has no firm price, and
  offers every plot when it has one — asserted in both directions so the gate
  cannot pass by always closing;
- the picker refuses to run before a package is selected, with an explaining
  empty state.

Every new gate is mutation-tested: break its target, confirm the failure,
restore, confirm green. A passing test proves nothing until it has been seen to
fail.

## Out of scope

- **Tahap 0 tier 2** — a firm price on `grave_plots` itself, overriding the
  package price. Not built, and not needed yet: when it arrives the line points
  at the plot's OWN `PriceVersion` instead, and `grave_plot_id` is already on
  the row, so no further column is required. Note that this is a price ON the
  plot, which D3 does not forbid; what D3 rules out is pricing through the
  plot's `cemetery_package_id` LINK — a different column and a different
  claim.
- **Operators entering real prices.** An owner action, and the reason D5 empties
  the picker today.
- **Tahap 3** (`GuardPaymentSession`, the anti-oversell condition) and
  everything after it.

## Accompanying ADR

D1 amends a decision recorded only in a doc block. An ADR must record that the
one-family rule is now an enumerated-combination rule, that `{PLOT, SERVICE}` is
declared a single pricing universe, and that PACKAGE remains exclusive —
otherwise the next reader finds two contradicting doc blocks and no ruling.
