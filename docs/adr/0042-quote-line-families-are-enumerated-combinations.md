# ADR-0042 — Quote line families are an enumerated combination, not a single family

**Status:** Accepted, 17 September 2026
**Supersedes:** the "P0 ruling (14 Aug 2026)" recorded only in
`app/Domain/Quotation/Actions/IssueQuote.php`'s doc block

## Context

`IssueQuote` accepted exactly one line family per quote — PACKAGE
(`service_package_version_id`) or SERVICE (`service_definition_id`) —
enforced by pinning the family from the first line and rejecting any later
line that differed. The stated reason: "a quote snapshots ONE kind of
pricing universe."

That rule was never an ADR. It lived in one doc block, which is why the
pay-first plan could be approved without anyone noticing it contradicted it.

Tahap 1 of `docs/superpowers/plans/2026-09-13-bayar-penuh-di-muka-online-saja.md`
requires one quote to carry a plot AND the funeral services bought with it:
"Total penawaran = petak + layanan."

## Decision

The set-level rule becomes an enumerated list of legal family combinations:

| Combination | Meaning |
| --- | --- |
| `{PACKAGE}` | marketplace / operator quotes — unchanged |
| `{SERVICE}` | services only |
| `{PLOT}` | a plot with no additional services |
| `{PLOT, SERVICE}` | the pay-first path |

Anything else is refused, naming the combination found.

## Why this is a sharpening rather than a loosening

The old rule's own reason is that a quote snapshots one pricing universe.
This does not abandon that reason — it states explicitly that a plot and the
funeral services bought with it ARE one universe: one order, one currency,
one customer, one moment. PACKAGE remains exclusive, so the marketplace
invariant it protected is untouched.

What changes is that the legal combinations become a list somebody can read
instead of a rule somebody has to remember.

## Rejected alternatives

- **Drop the family concept entirely.** Fewest lines of code, but then
  nothing stops a marketplace PACKAGE line being mixed into a booking quote.
  Removing a guard because it blocks one case is how guards die.
- **Model the plot as a synthetic `ServiceDefinition`.** No schema change and
  no ruling to amend, but `IssueQuote`'s doc block already refuses synthesized
  versions, and it would move plot pricing into the service catalogue —
  against the two-tier package/plot pricing design, and filling the service
  catalogue with entries that are not services.

## Consequences

- `quote_lines` gains `grave_plot_id` and `cemetery_package_id`, and a
  three-armed CHECK constraint enforces that exactly one family's key group
  is populated per row.
- The combination rule itself is per-SET and therefore has no database
  backstop. That is stated rather than assumed away.
- Design detail lives in
  `docs/superpowers/specs/2026-09-17-plot-quote-lines-design.md`; this ADR
  records only the ruling.
