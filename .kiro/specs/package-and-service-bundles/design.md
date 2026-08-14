# Design — Package and Service Bundles

## Authority and module boundary

`requirements.md`'s K26 / benchmark B03. No other design.md is cited as authority here — this
spec is itself the authority `at-need-booking` AC6 and `booking-and-order-orchestration` defer to
for package/service/price content. Owned by module **ServiceCatalog**
(`docs/architecture/overview.md` §5: "Service definitions, package bundles, price sources"); all
tables below live under `app/Domain/ServiceCatalog/`.

## Components

`ServicePackage` → `ServicePackageVersion` (immutable once published, AC2) → `ServicePackageItem`
(included/optional/excluded, AC1), each item referencing one `ServiceDefinition` (the catalogue's
individual service — items compose these, never redefine them). `SubstitutionPolicy` and
`EvidenceRequirement` attach per item, not per package, so a substitution rule or completion-
evidence requirement can differ item to item within one package. `PriceVersion` is a sibling
snapshot, not a column on the package version — AC3's "package, service, and price versions" are
three separate references because they revise on independent schedules.

## Data

```text
service_packages
service_package_versions (immutable once published — AC2)
service_package_items (included | optional | excluded; fulfillment_owner: platform|cemetery|vendor — AC7)
service_definitions (the individual catalogue service an item references)
price_versions
substitution_policies (per item)
evidence_requirements (per item)
```

Real tables and Actions, done 26 Jul 2026 (Sprint 4 S4-T1) — see `tasks.md`. AC2's immutability is
enforced at the model layer (`PublishedServicePackageVersionIsImmutableException`), not only by
convention.

## Consumption boundary — this spec defines, it does not book

This spec owns definition, versioning, and pricing. It does **not** turn a selected package into
order/quote line items — that's quote *expansion*, owned by `booking-and-order-orchestration` /
the booking wizard (S4-T4/S4-T5 onward). That boundary is why AC3/AC4 are only half-built here:
the price-snapshot mechanism a quote would reference exists, but nothing in this spec's scope
constructs a quote. Once a quote accepts a version, AC2's immutability is what protects it — a
later revision creates a new version, never rewrites the one already referenced.

No dedicated screen-inventory ID; UI surfaces inside consuming specs' screens — **PUB-013/PUB-014**
(booking Steps 4–5), **ADM-020** (admin catalogue) — governed by `docs/design/design-system.md`
per `tasks.md`'s own primitives table.

## Error handling

- **Substitution mid-fulfillment (AC5):** configured `SubstitutionPolicy` applies automatically;
  if it requires customer approval, the item goes `pending` (§6.7) — never silently substituted.
- **Completion evidence (AC6):** completion Actions check every required item's
  `EvidenceRequirement` first; a missing item blocks completion, never passes silently.
- **New version drafted while an old one is quoted:** the old version stays immutable and
  readable — never mutated out from under an already-issued quote.
- **Discontinued item still attached to an active bundle:** unresolved by the current schema —
  flagged, not designed around, since AC1–AC8 don't specify the behaviour. Correction 09 Aug 2026
  (ServiceCatalog retrofit): the *deletion* half is closed, not a hole —
  `service_package_items.service_definition_id` (`2026_07_26_180300:87`) and
  `substitution_policies.substitute_service_definition_id` (`2026_07_26_180500:59`) are both
  `restrictOnDelete()`; only the *deactivation* (`is_active = false`) half is open, dispositioned in
  `docs/planning/retrofit-backlog.md` §2 (closed at the Booking seam by an invariant test; needs a
  new AC for the published-version case).

## Not covered, deliberately

- No event for publish/revise/discontinue is catalogued in `docs/contracts/event-catalog.md`
  (checked — none exists); a consumer needing one requires adding it there first, not inventing an
  ad hoc name (N-12's lesson).
- Quote expansion (AC3/AC4's "quote" half) — `booking-and-order-orchestration`'s scope.
- Admin Filament editor UI — backend/Actions only; `tasks.md` already tracks this as not done.
- No `G-*` gate owns this spec directly — `G-PLOT-01`/`G-DIRECT-01` name "package/class
  confirmation" as the safe default before either opens, so this path is the current ungated mode.

## Testing strategy

`ServicePackageLifecycleTest`, `ServicePackageVersionImmutabilityTest` (existing) prove
AC1/AC2/AC8. A substitution/evidence test covers AC5/AC6's Action-layer enforcement. AC3/AC4's
**quote-issuance** half is shipped and tested since 13 Aug 2026 (lane L6):
`App\Domain\Quotation\Actions\IssueQuote` writes `unit_amount_minor` + `price_version_number`
into immutable `quote_lines` (`tests/Feature/Quotation/IssueQuoteTest.php`,
`QuoteImmutabilityTest.php`) — the price-snapshot behaviour AC3 needs. What remains unbuilt is
quote **expansion** (turning `service_package_items` into quote lines); until it lands, AC3/AC4's
package-driven end-to-end is not yet testable.
