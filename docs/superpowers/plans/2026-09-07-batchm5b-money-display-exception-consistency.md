# Batch M5b — money display, exception-handling, merchant-ref, and timezone consistency

Phase 3 audit remediation, batch M5b. Fixes ARCH-06, ARCH-07, ARCH-08, ARCH-14
from `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`.

Branch: `fix/batchm5b-money-display-exception-consistency`.

## ARCH-06 — route money rendering through `Money::format()`

**Root cause found by grep across `app/Filament` and `resources/views`:** ~14
call sites re-implement `'Rp '.number_format(...)` instead of using
`App\Platform\FinancialLedger\Money`, and two of them (`CarePlansTable`,
`CarePlanInfolist`, `subscription-status-page.blade.php`) display a
`*_minor` column's raw integer as if it were whole rupiah — no `/100`, no
`Money::format()` — so a plan priced at "150000 minor units" (= Rp 1.500)
renders as "Rp 150.000", a 100x display bug. `SubscriptionInfolist` also
references a `carePlan.price` attribute that does not exist on the
`CarePlan` model (only `price_minor` does), so that field always renders
its placeholder.

**Reference implementations already in the codebase** (used as the pattern
to match): `PaymentVerificationsTable`/`ReconciliationsTable` already do
`(new Money($state))->format()`; `Checkout.php` already does
`Money::fromDecimal((string) $validated['manualPaymentAmount'])` for
decimal user input.

**Fix — new shared seam (path of least resistance):**
- `TextColumn::macro('money', ...)` and `TextEntry::macro('money', ...)`,
  registered in `AppServiceProvider::boot()`, wrapping
  `(new Money((int) $state))->format()` with a null-safe placeholder. Future
  Filament money columns become `TextColumn::make('amount_minor')->money()`
  instead of hand-rolled `number_format`.
- `resources/views/components/mk/money.blade.php` — `<x-mk.money :minor="..." />`
  for Blade views outside Filament.

**Fix sites** (all now go through the seam above):
1. `CarePlansTable.php` — `price_minor` column → `->money()`.
2. `CarePlanInfolist.php` — same.
3. `CarePlanForm.php` — the `price_minor` `TextInput` accepted a raw
   integer typed by the admin as "minor units" (its own helper text says
   so), which is inconsistent with every other money entry point in the
   app and is what produced the CarePlansTable/CarePlanInfolist bug in the
   first place (the seeded/entered values were never really minor units).
   Confirmed via `database/migrations/2026_08_17_110000_create_care_plans_table.php`
   that `price_minor` really is `bigInteger` minor units. Reworked to accept
   a decimal-rupiah amount from the admin (matching `Checkout.php`'s
   `Money::fromDecimal()` pattern) via `afterStateHydrated()` (minor → decimal
   for display) and `dehydrateStateUsing()` (decimal → minor via
   `Money::fromDecimal()`) on the same field.
4. `subscription-status-page.blade.php` — `<x-mk.money :minor="$subscription->price_minor" />`.
5. `SubscriptionInfolist.php` — `carePlan.price` → `carePlan.price_minor`, `->money()`.
6. `PreNeedCaseInfolist.php` — two spots (`amount_minor` per-installment, and
   `quoteSummary()`'s `$totalRupiah` local var) → `Money::format()`.
7. `MarketplaceOrderInfolist.php` — private `moneyString()` helper body → `Money::format()`.
8. `MarketplaceOrdersTable.php` — `total_minor` column → `->money()`.
9. `BookingOrderInfolist.php` — private `moneyString()` helper body → `Money::format()`.
10. `RenewalOrderInfolist.php` — `quoteFor()` → `Money::format()`.
11. `RenewalOrdersTable.php` — `amountFor()` → `Money::format()`.
12. `ProductsTable.php` — `base_price_idr` is confirmed (migration +
    `Product` model) to be **whole IDR, not minor units** — a genuinely
    different column family from the `*_minor` ones. Routed through
    `Money::fromDecimal()` (whole-rupiah string → minor) then `->format()`
    so it still goes through the one seam, rather than leaving it as a
    second hand-rolled formatter.
13. `ListingsRelationManager.php` (Vendor listings) — `price_minor` table
    column → `->money()`; found via the same grep, not in the original
    "known bad spots" list but the identical bug shape (`VendorListing`
    model already has a `toMoney(): Money` helper that nothing was calling).
    Its `price_minor` form `TextInput` had the same raw-minor-unit input
    problem as `CarePlanForm` — same `afterStateHydrated`/`dehydrateStateUsing`
    fix applied.

**Explicitly out of scope:** `CemeteriesTable::price_min` /
`cemetery_packages.price_min`/`price_max` are `decimal(14,2)` **whole-rupiah**
columns (migration-confirmed), already rendered correctly with
`->numeric(decimalPlaces: 0)->prefix('Rp ')` — no unit-mismatch bug exists
there. Left alone to keep this batch's diff focused on genuine bugs/duplication.
`order-status-overview.blade.php`'s `number_format($totalTransactions, ...)`
formats a transaction **count**, not money — not a money-rendering bug.

No literal `carePlan.price` reference was found outside `SubscriptionInfolist.php`
(item 5 above) — the task's other examples (`CarePlansTable`,
`subscription-status-page.blade.php`) turned out to already reference
`price_minor` correctly; their bug was the missing `Money::format()`
conversion, not a wrong column name.

## ARCH-07 — swallowed `\Throwable`, no `report()`, raw message leak

**Scope for this batch:** `grep -rn 'catch (\\Throwable\|catch (Throwable'
app/Filament/` finds 40 sites. Per the task's explicit fallback, this batch
covers exactly the five named directories — `BookingOrders`,
`MarketplaceOrders`, `PreNeedCases`, `CarePlans`, `Subscriptions` — which
comes to 14 sites (listed below). The remaining 26 (`ServiceComplaints`,
`Certificates`, `ServicePackages`, `Reconciliations`, `Agreements`,
`CemeteryResource`, `WorkOrders`, `FeatureGateAdmin`, `RenewalOrders`,
`Filament/Shared/PlotFloorMap`, `Filament/Vendor`) are **not touched in this
PR** and are a follow-up batch's work — same helper, same allowlist
mechanism, just more call sites.

**Fix — `App\Filament\Shared\PanelFailure::notify(\Throwable $e, string $title)`:**
always calls `report($e)` first (closing the "no error tracking" half of the
finding), then sends a Filament danger notification whose body is:
- `$e->getMessage()` **only** if `$e` is an instance of a class on a small,
  evidence-based allowlist;
- otherwise a fixed generic Indonesian message
  ("Terjadi kesalahan pada sistem. Tim teknis telah diberi tahu.") — closing
  the "raw internal message leak" half.

**Allowlist, built from real doc-comment evidence (not guessed):**
- `App\Domain\PreNeed\Exceptions\IllegalPreNeedCaseTransitionException` —
  its own class doc: "Each factory says exactly what precondition was not
  met, so the admin surface can surface it without parsing messages."
- `App\Domain\PlotReservation\Exceptions\PlotReservationTransitionException` —
  its own class doc: "`forTransition($from, $to)` names the refused
  transition so the **operator-facing handler** ... can show which hop was
  illegal."

Other exception classes reachable from these 14 catch sites (e.g.
`MarketplacePaymentAmountMismatchException`, `PlotNotAvailableException`,
`OrderActionNotAuthorisedException`) do **not** carry an explicit
admin/operator-display doc comment and are deliberately left off the
allowlist — they get the generic message. This can be revisited later with
a human decision per class, per `AGENTS.md`'s human-review bar for anything
security/financial-adjacent; guessing intent here would be exactly the kind
of unverified claim the finding is trying to eliminate.

**Sites fixed (14):**
- `BookingOrders/Actions/ReservePlotAction.php`
- `BookingOrders/Actions/IssueQuoteFromReservedPlotAction.php`
- `BookingOrders/Actions/TransitionOrderAction.php`
- `BookingOrders/Actions/PlotReservationLifecycleActions.php`
- `MarketplaceOrders/Actions/MarkMarketplaceOrderPaidAction.php` (×3 — two
  notify-pattern catches, plus a third `catch (\Throwable)` inside
  `authorized()` that swallows everything silently with no `report()` and
  no notification at all; fixed with a bare `report($exception)` since
  there is no notification to build there)
- `PreNeedCases/Actions/PreNeedCaseActions.php` (×3)
- `CarePlans/Pages/CreateCarePlan.php` (rethrows after notifying — preserved,
  `PanelFailure::notify()` does not alter control flow)
- `Subscriptions/Actions/PauseSubscriptionAction.php`
- `Subscriptions/Actions/CreateSubscriptionAction.php`
- `Subscriptions/Actions/CancelSubscriptionAction.php`

`MarkMarketplaceOrderPaidAction.php`'s first catch previously used
`$exception->getMessage()` **as the notification title** with no body —
changed to a fixed title ("Otorisasi gagal") with the (allowlist-gated)
message as body, matching every other site's shape.

## ARCH-08 — Pre-Need installment link ignores the merchant-ref setting

Confirmed: every other `OpenPaymentSessionCommand` construction (`GuardMarketplacePaymentOpening`,
`GuardPaymentSession`, `RenewalPayment.php`, `BookingWizard.php`,
`Checkout.php`, and `OpenPaymentSession::assertMerchantBound()` itself) reads
`app(SettingsService::class)->setting(SiteSetting::KEY_PAYMENT_MERCHANT_REF, (string) config('payment.merchant_ref', ''))`.
`PreNeedCaseActions.php` (~line 589) was the one caller passing
`(string) config('payment.merchant_ref', '')` directly, skipping the
operator-managed `site_settings` override. Since `OpenPaymentSession::assertMerchantBound()`
throws `PaymentSessionMerchantMismatchException` whenever the claimed
`merchantRef` doesn't equal the **settings-resolved** bound merchant, this
was a live bug, not just style: once an operator sets a `payment_merchant_ref`
row that differs from the raw env/config default, every Pre-Need installment
payment link would fail while every other payment path kept working.

**Fix applied:** replaced the `config()`-only read with the same
`SettingsService`/`SiteSetting::KEY_PAYMENT_MERCHANT_REF` call the other six
call sites use.

**Bigger refactor considered and deliberately deferred:** dropping
`merchantRef` from `OpenPaymentSessionCommand` and having `OpenPaymentSession`
resolve it internally (removing the class of "one caller forgot" bugs
entirely) is attractive, but touches the constructor of a value object used
in production code paths at all six call sites plus `assertMerchantBound()`'s
comparison logic, each with its own test suite. Verifying "all six callers
still pass their own tests" for a refactor like that is a meaningfully larger
and riskier change than the actual reported bug, and this batch's fix already
eliminates the concrete defect. Left as a documented follow-up recommendation
rather than bundled into this PR.

## ARCH-14 — timezone: UTC storage vs Jakarta-pinned ledger vs raw display

**Investigation finding:** `LedgerPeriod`'s own doc comment explains its
`Asia/Jakarta` constant is *not* behaviourally load-bearing today —
`occurred_at` is a naive (`timestamp without time zone`) column, Postgres
binds a `DateTimeInterface` literally without conversion, and
`boundsFor()` only ever asks for exact-midnight literals, which are
byte-identical regardless of which zone label built the Carbon object. So
the ledger's internal period-window math is not actually broken by the
UTC/Jakarta labeling mismatch — it's a naming/documentation inconsistency
the class's own doc block already flags as something "a human must confirm"
the moment `occurred_at` ever stops being naive.

**The real, live bug is display-only:** `grep -rn '\->timezone(' app/Filament`
returns nothing — no Filament column or entry anywhere sets a display
timezone, so every `->dateTime()`/`->date()` in every one of the three panels
(Admin/Operator/Vendor) renders the raw UTC-labeled Carbon value. Same for
public Blade views — e.g. `invoice-receipt-page.blade.php`'s
`$invoice->issued_at->translatedFormat('j F Y, H:i')` prints UTC wall-clock
time on a public receipt for an Indonesian customer, 7 hours off from Jakarta
local time.

**Decision: option (b), a single display seam — UTC storage kept as-is.**
Changing `config('app.timezone')` to `Asia/Jakarta` was rejected: it would
change what `now()`/`Carbon::now()` produce **application-wide**, including
every naive-column write throughout the app (bookings, orders, audit log
timestamps, everything) — a blast radius far larger than what ARCH-14
actually reported, and exactly the kind of change `AGENTS.md`'s human-review
bar exists for. **This PR does not touch `config/app.php`'s `timezone` key.**

Seam introduced instead:
- `config/app.php` gets a new `'display_timezone' => 'Asia/Jakarta'` key
  (documented as the one place every customer/operator-facing render must
  read from — deliberately the same real-world zone `LedgerPeriod::TIMEZONE`
  names, for a different concern: display conversion of a UTC instant,
  not naive-literal period-window construction).
- `Filament\Support\Facades\FilamentTimezone::set(config('app.display_timezone'))`
  called from `AppServiceProvider::boot()` — Filament's own built-in
  site-wide timezone-conversion seam (v3.2+, carried into v5): every
  Filament `DateTimePicker`/`TextColumn::dateTime()`/`TextEntry::dateTime()`
  across all three panels that doesn't set its own explicit `->timezone()`
  now converts to Jakarta for display automatically, without touching each
  of the ~dozens of individual column definitions.
- `resources/views/components/mk/local-time.blade.php` — `<x-mk.local-time :at="$carbon" format="j F Y, H:i" />`
  for public Blade views (Filament's facade doesn't cover these). Applied to
  `invoice-receipt-page.blade.php` as the proof case named in the finding.

**Explicitly not done in this batch:** migrating every other public Blade
view's raw `->translatedFormat()`/`->format()` call (there are ~16 files
under `resources/views/livewire/public` doing ad hoc date formatting) onto
`<x-mk.local-time>`. That is a mechanical, low-risk, but wide sweep better
done as its own follow-up batch once this seam exists and is proven —
bundling ~16 file edits into an already four-finding PR would make review
harder without changing the risk profile. Documented here so a follow-up
batch has a starting grep (`grep -rln "translatedFormat\|Carbon::parse" resources/views/livewire/public`).

**Test added:** `InvoiceReceiptPageTest` — a new test that creates an invoice
with a known UTC instant and asserts the rendered page shows the Jakarta-local
(+7h) formatted time, not the raw UTC one.

## Testing

Host PHP is 8.3; all verification below runs inside Docker (CI-parity
image or fresh build), against real `postgres:18` + `redis:8.2-alpine`
containers prefixed `m5b-`:
- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --no-progress` (memory limit bumped if needed)
- `bash ci/verify-docs.sh`
- Full test suite, focused first on: `CarePlansTable`/`CarePlanForm`
  (new/changed), `SubscriptionInfolist`, `MarkMarketplaceOrderPaidAction`,
  `PreNeedCaseActions` (installment link), `InvoiceReceiptPageTest` (new
  timezone assertion), then the whole suite for regressions.
