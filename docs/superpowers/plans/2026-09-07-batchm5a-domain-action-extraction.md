# Batch M5a — Domain Action extraction (ARCH-01, ARCH-04, ARCH-05, ARCH-13)

Phase 3 of `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`. Four
independent findings, each fixed in its own commit-worthy unit but shipped as
one PR per the batch's scope.

## ARCH-01 — extract `BookingWizard::openOnlinePayment()`'s submit→quote→pay chain

**Investigation finding that changes the literal instruction:** the finding
text says to call `IssueOrderQuote` "so the status event is correctly
recorded." Verified against `OrderTransition::ALLOWED`: `MASUK` (the status
every order starts at, per `SubmitBookingDraft`) may only transition to
`DIVERIFIKASI`/`DITOLAK`/`DIBATALKAN` — never directly to
`PENAWARAN_TERKIRIM`. `IssueOrderQuote::__invoke()` unconditionally calls
`RecordOrderStatusChange::__invoke($order, PENAWARAN_TERKIRIM, ...)` AFTER
already writing the quote via `IssueQuote` — so on the wizard's normal path
(a freshly submitted order, still at `MASUK`), calling `IssueOrderQuote`
verbatim would throw `IllegalOrderTransitionException` on every first online
payment click, after the quote row is already committed. This is confirmed
by `GuardPaymentSession::CONFIRMED_STATUSES`, which starts at
`PENAWARAN_TERKIRIM` — the six-condition guard's condition 2 requires an
order status the wizard's own order can never legally reach through
`IssueOrderQuote` while still at `MASUK`. `BookingWizardOnlinePaymentTest`'s
`operatorCompletes()` helper confirms the transition sequence
`DIVERIFIKASI -> MENUNGGU_KETERSEDIAAN -> PENAWARAN_TERKIRIM` is a
deliberately operator-only act, matching the wizard's own doc block
("confirmation ... are operator-side acts").

There IS a real gap `IssueOrderQuote` fixes, though: when an order is
ALREADY at `DIVERIFIKASI` or `MENUNGGU_KETERSEDIAAN` (an operator verified it
off-screen before a quote existed) and the customer's online-payment click
finds no current quote, using plain `IssueQuote` leaves the order stuck at
that earlier status with an issued quote the guard's condition 2 can never
see as confirmed. That is the actual "status event not correctly recorded"
bug.

**Fix:** `OpenBookingOnlinePayment` composes the quote only when
`Quote::currentFor($order)` is null, and chooses the action by whether the
`MASUK -> PENAWARAN_TERKIRIM`-equivalent edge is legal from the order's
CURRENT status (`OrderTransition::isAllowed($order->status(),
PENAWARAN_TERKIRIM)`):

- legal (`DIVERIFIKASI`/`MENUNGGU_KETERSEDIAAN`) → `IssueOrderQuote` (records
  the status event correctly — the fix this finding actually wants);
- illegal (`MASUK`, the common fresh-order case) → `IssueQuote` directly,
  preserving today's fail-closed behaviour, which the existing test suite
  locks in.

**Quote validity constant:** `TransitionOrderAction` and
`IssueQuoteFromReservedPlotAction` (the two real admin quote-issuing call
sites) both hardcode `CarbonImmutable::now()->addDays(30)`. The wizard
hardcoded `addDays(7)` — a second, DIFFERENT, undocumented value. Added
`IssueOrderQuote::DEFAULT_VALIDITY_DAYS = 30` as the single named constant;
both admin call sites and the new `OpenBookingOnlinePayment` now reference
it, so there is one source of truth at 30 days (the admin flow's existing,
consistently-used value) instead of two conflicting hardcodes.

## ARCH-04 — `GenerateCycle`'s unreachable recovery path

Move the try/catch to wrap the WHOLE `Audit::wrap(...)` call (matching
`SubmitBookingDraft::__invoke()`'s shape exactly: `DB::transaction` inside
the try, recovery `SELECT` in the catch, run in a fresh implicit
transaction/connection state since PostgreSQL already rolled back the
aborted one). Test: pre-insert a conflicting `SubscriptionCycle` row directly
(bypassing the idempotent existing-row check with a second call that races
past the initial `first()` read), and assert `GenerateCycle` returns the
incumbent row instead of raising 25P02. This requires real PostgreSQL — the
whole reason this bug is invisible on SQLite's single-connection semantics.

## ARCH-05 — `CarePlansResource` has no `form()`

Add `public static function form(Schema $schema): Schema { return
CarePlanForm::configure($schema); }`. Add `CreateAction::make()` to
`ListCarePlans::getHeaderActions()`. Feature test drives the real Filament
create flow end to end.

## ARCH-13 — `Cart::reconfirmPricing()` mutates frozen columns directly

New `App\Domain\Marketplace\Actions\ReconfirmCartPricing`, matching
`UpdateCartItem`/`RemoveCartItem`'s `->handle()` shape, wrapped in
`Audit::wrap()` (no redundant explicit transaction). New audit action
constant `MarketplaceAuditActions::CART_PRICING_RECONFIRMED`. `Cart::
reconfirmPricing()` calls the Action with `actorRef`/`actorRole` resolved
the same way `Checkout::submitManualProof()` resolves them
(`auth()->id()` / `auth()->check() ? 'customer' : 'guest'`), `AuditSource::
Api`. Test: bump a listing's price mid-session, call `reconfirmPricing()`,
assert the cart line's frozen columns now match the listing AND an
`audit_events` row was written for the mutation.

## Verification

- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --no-progress`
- `bash ci/verify-docs.sh`
- Full test suite against real PostgreSQL 18 in Docker (`m5a-` prefixed
  containers), not SQLite — ARCH-04 specifically cannot be verified any
  other way.
