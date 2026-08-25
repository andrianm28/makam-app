# Renewal Online Payment — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the real online-payment path for renewals — today `App\Livewire\Public\Renewal\RenewalPayment`'s Blade view unconditionally renders the manual-coordination screen even when `App\Domain\Renewal\Actions\GuardRenewalPaymentOpening` correctly computes `paymentState = 'online'` (all conditions hold, G-PAY-01 open). This closes that live gap: a real "Bayar Sekarang" checkout, a real settlement path, and the "Renewal paid/verified" notification-matrix row this unblocks.

**Architecture:** Extends 3 existing, already-shipped mechanisms by their own established, pre-declared extension points — this is NOT new payment architecture:
1. `App\Platform\Payment\OrderType` gets a new `Renewal` case (the enum's own doc block already documents this exact "declared now, producer lands later" pattern for `Marketplace`/`CareSubscription`).
2. `App\Platform\Payment\Actions\OpenPaymentSession::__invoke()` gets a new `authorizeRenewal()` branch, composing the ALREADY-CORRECT `GuardRenewalPaymentOpening` (verified: it already returns the right `manualCoordinationRequired: false` when G-PAY-01 is open and all 4 conditions hold — nothing in the guard itself needs to change).
3. `App\Platform\Payment\Actions\ApplyPaymentSettlement::settle()` gets a new `Renewal` resolution branch, matched by `renewals.reference` (the same `PPJ-`-prefixed string shape `orders.reference`/`MarketplaceOrder.order_number` already use — confirmed via direct code read of `OpenRenewal.php:85`, NOT a UUID match like `SubscriptionCycle`).

**IMPORTANT — a stale doc-comment correction this plan makes, not a design decision:** `RenewalPaymentOpeningResult`'s and `GuardRenewalPaymentOpening`'s class-level doc blocks currently say the online path is "BLOCKED upstream (PaymentSession throws)... never claimed as PASS," citing `GuardPaymentSession.php:140 — the pass path is unreachable in the type`. Direct code read confirms this citation describes a state that no longer exists: `GuardPaymentSession`'s own doc block says "DENY-ONLY is over; the six conditions are all real (Task 6 + the gateway)" — the online-payment gateway task already gave booking/marketplace/care a real pass path, and `PaymentSession::create()`'s guard hook (`PaymentSession.php:97-99`) checks ONLY the global `ModeResolver::paymentMode()` gate, not any renewal-specific prohibition. These doc comments were accurate before the gateway task shipped and were never revisited for renewal afterward. This plan updates them to match reality — it is a documentation correction following from a code fact, not a new architectural decision this plan is making.

**Tech Stack:** Laravel 13, PostgreSQL 18, Redis 8.2, PHPUnit, real SumoPod sandbox integration (existing `PaymentCheckoutClient`).

**Spec:** None — approved via chat confirmation (25 Aug 2026) after a scoped investigation found the real gap (a live wiring gap using an already-correct guard, not new architecture) — matching this repo's brainstorming-skill "Bounded" path for a well-defined extension of an existing, established pattern. No separate spec doc; this plan document carries the full investigation record.

## Global Constraints

- Every new/modified PHP file needs `declare(strict_types=1);`.
- **Money is integer minor units, never float** (`AGENTS.md` §Domain and financial invariants, `Wave 0 ruling 0c` — cited in `PaymentSession.php`'s own cast comment). All new amount handling must use `Money`/`amountMinor`/`->toMinorInt()`, matching `BookingWizard.php`'s existing pattern exactly.
- **Settlement runs inside the caller's transaction.** `ApplyPaymentSettlement::settle()`'s own doc block: "`ProcessWebhookEvent` calls `settle()`/`applyOutcome()` inside its claim transaction, so the claim, the paid effects, their audit rows, the outbox row and the session-state transition commit or roll back together." The new `settleRenewal()` branch must not open its own transaction — it runs inside the one already open.
- **Idempotency is defense-in-depth, not optional.** The webhook claim layer (`ProcessWebhookEvent`) already deduplicates provider retries upstream, but every existing settle branch (`settleBooking`/`settleMarketplace`/`settleCareSubscription`, transitively `MarkCyclePaid`) ALSO refuses a second settlement attempt at the domain-Action layer. The new renewal settlement Action must do the same — lock the row, refuse (not silently no-op, not throw an unhandled error) if `status !== MENUNGGU_PEMBAYARAN`.
- **Use real domain Actions, never raw Eloquent mutation for a status transition.** Matches `MarkRenewalPaidExternally`'s established shape (`Audit::wrap`, `lockForUpdate()`, explicit status guard) — the new online-settlement Action follows the identical shape, adapted for its own trigger (a settled webhook event, not an admin-recorded reason).
- **`renewals.reference` (the `PPJ-`-prefixed string, set by `OpenRenewal.php:85`) is the match key for settlement resolution — NOT `renewals.id`.** Confirmed via direct code read; this mirrors `orders.reference`/`MarketplaceOrder.order_number`'s existing string-match pattern in `ApplyPaymentSettlement::settle()`, not `SubscriptionCycle`'s UUID-match pattern (Renewal has a real business reference, SubscriptionCycle does not).
- **`OpenPaymentSession::authorizeRenewal()` must compose `GuardRenewalPaymentOpening` and refuse (throw `PaymentSessionOpeningDeniedException` or an equivalent, following the existing exception shape) unless `isAllowed() === true AND isManualCoordinationRequired() === false`.** A `manualCoordinationRequired: true` result must NEVER reach `PaymentSession::create()` — that would attempt to open an online session while the gate itself says manual coordination is required, which is a logically incoherent state this plan must never construct.
- **`AGENTS.md` §Observability: never place restricted data in logs/Pulse/Horizon tags/error trackers.** No renewal reference, grave record detail, or payment amount in any audit `metadata` field beyond what the existing booking/marketplace patterns already allow (closed-list values only — see `OpenPaymentSession.php`'s own `metadata: ['note' => ...]` comment for the exact precedent to follow).
- **This is a financial/payment-domain change.** Per `AGENTS.md` §Infrastructure-agent execution, human review is mandatory before merge — this plan's execution stops at "ready for merge sign-off," same as every other workstream this session; nothing in this plan authorizes self-merging.
- No AWS, no DNS/firewall changes.
- Composer/npm builds do not run on this host — CI only. This plan adds no new packages.
- Real Docker test-execution recipe (established this session): `docker run --network host --user 1000:1000 -e DB_CONNECTION=pgsql ... <pinned-image-digest> php -d memory_limit=512M vendor/bin/phpunit <paths>` against fresh disposable `postgres:18`/`redis:8.2-alpine` containers. Verify the pinned image digest is current (check for a newer "Build and push image" CI run since this plan's base commit `54b7d5b`) before using it.
- `phpunit.xml` already sets `CACHE_STORE=array`/`SESSION_DRIVER=array` as test defaults — never override these to `redis` when invoking `vendor/bin/phpunit` directly (root-caused earlier this session: leaks rate-limiter state across tests in one process).

---

### Task 1: `OrderType::Renewal` + `OpenPaymentSession::authorizeRenewal()` — opening a real renewal payment session

**Files:**
- Modify: `app/Platform/Payment/OrderType.php` (add `Renewal` case)
- Modify: `app/Platform/Payment/Actions/OpenPaymentSession.php` (add the `match` arm + `authorizeRenewal()` private method)
- Modify: `app/Domain/Renewal/Actions/GuardRenewalPaymentOpening.php` (correct the stale class-level doc-comment block only — see plan-level note above; the LOGIC is already correct and does not change)
- Modify: `app/Domain/Renewal/Actions/RenewalPaymentOpeningResult.php` (correct the stale "BLOCKED upstream... never PASS" doc-comment block only — logic unchanged)
- Test: `tests/Feature/Payment/OpenRenewalPaymentSessionTest.php`

**Interfaces:**
- Consumes: `App\Domain\Renewal\Actions\GuardRenewalPaymentOpening` (existing, unchanged logic), `App\Domain\Renewal\Models\Renewal` (existing).
- Produces: `OrderType::Renewal` (consumed by Task 2's settlement branch) — the enum case itself, not any new business logic.

- [ ] **Step 1: Add the `Renewal` case to `OrderType`**

Follow the existing `Marketplace`/`CareSubscription` case doc-comment pattern (a short doc-comment block above the case, matching the file's own established style) — `/** A `Renewal` (the renewal domain), matched by `renewals.reference`. */ case Renewal = 'renewal';`.

- [ ] **Step 2: Add the `authorizeRenewal()` branch to `OpenPaymentSession`**

In `__invoke()`'s `match ($command->orderType)` block, add `OrderType::Renewal => $this->authorizeRenewal($command),`.

Add a new private method, mirroring `authorizeBooking()`'s shape exactly:

```php
private function authorizeRenewal(OpenPaymentSessionCommand $command): string
{
    $renewal = Renewal::query()->find($command->orderRef);

    if (! $renewal instanceof Renewal) {
        throw PaymentSessionOpeningDeniedException::forResult(/* construct an appropriate denial — read PaymentSessionOpeningDeniedException's real constructor/factory methods first; if it requires a GuardResult specifically and cannot represent "renewal not found", use a clearer, already-established exception shape instead — check how authorizeBooking()/resolveOrder() handles an order that doesn't exist, and mirror that exact pattern rather than inventing a new one */);
    }

    $result = app(GuardRenewalPaymentOpening::class)($renewal, new Money($command->amountMinor));

    if (! $result->isAllowed() || $result->isManualCoordinationRequired()) {
        throw PaymentSessionOpeningDeniedException::forResult(/* same note as above — read the real exception's shape and adapt correctly; do not guess at a constructor signature */);
    }

    return $renewal->reference;
}
```

**Read `PaymentSessionOpeningDeniedException`'s real source first** — the pseudocode above is illustrative of the CONTROL FLOW, not the exact exception-construction call, which must match the real class. If `PaymentSessionOpeningDeniedException::forResult()` is typed specifically to `App\Platform\Payment\GuardResult` (the booking-domain guard's result type) and cannot accept a `RenewalPaymentOpeningResult`, you have two honest options: (a) if the exception class is easily generalized without breaking `authorizeBooking()`, do so; (b) if not, throw a distinct, clearly-named exception for the renewal-denial case instead of forcing an incompatible type through — note which option you took and why in your report. Do not silently coerce types or swallow the real denial reason.

**Also read `OrderType::Marketplace`'s existing behavior** — since `PaymentSessionOrderTypeNotSupportedException` exists for a declared-but-unimplemented case — confirm your new `Renewal` branch is a REAL implementation (unlike `Marketplace`'s placeholder), so no such refusal applies to it.

- [ ] **Step 3: Correct the two stale doc-comment blocks**

In `GuardRenewalPaymentOpening.php`'s class-level doc block: remove/correct the line "This guard never calls `PaymentSession::create()` — that path throws by design (Wave 1b ruling 1b-L3-01), and Ruling A explicitly forbids attempting it" — this remains TRUE (this guard still never calls `PaymentSession::create()` — that call now lives in `OpenPaymentSession::authorizeRenewal()`, Task 1 Step 2), but adjust the surrounding "online path is blocked" framing to reflect that it's ONLY blocked while G-PAY-01 is closed, matching the guard's own real, unchanged `$gateClosed` logic.

In `RenewalPaymentOpeningResult.php`'s class-level doc block: correct the "AC8's online half is recorded BLOCKED (upstream deny-only)... never PASS" section — these citations (`PaymentSession.php:84-87`, `GuardResult.php:49,63,79`, `GuardPaymentSession.php:140`) describe pre-gateway-task code state. Read the REAL current state of each cited location (`PaymentSession.php`'s real line numbers for its `creating` hook, confirm `GuardPaymentSession`'s real current pass-path existence) and correct the comment to state plainly: the online path is now real and reachable when `G-PAY-01` is open and `GuardRenewalPaymentOpening` returns `isAllowed() && !isManualCoordinationRequired()` — Task 1 (this task) is what wires the actual `PaymentSession::create()` call.

- [ ] **Step 4: Write tests**

`tests/Feature/Payment/OpenRenewalPaymentSessionTest.php` — follow this repo's existing `tests/Feature/Payment/` conventions (look at an existing `OpenPaymentSession`-adjacent test file for booking, if one exists, for style):
- Opening succeeds for a real, eligible `Renewal` (grave published, quote accepted+unexpired, amount matches, G-PAY-01 open) — asserts a real `PaymentSession` row is created with `merchant_ref`/`amount_minor`/`state = AwaitingPayment`, and the checkout provider was called (use whatever double/fake this repo's existing booking-payment tests use for `PaymentCheckoutClient` — check `tests/Feature/Payment/` for the established fake/mock convention rather than inventing one).
- Opening refuses when `GuardRenewalPaymentOpening` denies (bad grave, stale quote, amount mismatch) — no `PaymentSession` row created.
- Opening refuses when `manualCoordinationRequired: true` (G-PAY-01 closed) — no `PaymentSession` row created, even though the renewal is otherwise eligible. This is the single most important negative test in this task — it proves the plan's own explicit Global Constraint ("manualCoordinationRequired must never reach `PaymentSession::create()`") holds.
- A real `PaymentSession` row's `merchant_ref` is bound to `config('payment.merchant_ref')`/`SiteSetting::KEY_PAYMENT_MERCHANT_REF`, same as booking — one test confirming this isn't accidentally bypassed for the renewal branch.

- [ ] **Step 5: Run tests against real Postgres, run lint/static-analysis/doc gates, commit**

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
bash ci/verify-docs.sh
```

Run the new test file against real Postgres 18 via the established Docker recipe. Confirm `OK (N tests, M assertions)`.

```bash
git add app/Platform/Payment/OrderType.php app/Platform/Payment/Actions/OpenPaymentSession.php app/Domain/Renewal/Actions/GuardRenewalPaymentOpening.php app/Domain/Renewal/Actions/RenewalPaymentOpeningResult.php tests/Feature/Payment/OpenRenewalPaymentSessionTest.php
git commit -m "feat(payment): open a real online payment session for renewals"
```

---

### Task 2: Settlement — `ApplyPaymentSettlement::settleRenewal()` + `MarkRenewalPaidOnline`

**Files:**
- Modify: `app/Platform/Payment/Actions/ApplyPaymentSettlement.php` (add the `Renewal` resolution branch in `settle()` + a new `settleRenewal()` private method)
- Create: `app/Domain/Renewal/Actions/MarkRenewalPaidOnline.php`
- Modify: `app/Platform/Audit/SensitiveActions.php` (or wherever `SensitiveActions::ACTIONS` is the real closed list — grep for it first) if a new action name is needed for the audit trail; check whether `MarkRenewalPaidExternally`'s `'RENEWAL_EXTERNAL_MARKING'` action name convention needs a sibling `'RENEWAL_PAID_ONLINE'` entry, or whether payment-settlement actions elsewhere (`settleBooking`/`settleCareSubscription`) use a DIFFERENT convention (check `PaymentAuditActions` — imported in `ApplyPaymentSettlement.php` — this may be the real, established action-name source for settlement-triggered audits, distinct from `SensitiveActions` which governs admin-panel actions like `MarkRenewalPaidExternally`'s).
- Modify: `docs/contracts/event-catalog.md` (add the new `renewal.paid_online.v1` event — one clean new row, matching this session's own established precedent for the 5 events added in the notification-matrix-medium-rows plan)
- Test: `tests/Feature/Domain/Renewal/MarkRenewalPaidOnlineTest.php`
- Test: extend `tests/Feature/Payment/ProcessWebhookEventTest.php` (or wherever `ApplyPaymentSettlement::settle()`'s existing booking/marketplace/care-subscription branches are tested end-to-end via a real webhook — find the real test file first) with a renewal-settlement case

**Interfaces:**
- Consumes: `OrderType::Renewal` (Task 1), `RenewalStatus::DIBAYAR` (existing), `Renewal` model (existing).
- Produces: `renewal.paid_online.v1` outbox event name (consumed by Task 3's notification-matrix wiring).

- [ ] **Step 1: Read `settleCareSubscription()` and `MarkCyclePaid` in full as your pattern template**

This is the closest existing precedent: a domain-owned "mark paid" Action, called from `ApplyPaymentSettlement`, that performs the real transition and is idempotent against a second call. Read both files completely before writing `MarkRenewalPaidOnline`.

- [ ] **Step 2: Add the `Renewal` resolution branch to `ApplyPaymentSettlement::settle()`**

Insert a new branch checking `renewals.reference` — per the plan's Global Constraints, BEFORE the `SubscriptionCycle` UUID check (order doesn't strictly matter since a `PPJ-`-prefixed reference will never collide with a raw UUID string used for cycle lookup, but match the existing Order → MarketplaceOrder → [new: Renewal] → SubscriptionCycle ordering for readability, consistent with how each new domain was appended at the end of the chain historically):

```php
$renewal = Renewal::query()->where('reference', $invoiceReference)->first();

if ($renewal instanceof Renewal) {
    $this->settleRenewal($renewal, $event, $session);
    $this->transitionToTerminal($session, SessionState::Paid);

    return;
}
```

- [ ] **Step 3: Write `MarkRenewalPaidOnline`**

Mirror `MarkRenewalPaidExternally`'s shape (`Audit::wrap`, `lockForUpdate()`, explicit `status !== MENUNGGU_PEMBAYARAN` refusal — reuse `RenewalAlreadySettledException` if its shape fits an online-triggered refusal, or confirm it's generic enough; do not invent a duplicate exception class if the existing one already fits), adapted for its real trigger:

```php
final readonly class MarkRenewalPaidOnline
{
    public function __invoke(
        Renewal $renewal,
        int $amountMinor,
        string $providerTransactionRef,
        string $actorRef,
    ): Renewal {
        // lockForUpdate, status guard (refuse if not MENUNGGU_PEMBAYARAN),
        // update status => DIBAYAR, settled_at => now(),
        // Outbox::record() with 'renewal.paid_online.v1',
        // Audit::wrap with the correct action name from Step-0's research,
        // AuditSource::Api (matches settleBooking/settleCareSubscription's
        // webhook-triggered source, not AuditSource::Panel which is for
        // admin-initiated actions like MarkRenewalPaidExternally).
    }
}
```

Read `MarkCyclePaid`'s real `Outbox::record()` call signature exactly (subject type/id, event name, payload shape) before writing this — match its conventions precisely rather than guessing at the outbox API.

- [ ] **Step 4: Wire `settleRenewal()` in `ApplyPaymentSettlement`**

```php
private function settleRenewal(Renewal $renewal, ProviderEvent $event, PaymentSession $session): void
{
    app(MarkRenewalPaidOnline::class)(
        $renewal,
        amountMinor: (int) $session->amount_minor,
        providerTransactionRef: (string) $event->provider_transaction_id,
        actorRef: (string) $event->getKey(),
    );
}
```

Adjust parameter names/shapes to match whatever `MarkRenewalPaidOnline`'s real constructed signature ends up being from Step 3 — this is illustrative, not literal.

- [ ] **Step 5: Add the event-catalog entry**

`docs/contracts/event-catalog.md` — one new row for `renewal.paid_online.v1`, matching the format of the 5 rows added in `docs/superpowers/plans/2026-08-24-notification-matrix-medium-rows.md`'s Task work (read one of those real, already-merged rows as your exact format template).

- [ ] **Step 6: Write tests**

`tests/Feature/Domain/Renewal/MarkRenewalPaidOnlineTest.php`:
- A real `MENUNGGU_PEMBAYARAN` renewal transitions to `DIBAYAR`, `settled_at` set.
- A second invocation against an already-`DIBAYAR` renewal refuses (idempotent, no double-transition, no duplicate outbox row) — mirror `MarkRenewalPaidExternally`'s own existing idempotency test for the exact assertion shape.
- The outbox event `renewal.paid_online.v1` is recorded with the correct subject reference.

Extend the real end-to-end webhook-settlement test file (found in Step 1's research) with a renewal case: a real webhook event settling a real, previously-opened renewal payment session correctly resolves to the renewal, transitions it, and does NOT also match/settle any Order/MarketplaceOrder/SubscriptionCycle (a negative-resolution test, matching this session's own established discipline for `from_status` discrimination tests elsewhere).

- [ ] **Step 7: Run tests against real Postgres, run lint/static-analysis/doc gates, commit**

Same verification bar as Task 1 Step 5.

```bash
git add app/Platform/Payment/Actions/ApplyPaymentSettlement.php app/Domain/Renewal/Actions/MarkRenewalPaidOnline.php docs/contracts/event-catalog.md tests/Feature/Domain/Renewal/MarkRenewalPaidOnlineTest.php
# plus whatever webhook-settlement test file Step 6 extended
git commit -m "feat(payment): settle a renewal's online payment session, emit renewal.paid_online.v1"
```

---

### Task 3: Public UI — a real "Bayar Sekarang" checkout on the renewal payment screen

**Files:**
- Modify: `app/Livewire/Public/Renewal/RenewalPayment.php` (add the checkout-opening action method, mirroring `BookingWizard.php`'s pattern)
- Modify: `resources/views/livewire/public/renewal/payment.blade.php` (add the `@elseif ($paymentState === 'online')` branch with a real "Bayar Sekarang" button)
- Test: extend or create a Feature/Livewire test for `RenewalPayment` covering the new online branch
- Test: extend `tests/browser/e2e-renewal.spec.ts` if a reasonable extension point exists (check the file first — this may be better left as a follow-up if the existing suite's structure doesn't cleanly accommodate a real sandbox-checkout redirect test; state your reasoning either way in your report, don't force it)

**Interfaces:**
- Consumes: `OpenPaymentSession`/`OpenPaymentSessionCommand` (Task 1), `OrderType::Renewal` (Task 1).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Read `BookingWizard.php`'s online-payment-opening method in full**

This is your pattern template for the Livewire-side wiring: the `try`/`catch` shape around `OpenPaymentSession`, the specific exception types caught (`PaymentSessionOpeningDeniedException`, `PaymentCheckoutProviderException`, `PaymentCheckoutUnavailableException`, `PaymentSessionOrderAlreadyPaidException` — confirm which of these genuinely apply to the renewal case and which don't; a renewal has no "already paid" concept distinct from its `DIBAYAR` status, so check whether that specific exception needs a renewal-side check or whether the guard's own conditions already cover it), and how the component redirects to `$session->payment_link_url` on success (read the redirect mechanism directly — is it a `redirect()->away(...)`, a Livewire `$this->redirect(...)`, or a Blade-rendered link? Match whatever booking really does).

- [ ] **Step 2: Add the checkout-opening method to `RenewalPayment.php`**

A new public method (e.g. `payOnline()`) callable from the Blade view's new button, following `BookingWizard`'s exact structure: resolve the renewal + its latest quote, call `OpenPaymentSession` with `OrderType::Renewal`, `orderRef: $renewal->reference`, the quote's amount in minor units, the configured merchant ref, and success/cancel return URLs (check what `route('payments.return')`/`route('payments.cancel')` resolve to and whether they're renewal-agnostic already — they likely are, since `ApplyPaymentSettlement::settle()` resolves the target from the webhook event, not the return URL). Handle denial/provider-error exceptions with fixed Indonesian error copy, matching `BookingWizard`'s established tone and the class's own existing `errorMessage`/`paymentState` state shape (do not invent new UI states beyond what's needed).

- [ ] **Step 3: Add the `'online'` branch to the Blade view**

A real, visible "Bayar Sekarang" button/link that triggers the new component method, styled consistently with this codebase's `<x-mk.button>` conventions (check `payment.blade.php`'s existing manual-coordination card for the surrounding visual pattern to match). Remove or adjust the file's own header comment block ("AC8 — NEVER the online path... BLOCKED upstream... never claimed as PASS") since it's now stale for the same reason Task 1 corrects the PHP-side doc comments — update it to describe the real, current behavior.

- [ ] **Step 4: Write/extend tests**

A Feature/Livewire test (`Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])`) proving: when `GuardRenewalPaymentOpening` returns online-eligible, the view renders the "Bayar Sekarang" affordance (not the manual-coordination copy); when it returns `manualCoordinationRequired: true`, the view still renders manual coordination (regression-proof that this task doesn't break the existing, already-tested manual path); calling the new `payOnline()` method on a real eligible renewal results in a real redirect to a `payment_link_url`.

- [ ] **Step 5: Run tests against real Postgres, run lint/design-system/doc gates, commit**

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
bash ci/verify-docs.sh
```

`ci/verify-docs.sh` also scans Blade for hardcoded design values/arbitrary Tailwind values (per `CLAUDE.md`'s scope note) — the new button must use existing `<x-mk.*>` components/tokens, never a raw hex/arbitrary class.

```bash
git add app/Livewire/Public/Renewal/RenewalPayment.php resources/views/livewire/public/renewal/payment.blade.php tests/Feature/Livewire/Public/Renewal/
git commit -m "feat(renewal): real online checkout on the renewal payment screen"
```

---

### Task 4: Notification-matrix wiring — "Renewal paid/verified"

**Files:**
- Modify: `database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php` (add `'Renewal paid/verified' => 'renewal.paid_online.v1'` to `outboxEventName()`'s match — following this session's own established precedent from `2026-08-24-notification-matrix-medium-rows.md`'s Task work, which added 5 sibling rows the exact same way)
- Modify: `docs/contracts/notification-matrix.md` (mark this row's template content, if still `TBD`, or leave as-is if content authoring is genuinely out of scope — check the row's real current state first)
- Modify: `docs/testing/release-gates.md` (§D's "Notification matrix implemented" box — this closes the LAST remaining unproduced row; update the box's running count from "14/16" to reflect the real, current total, and re-evaluate whether the box's full literal claim is now satisfied — "Reminder due" is the one row this plan does NOT touch, per the user's own separate decision to build only its eligibility-query half in a different, smaller piece of work; state this precisely)
- Test: extend `tests/Feature/Notification/NotificationTemplatePersistenceTest.php` (the file this session already fixed once — see its `$mapped`/`matrixOutboxEventName()` structure) with the new row
- Test: a real Feature test proving the notification actually dispatches when `MarkRenewalPaidOnline` runs (following `tests/Feature/Domain/Renewal/OpenRenewalTest.php`'s or `tests/Feature/Domain/VendorFulfillment/EvidenceUploadTest.php`'s established pattern for "the real domain Action, run end-to-end, results in a real notification_template match and a real dispatched notification" — read one of those as your template)

**Interfaces:**
- Consumes: `renewal.paid_online.v1` (Task 2's real event, not a prediction — read Task 2's actual committed code before writing this task).
- Produces: nothing consumed elsewhere.

- [ ] **Step 1: Confirm Task 2's real committed event name and payload shape**

Read Task 2's actual committed `MarkRenewalPaidOnline.php` and its `Outbox::record()` call directly — do not assume the event name/shape this plan predicted is exactly what got built.

- [ ] **Step 2: Wire the seed migration**

Add the real event name to `outboxEventName()`'s match, following the exact pattern the 5 sibling rows already established (read one, e.g. `'Renewal submitted' => 'renewal.submitted.v1'`, as your literal template).

- [ ] **Step 3: Update docs**

Update `docs/testing/release-gates.md`'s §D box with real, precise evidence — cite the real test names from Task 2/Task 4, and state the real remaining gap honestly ("Reminder due" only, if that row is still unbuilt at the time this task runs — check its real current state, don't assume).

- [ ] **Step 4: Write tests**

Both test files described above, run for real.

- [ ] **Step 5: Run tests against real Postgres, run doc gates, commit**

```bash
bash ci/verify-docs.sh
git add database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php docs/contracts/notification-matrix.md docs/testing/release-gates.md tests/Feature/Notification/NotificationTemplatePersistenceTest.php
# plus the new end-to-end notification test file
git commit -m "feat(notifications): wire renewal.paid_online.v1 into the notification matrix"
```

---

## Verification

Every task: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `bash ci/verify-docs.sh`, and real test execution against the pinned container image + Postgres 18/Redis 8.2 (no unexecuted PASS claims). Task 2 and Task 4 particularly need real end-to-end webhook-driven tests, not just unit-level Action tests, since this is the exact class of bug (an isolated unit passing while the real dispatch chain doesn't) this repo's `docs/testing/release-gates.md` has caught before.

## Execution

This plan will be executed via superpowers:subagent-driven-development immediately after being written and saved, matching this session's established pattern. Given this touches the payment domain, human review is mandatory before merge (`AGENTS.md` §Infrastructure-agent execution) — this plan's execution stops at "branch ready, PR open, CI green," never at "merged," matching every other workstream this session.
