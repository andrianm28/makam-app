# Order Orchestration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the order aggregate, the forward-only commercial state machine, immutable versioned quotes, and the exactly-once paid-effects path that `booking-and-order-orchestration` owns — supplying the upstream domain records the payment adapter's deny-only guard has been waiting on since Wave 1b.

**Architecture:** Two new domain modules. `app/Domain/OrderWorkflow/` owns the order aggregate, the transition graph, and `ApplyPaidEffects` (the sole writer of `DIBAYAR`). `app/Domain/Quotation/` owns versioned quotes and their lines. Both compose with already-merged platform seams — `Payment` for the guard and manual verification, `FinancialLedger` for `Money` and `Journal`, `IdentityAccess` for authorization, `DocumentVault` for order documents, `Audit` for sensitive-action records — and reinvent none of them. Commercial state stays structurally separate from case/work/certificate state.

**Tech Stack:** PHP 8.5, Laravel 13, PostgreSQL 18 (SQLite in the hermetic test suite), PHPUnit.

## Global Constraints

- **Money is integer minor units.** Never float. `new Money(Money::fromDecimal($decimalString))` is the only conversion idiom; `price_versions.amount` is `decimal:2` and hydrates as a PHP **string**.
- **Forward-only commercial transitions.** `docs/domain/order-lifecycle.md` §3: "No transition backward." Correction creates a new reasoned event or a compensating financial action, never a reversed edge.
- **Commercial state is separate from case/work/certificate state.** Direct authority: `docs/domain/funeral-case-model.md:35` and `docs/domain/domain-model.md:165`. Do not merge them into one status.
- **One order event name exists:** `order.status_changed.v1` (`docs/contracts/event-catalog.md:7-23`). There is no `order.created.v1` and no `order.paid.v1`. Never invent an event name — finding N-12.
- **`G-PAY-01` (online payment) is closed**; documented fallback is manual coordination. Step 8 is never removed.
- **`FIN-DEC-01`–`07` are `TBD`, `FIN-DEC-08` is `GATED`.** `docs/domain/financial-model.md` §4: "A missing decision closes the relevant payment/settlement gate; it does not authorize a guessed implementation."
- **Never hardcode a design value.** `resources/css/tokens.css` is the single source of truth; no arbitrary Tailwind values. Applies to any Blade/Livewire touched here.
- **Never report `PASS` for an unexecuted check.** Use `BLOCKED` or `NOT TESTED` explicitly (`AGENTS.md` §Infrastructure-agent execution).
- **Test environment:** a fresh worktree needs `cp -al /home/ubuntu/makam-app/vendor <worktree>/vendor` — **never `ln -s`**, which silently runs the main checkout's `app/`. Never run `composer install` or `npm run build` on this host; CI owns builds. Baseline at `d9fea9f` is 1812 tests / 0 failures / 2 host-only errors / 59 skipped.

## Status of design decisions

Scope approved 12 Aug 2026: guard conditions 2-5 built for real, condition 6 left as an honest `UnavailableUpstream` citing `FIN-DEC-01`.

Five design decisions were settled by a grill-spec pass on this spec's 23-line `design.md` and **ratified 12 Aug 2026**. They are recorded here because the canonical documents do not carry them, and an implementer who does not know them will get the affected task wrong:

1. **`MENUNGGU_VERIFIKASI_PEMBAYARAN` is absent from `docs/domain/order-lifecycle.md` entirely.** It sits on the manual path only, between `MENUNGGU_PEMBAYARAN` and `DIBAYAR`. A *rejected* verification is not an order transition at all — it is a `PaymentVerificationStatus` change, so the order stays put and the customer resubmits. This is how "no transition backward" and the customer's need to retry are satisfied together.
2. **Terminal-branch source states** are restricted per Task 1's graph, and **nothing terminal is reachable after `DIBAYAR`**. Cancellation from the verification-pending state is admin-only, because unverified money may already have moved.
3. **Exactly-once needs two levels**, not one — see Task 7's rationale. The Journal business key alone is insufficient.
4. **Quote acceptance is a property of the quote version, not the order**, so re-quoting never requires a backward order transition. Expiry is evaluated lazily and authoritatively at guard time and never trusts a scheduled job to have run.
5. **`decimal` → `Money` conversion happens exactly once, at quote issuance.** Quote lines reference frozen published `ServicePackageVersion`s, and a strictly-positive-total guard discharges L3's previously-unowned forward constraint that non-positive amounts must be rejected on any real pass path.

**Module ownership** (resolved against `app/Domain/README.md:13-15`, which makes the owning spec's `design.md` normative): this spec's `design.md:19` claims all seven tables as one undivided list and no other spec claims any of them, so the split below is this lane's own implementation choice — `OrderWorkflow` and `Quotation` have genuinely different lifecycles and the payment guard reads them independently.

---

## File Structure

**`app/Domain/OrderWorkflow/`**
- `OrderStatus.php` — closed-list enum, 13 statuses.
- `OrderTransition.php` — the allowed-edge graph plus `assertAllowed()`.
- `ProductType.php` — closed-list enum for AC4 routing.
- `Models/Order.php`, `Models/OrderParty.php`, `Models/DeceasedProfile.php`, `Models/OrderStatusEvent.php`, `Models/OrderDocument.php`
- `Actions/SubmitBookingDraft.php` — draft → order, product-type routing (AC4, AC5).
- `Actions/RecordOrderStatusChange.php` — the **only** writer of `order_status_events` and `orders.status`.
- `Actions/ApplyPaidEffects.php` — the **only** writer of `DIBAYAR` (AC9).
- `Actions/AttachOrderDocument.php` — composes `DocumentVault` (AC10).
- `Exceptions/` — `IllegalOrderTransitionException`, `OrderNotAuthorisedException`.
- `OrderReadModel.php` — Step 9 content (AC13).

**`app/Domain/Quotation/`**
- `QuoteStatus.php` — `ISSUED | ACCEPTED | SUPERSEDED | EXPIRED`.
- `Models/Quote.php`, `Models/QuoteLine.php`
- `Actions/IssueQuote.php` — versioning + immutability (AC8).
- `Actions/AcceptQuote.php`
- `Exceptions/IssuedQuoteIsImmutableException.php`

**Modified:** `app/Platform/Payment/GuardPaymentSession.php` (conditions 2-5 made real). `app/Platform/Audit/SensitiveActions.php` only if a genuinely new sensitive action is needed — check first, because `DITOLAK` and `PAYMENT_MANUAL_VERIFICATION` already exist and cover order rejection and manual-verification approval. Do not add a duplicate under a new name.

**Migrations:** one per table, `database/migrations/2026_08_12_*`, uuid primary keys throughout to match the dominant recent convention.

---

### Task 1: Order status closed list and the forward-only transition graph

Pure in-memory logic, no database. This is the spine every later task asserts against.

**Ratified design (Q1, Q2).** The graph below encodes: `MENUNGGU_VERIFIKASI_PEMBAYARAN` sits on the manual path between `MENUNGGU_PEMBAYARAN` and `DIBAYAR`; a *rejected* verification is **not** an order transition (it lives on `PaymentVerificationStatus`, so the order stays put and the customer resubmits); terminal branches are reachable only from the states named; and **nothing terminal is reachable after `DIBAYAR`**.

**Files:**
- Create: `app/Domain/OrderWorkflow/OrderStatus.php`
- Create: `app/Domain/OrderWorkflow/OrderTransition.php`
- Create: `app/Domain/OrderWorkflow/Exceptions/IllegalOrderTransitionException.php`
- Test: `tests/Unit/Domain/OrderWorkflow/OrderTransitionTest.php`

**Interfaces:**
- Produces: `OrderStatus` (backed enum, string values exactly as in `docs/domain/order-lifecycle.md`); `OrderTransition::isAllowed(OrderStatus $from, OrderStatus $to): bool`; `OrderTransition::assertAllowed(OrderStatus $from, OrderStatus $to): void` (throws `IllegalOrderTransitionException`); `OrderTransition::isTerminal(OrderStatus $s): bool`; `OrderStatus::requiresReason(): bool` (true only for `DITOLAK`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OrderWorkflow;

use App\Domain\OrderWorkflow\Exceptions\IllegalOrderTransitionException;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderTransition;
use App\Support\Design\StatusIntent;
use PHPUnit\Framework\TestCase;

final class OrderTransitionTest extends TestCase
{
    public function test_the_canonical_happy_path_is_allowed_end_to_end(): void
    {
        $chain = [
            OrderStatus::MASUK,
            OrderStatus::DIVERIFIKASI,
            OrderStatus::MENUNGGU_KETERSEDIAAN,
            OrderStatus::PENAWARAN_TERKIRIM,
            OrderStatus::DISETUJUI_PEMESAN,
            OrderStatus::MENUNGGU_PEMBAYARAN,
            OrderStatus::DIBAYAR,
            OrderStatus::DIPROSES,
            OrderStatus::SELESAI,
        ];

        for ($i = 0; $i < count($chain) - 1; $i++) {
            self::assertTrue(
                OrderTransition::isAllowed($chain[$i], $chain[$i + 1]),
                "{$chain[$i]->value} -> {$chain[$i + 1]->value} should be allowed"
            );
        }
    }

    public function test_no_backward_transition_exists_anywhere_in_the_graph(): void
    {
        $order = OrderStatus::forwardOrder();

        foreach ($order as $fromIndex => $from) {
            foreach ($order as $toIndex => $to) {
                if ($toIndex < $fromIndex && OrderTransition::isAllowed($from, $to)) {
                    self::fail("Backward transition {$from->value} -> {$to->value} is allowed");
                }
            }
        }

        self::assertTrue(true);
    }

    public function test_manual_verification_sits_between_awaiting_payment_and_paid(): void
    {
        self::assertTrue(OrderTransition::isAllowed(
            OrderStatus::MENUNGGU_PEMBAYARAN,
            OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN
        ));
        self::assertTrue(OrderTransition::isAllowed(
            OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN,
            OrderStatus::DIBAYAR
        ));
    }

    public function test_a_rejected_verification_cannot_send_the_order_backward(): void
    {
        self::assertFalse(OrderTransition::isAllowed(
            OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN,
            OrderStatus::MENUNGGU_PEMBAYARAN
        ));
    }

    public function test_nothing_terminal_is_reachable_after_paid(): void
    {
        foreach ([OrderStatus::DIBAYAR, OrderStatus::DIPROSES, OrderStatus::SELESAI] as $from) {
            foreach ([OrderStatus::DITOLAK, OrderStatus::DIBATALKAN, OrderStatus::KEDALUWARSA] as $to) {
                self::assertFalse(
                    OrderTransition::isAllowed($from, $to),
                    "{$from->value} -> {$to->value} must not be allowed; use a compensating financial action"
                );
            }
        }
    }

    public function test_rejection_is_only_reachable_from_the_pre_quote_states(): void
    {
        foreach ([OrderStatus::MASUK, OrderStatus::DIVERIFIKASI, OrderStatus::MENUNGGU_KETERSEDIAAN] as $from) {
            self::assertTrue(OrderTransition::isAllowed($from, OrderStatus::DITOLAK));
        }

        foreach ([OrderStatus::PENAWARAN_TERKIRIM, OrderStatus::DISETUJUI_PEMESAN, OrderStatus::MENUNGGU_PEMBAYARAN] as $from) {
            self::assertFalse(OrderTransition::isAllowed($from, OrderStatus::DITOLAK));
        }
    }

    public function test_expiry_is_reachable_only_where_a_window_can_lapse(): void
    {
        foreach ([OrderStatus::PENAWARAN_TERKIRIM, OrderStatus::DISETUJUI_PEMESAN, OrderStatus::MENUNGGU_PEMBAYARAN] as $from) {
            self::assertTrue(OrderTransition::isAllowed($from, OrderStatus::KEDALUWARSA));
        }

        self::assertFalse(
            OrderTransition::isAllowed(OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN, OrderStatus::KEDALUWARSA),
            'Submitted evidence must be decided, never left to lapse'
        );
    }

    public function test_terminal_states_are_absorbing(): void
    {
        foreach ([OrderStatus::SELESAI, OrderStatus::DITOLAK, OrderStatus::DIBATALKAN, OrderStatus::KEDALUWARSA] as $terminal) {
            self::assertTrue(OrderTransition::isTerminal($terminal));

            foreach (OrderStatus::cases() as $to) {
                self::assertFalse(
                    OrderTransition::isAllowed($terminal, $to),
                    "Terminal {$terminal->value} must have no outgoing edge, found -> {$to->value}"
                );
            }
        }
    }

    public function test_assert_allowed_throws_on_an_illegal_edge(): void
    {
        $this->expectException(IllegalOrderTransitionException::class);

        OrderTransition::assertAllowed(OrderStatus::MASUK, OrderStatus::DIBAYAR);
    }

    public function test_only_rejection_demands_a_reason(): void
    {
        self::assertTrue(OrderStatus::DITOLAK->requiresReason());

        foreach (OrderStatus::cases() as $status) {
            if ($status !== OrderStatus::DITOLAK) {
                self::assertFalse($status->requiresReason(), "{$status->value} should not demand a reason");
            }
        }
    }

    public function test_every_status_is_renderable_through_status_intent(): void
    {
        $known = StatusIntent::knownStatuses(StatusIntent::FAMILY_ORDER_LIFECYCLE);

        foreach (OrderStatus::cases() as $status) {
            self::assertContains(
                $status->value,
                $known,
                "{$status->value} has no StatusIntent entry in the order-lifecycle family"
            );

            self::assertNotSame('', StatusIntent::intent(
                $status->value,
                StatusIntent::FAMILY_ORDER_LIFECYCLE
            ));
        }
    }

    public function test_paid_and_completed_stay_distinct_intents(): void
    {
        // design-system.md 3.7: DIBAYAR != SELESAI. Guards against a future
        // refactor merging them into one "done" badge, which AC11 forbids.
        self::assertNotSame(
            StatusIntent::intent(OrderStatus::DIBAYAR->value, StatusIntent::FAMILY_ORDER_LIFECYCLE)
                .'|'.StatusIntent::icon(OrderStatus::DIBAYAR->value, StatusIntent::FAMILY_ORDER_LIFECYCLE),
            StatusIntent::intent(OrderStatus::SELESAI->value, StatusIntent::FAMILY_ORDER_LIFECYCLE)
                .'|'.StatusIntent::icon(OrderStatus::SELESAI->value, StatusIntent::FAMILY_ORDER_LIFECYCLE),
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /home/ubuntu/makam-app/.worktrees/platform-order-orchestration && php vendor/bin/phpunit tests/Unit/Domain/OrderWorkflow/OrderTransitionTest.php`
Expected: FAIL — `Class "App\Domain\OrderWorkflow\OrderStatus" not found`.

- [ ] **Step 3: Write the enum**

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

/**
 * Commercial order status. Values are canonical in
 * `docs/domain/order-lifecycle.md` §1 and rendered through
 * `App\Support\Design\StatusIntent`'s order-lifecycle family — never by
 * matching on these strings in a component.
 *
 * Deliberately NOT the case/work/certificate state: `funeral-case-model.md:35`
 * and `domain-model.md:165` both state those are distinct from commercial
 * status. Requirement 11 depends on the separation.
 */
enum OrderStatus: string
{
    case MASUK = 'MASUK';
    case DIVERIFIKASI = 'DIVERIFIKASI';
    case MENUNGGU_KETERSEDIAAN = 'MENUNGGU_KETERSEDIAAN';
    case PENAWARAN_TERKIRIM = 'PENAWARAN_TERKIRIM';
    case DISETUJUI_PEMESAN = 'DISETUJUI_PEMESAN';
    case MENUNGGU_PEMBAYARAN = 'MENUNGGU_PEMBAYARAN';
    case MENUNGGU_VERIFIKASI_PEMBAYARAN = 'MENUNGGU_VERIFIKASI_PEMBAYARAN';
    case DIBAYAR = 'DIBAYAR';
    case DIPROSES = 'DIPROSES';
    case SELESAI = 'SELESAI';
    case DITOLAK = 'DITOLAK';
    case DIBATALKAN = 'DIBATALKAN';
    case KEDALUWARSA = 'KEDALUWARSA';

    /**
     * The linear progression, used to assert no edge ever points backward.
     * Terminal branches are excluded — they are not positions on the line.
     *
     * @return list<self>
     */
    public static function forwardOrder(): array
    {
        return [
            self::MASUK,
            self::DIVERIFIKASI,
            self::MENUNGGU_KETERSEDIAAN,
            self::PENAWARAN_TERKIRIM,
            self::DISETUJUI_PEMESAN,
            self::MENUNGGU_PEMBAYARAN,
            self::MENUNGGU_VERIFIKASI_PEMBAYARAN,
            self::DIBAYAR,
            self::DIPROSES,
            self::SELESAI,
        ];
    }

    public function requiresReason(): bool
    {
        return $this === self::DITOLAK;
    }
}
```

- [ ] **Step 4: Write the transition graph**

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

use App\Domain\OrderWorkflow\Exceptions\IllegalOrderTransitionException;

/**
 * The allowed commercial edges. Mirrors `docs/domain/order-lifecycle.md` §2's
 * transition matrix, plus the three placements that matrix does not cover and
 * this module had to settle (grill-spec Round 1):
 *
 *   - `MENUNGGU_VERIFIKASI_PEMBAYARAN` is absent from the canonical matrix
 *     entirely. It sits on the MANUAL path only, between MENUNGGU_PEMBAYARAN
 *     and DIBAYAR.
 *   - A REJECTED manual verification is deliberately not an edge here. It is a
 *     `PaymentVerificationStatus` transition, so the order stays put and the
 *     customer submits a new verification — which is how §3's "No transition
 *     backward" and the customer's need to retry are satisfied at once.
 *   - Nothing terminal is reachable after DIBAYAR. Once money is confirmed,
 *     §3's "compensating financial action" (PaymentReversal + reversing
 *     journal batch) is the correction mechanism, not a status edge.
 */
final class OrderTransition
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'MASUK' => ['DIVERIFIKASI', 'DITOLAK', 'DIBATALKAN'],
        'DIVERIFIKASI' => ['MENUNGGU_KETERSEDIAAN', 'DITOLAK', 'DIBATALKAN'],
        'MENUNGGU_KETERSEDIAAN' => ['PENAWARAN_TERKIRIM', 'DITOLAK', 'DIBATALKAN'],
        'PENAWARAN_TERKIRIM' => ['DISETUJUI_PEMESAN', 'KEDALUWARSA', 'DIBATALKAN'],
        'DISETUJUI_PEMESAN' => ['MENUNGGU_PEMBAYARAN', 'KEDALUWARSA', 'DIBATALKAN'],
        'MENUNGGU_PEMBAYARAN' => ['MENUNGGU_VERIFIKASI_PEMBAYARAN', 'DIBAYAR', 'KEDALUWARSA', 'DIBATALKAN'],
        'MENUNGGU_VERIFIKASI_PEMBAYARAN' => ['DIBAYAR', 'DIBATALKAN'],
        'DIBAYAR' => ['DIPROSES'],
        'DIPROSES' => ['SELESAI'],
        'SELESAI' => [],
        'DITOLAK' => [],
        'DIBATALKAN' => [],
        'KEDALUWARSA' => [],
    ];

    public static function isAllowed(OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value], true);
    }

    public static function assertAllowed(OrderStatus $from, OrderStatus $to): void
    {
        if (! self::isAllowed($from, $to)) {
            throw IllegalOrderTransitionException::between($from, $to);
        }
    }

    public static function isTerminal(OrderStatus $status): bool
    {
        return self::ALLOWED[$status->value] === [];
    }

    /** @return list<string> */
    public static function allowedFrom(OrderStatus $from): array
    {
        return self::ALLOWED[$from->value];
    }
}
```

And the exception:

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Exceptions;

use App\Domain\OrderWorkflow\OrderStatus;
use DomainException;

final class IllegalOrderTransitionException extends DomainException
{
    public static function between(OrderStatus $from, OrderStatus $to): self
    {
        return new self("Order transition {$from->value} -> {$to->value} is not allowed.");
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php vendor/bin/phpunit tests/Unit/Domain/OrderWorkflow/OrderTransitionTest.php`
Expected: PASS, 10 tests.

- [ ] **Step 6: Mutation-check the graph before trusting green**

Temporarily add `'DIBAYAR' => ['DIPROSES', 'DIBATALKAN']` to `ALLOWED`, re-run, and confirm `test_nothing_terminal_is_reachable_after_paid` **fails**. Then revert. A transition-matrix test that cannot fail is worthless; this proves it bites.

- [ ] **Step 7: Commit**

```bash
git add app/Domain/OrderWorkflow tests/Unit/Domain/OrderWorkflow
git commit -m "feat(order-workflow): forward-only commercial transition graph"
```

---

### Task 2: `orders` + `order_status_events`, and the single transition writer

**Ratified design (Q3)** for the partial unique index below.

**Files:**
- Create: `database/migrations/2026_08_12_100000_create_orders_table.php`
- Create: `database/migrations/2026_08_12_100010_create_order_status_events_table.php`
- Create: `app/Domain/OrderWorkflow/Models/Order.php`, `Models/OrderStatusEvent.php`
- Create: `app/Domain/OrderWorkflow/Actions/RecordOrderStatusChange.php`
- Test: `tests/Feature/OrderWorkflow/RecordOrderStatusChangeTest.php`

**Interfaces:**
- Consumes: `OrderStatus`, `OrderTransition` (Task 1); `Audit::wrap()`, `AuditSubject`, `AuditOutcome`, `AuditSource`.
- Produces: `RecordOrderStatusChange::__invoke(Order $order, OrderStatus $to, string $actorRef, string $actorRole, ?string $reason = null, array $metadata = []): OrderStatusEvent`.

**Schema notes.** `orders`: uuid PK; `reference` (unique, human-facing, e.g. `MK-2026-000123`); `product_type`; `status`; `booking_draft_id` (nullable FK); `funeral_case_id` / `pre_need_case_id` (nullable); `paid_via` + `paid_source_ref` (nullable, set only by `ApplyPaidEffects`); `correlation_id`; timestamps. `order_status_events`: uuid PK; `order_id` FK; `from_status` (nullable for the initial `MASUK`); `to_status`; `actor_ref`; `actor_role`; `reason` (nullable); `metadata` json; `occurred_at`. Postgres CHECK constraints pin both status columns to the 13 known values.

**The exactly-once index** — the load-bearing line of the whole plan:

```php
// Postgres and SQLite both support partial indexes.
DB::statement(
    'CREATE UNIQUE INDEX order_status_events_paid_once
     ON order_status_events (order_id)
     WHERE to_status = \'DIBAYAR\''
);
```

- [ ] **Step 1: Write the failing test**

```php
public function test_a_legal_transition_is_recorded_with_an_audit_row(): void
{
    $order = $this->makeOrder(OrderStatus::MASUK);

    $event = app(RecordOrderStatusChange::class)(
        $order, OrderStatus::DIVERIFIKASI, 'actor:admin-1', 'admin'
    );

    self::assertSame('DIVERIFIKASI', $order->fresh()->status);
    self::assertSame('MASUK', $event->from_status);
    self::assertDatabaseHas('audit_events', ['action' => 'ORDER_STATUS_CHANGED']);
}

public function test_an_illegal_transition_throws_and_writes_nothing(): void
{
    $order = $this->makeOrder(OrderStatus::MASUK);

    try {
        app(RecordOrderStatusChange::class)($order, OrderStatus::DIBAYAR, 'actor:admin-1', 'admin');
        self::fail('Expected IllegalOrderTransitionException');
    } catch (IllegalOrderTransitionException) {
        // expected
    }

    self::assertSame('MASUK', $order->fresh()->status);
    self::assertSame(0, OrderStatusEvent::query()->where('order_id', $order->getKey())->count());
}

public function test_rejection_without_a_reason_is_refused(): void
{
    $order = $this->makeOrder(OrderStatus::MASUK);

    $this->expectException(\InvalidArgumentException::class);

    app(RecordOrderStatusChange::class)($order, OrderStatus::DITOLAK, 'actor:admin-1', 'admin');
}

public function test_at_most_one_paid_event_can_exist_per_order(): void
{
    $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);

    app(RecordOrderStatusChange::class)($order, OrderStatus::DIBAYAR, 'actor:system', 'system');

    $this->expectException(\Illuminate\Database\QueryException::class);

    // Force a second paid row past the in-memory guard to prove the DATABASE
    // rejects it — application logic is not the thing under test here.
    OrderStatusEvent::query()->create([
        'order_id' => $order->getKey(),
        'from_status' => 'MENUNGGU_PEMBAYARAN',
        'to_status' => 'DIBAYAR',
        'actor_ref' => 'actor:system',
        'actor_role' => 'system',
        'occurred_at' => now(),
    ]);
}

public function test_a_concurrent_double_transition_yields_exactly_one_event(): void
{
    // Two callers racing the same transition: one wins, one throws, and the
    // table holds exactly one row. Re-verified on PostgreSQL in Task 10.
    $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);

    $outcomes = $this->runConcurrently(2, fn () => app(RecordOrderStatusChange::class)(
        $order->fresh(), OrderStatus::DIBAYAR, 'actor:system', 'system'
    ));

    self::assertSame(1, collect($outcomes)->filter(fn ($o) => $o === 'ok')->count());
    self::assertSame(1, OrderStatusEvent::query()
        ->where('order_id', $order->getKey())->where('to_status', 'DIBAYAR')->count());
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php vendor/bin/phpunit tests/Feature/OrderWorkflow/RecordOrderStatusChangeTest.php`
Expected: FAIL — migrations and classes absent.

- [ ] **Step 3: Write the migrations, models, and Action**

`RecordOrderStatusChange` must: re-read the order `lockForUpdate()` inside a transaction, `OrderTransition::assertAllowed()`, reject a blank reason when `$to->requiresReason()` (delegate blankness to the same Unicode-aware check the audit layer uses — do not reimplement), insert the event, update `orders.status`, and wrap the whole thing in `Audit::wrap()` with action `ORDER_STATUS_CHANGED`. It emits `order.status_changed.v1` via the existing Outbox — **no new event name**.

- [ ] **Step 4: Run to verify it passes**

- [ ] **Step 5: Mutation-check the exactly-once index**

Drop the partial index by hand, re-run `test_at_most_one_paid_event_can_exist_per_order`, and confirm it **fails**. Restore. This is the single most important assertion in the lane; prove it bites before trusting it.

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Domain/OrderWorkflow tests/Feature/OrderWorkflow
git commit -m "feat(order-workflow): orders, status events, and the sole transition writer"
```

---

### Task 3: Submission, product-type routing, and the case link (AC4, AC5)

**Files:**
- Create: `app/Domain/OrderWorkflow/ProductType.php`
- Create: `database/migrations/2026_08_12_100020_create_order_parties_table.php`, `..._100030_create_deceased_profiles_table.php`
- Create: `app/Domain/OrderWorkflow/Models/OrderParty.php`, `Models/DeceasedProfile.php`
- Create: `app/Domain/OrderWorkflow/Actions/SubmitBookingDraft.php`
- Create: `app/Domain/FuneralCase/Models/FuneralCase.php`, `Actions/OpenFuneralCase.php`
- Create: `app/Domain/PreNeed/Models/PreNeedInterest.php`, `Actions/RegisterPreNeedInterest.php`
- Test: `tests/Feature/OrderWorkflow/SubmitBookingDraftTest.php`

**Interfaces:**
- Consumes: `BookingDraft` (existing, `app/Domain/Booking/Models/`); `RecordOrderStatusChange` (Task 2).
- Produces: `SubmitBookingDraft::__invoke(BookingDraft $draft, string $idempotencyKey): Order`.

**Routing rule.** `AT_NEED_SERVICE_ORDER` → create or link a `FuneralCase` (states `NEW → TRIAGED → COORDINATING → READY_FOR_SERVICE → IN_SERVICE → COMPLETED`, branches `DECLINED/CANCELLED/TRANSFERRED`, minimum fields urgency/area/owner/deadlines) — **a separate state machine, never merged with `OrderStatus`**. `PRE_NEED_PLOT_PURCHASE` → register interest only, chain `INTEREST_REGISTERED → CONTACTED → CLOSED`, and **create no payment object, invoice, or financial obligation** while `G-LEGAL-01` is closed.

Submission is idempotent on `$idempotencyKey`: resubmitting returns the **same** order, satisfying design-system §6.6 (duplicate submission renders the same confirmation, never a second order).

Tests must cover: At-Need creates a FuneralCase and links it; Pre-Need creates an interest record and **no** payment/invoice row; duplicate submission returns the identical order id; the order starts at `MASUK`; case status and order status remain independently readable.

- [ ] **Step 1: Write the failing tests** — as enumerated above, one test method each.
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement the enum, migrations, models, and Actions.**
- [ ] **Step 4: Run to verify they pass.**
- [ ] **Step 5: Commit** — `feat(order-workflow): submission with product-type routing and case linkage`

---

### Task 4: Versioned, immutable quotes (AC8)

**Ratified design (Q4, Q5).** Written against: acceptance is a property of the **quote version**, not the order, so superseding an accepted quote never moves the order backward; expiry is evaluated **lazily and authoritatively at guard time**, with a scheduled job writing `KEDALUWARSA` only for read-model honesty; conversion from `decimal` to `Money` happens **exactly once, at issuance**.

**Files:**
- Create: `app/Domain/Quotation/QuoteStatus.php`
- Create: `database/migrations/2026_08_12_100040_create_quotes_table.php`, `..._100050_create_quote_lines_table.php`
- Create: `app/Domain/Quotation/Models/Quote.php`, `Models/QuoteLine.php`
- Create: `app/Domain/Quotation/Actions/IssueQuote.php`, `Actions/AcceptQuote.php`
- Create: `app/Domain/Quotation/Exceptions/IssuedQuoteIsImmutableException.php`
- Test: `tests/Feature/Quotation/IssueQuoteTest.php`, `tests/Feature/Quotation/QuoteImmutabilityTest.php`

**Interfaces:**
- Produces: `IssueQuote::__invoke(Order $order, array $lines, CarbonInterface $expiresAt, string $actorRef, string $actorRole): Quote`; `AcceptQuote::__invoke(Quote $quote, string $actorRef): Quote`; `Quote::totalMinor(): Money`; `Quote::isAcceptedAndUnexpired(CarbonInterface $now): bool`; `Quote::currentFor(Order $order): ?Quote`.

**Schema.** `quotes`: uuid PK; `order_id`; `version_number` (**unique per order**); `status`; `total_minor` bigint; `currency`; `issued_at`; `expires_at`; `accepted_at` nullable; `superseded_at` nullable. `quote_lines`: uuid PK; `quote_id`; `service_package_version_id`; `price_version_id`; `price_version_number`; `description`; `quantity`; `unit_amount_minor` bigint; `line_total_minor` bigint; `currency`; `fulfillment_owner`.

Immutability is enforced structurally, mirroring `PriceVersion`'s existing pattern: `update()`/`delete()` throw on a quote that is not `ISSUED`-and-unaccepted, and `quote_lines` are write-once outright.

Tests must cover: issuing v2 marks v1 `SUPERSEDED` and leaves v1's stored amounts byte-identical; **superseding an ACCEPTED v1 does not change the order's status**; a superseded-accepted quote makes `isAcceptedAndUnexpired()` false for the current version, so the guard fails condition 3 without any backward transition; `decimal:2` string `"1250000.00"` converts to exactly `125000000` minor units; total is the `Money::add` sum of line totals; a mixed-currency line set is rejected; a zero or negative total is rejected; an expired quote is not acceptable; direct `->update()` on an issued quote throws.

- [ ] **Step 1: Write the failing tests** — one method per bullet above.
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify they pass.**
- [ ] **Step 5: Mutation-check immutability** — comment out the `update()` guard and confirm the immutability test fails; restore.
- [ ] **Step 6: Commit** — `feat(quotation): versioned immutable quotes with Money-typed totals`

---

### Task 5: Order documents on the Document Vault (AC10)

**Files:**
- Create: `database/migrations/2026_08_12_100060_create_order_documents_table.php`
- Create: `app/Domain/OrderWorkflow/Models/OrderDocument.php`, `Actions/AttachOrderDocument.php`
- Modify: `app/Platform/DocumentVault/DocumentKind.php` (add an order/deceased kind if none fits — check the 9 existing cases first)
- Test: `tests/Feature/OrderWorkflow/OrderDocumentTest.php`

Composes `UploadDocument::upload()` with `ownerType = 'order'` and `ownerId = $order->id`; **never** reimplements storage, quarantine, or signed URLs. Tests: a quarantined document is never previewable, downloadable, or thumbnailed; a signed URL expires within 300 s; every access writes an audit row; a document bound to order A is not reachable from order B.

- [ ] **Step 1-5:** failing test → verify fail → implement → verify pass → commit (`feat(order-workflow): purpose-scoped order documents via the vault`).

---

### Task 6: Payment guard conditions 2-5 made real (AC6)

**Files:**
- Modify: `app/Platform/Payment/GuardPaymentSession.php`
- Create: `app/Domain/OrderWorkflow/Actions/AuthorizeOrderPaymentOpening.php`
- Test: `tests/Feature/Payment/GuardPaymentSessionUpstreamTest.php`

Conditions **2** (confirmation or active reservation), **3** (current quote `ACCEPTED` and unexpired), **4** (authorized opening), and **5** (amount equals quote total, integer equality only) become real evaluations. **Condition 6 stays `UnavailableUpstream`**, citing `FIN-DEC-01` by name.

Authorization for condition 4 follows `docs/security/rbac-matrix.md:11` — "Quote/open payment": **Admin is `Authorized`**; Case Manager may only prepare/request; Customer may only accept; Operator and Vendor never. Implement it the way `FinanceOrRestrictedAdminPayoutAuthorizer` does — check `ActorContext` roles, then an explicit `ScopeAssignment` existence check against `ScopeEntityType::ORDER` (already a reserved value, no migration needed) — and throw a dedicated exception. Do not invent a parallel permission concept.

**The guard still cannot reach PASS**, and a test must assert exactly that: with conditions 2-5 all satisfied by real records, the result is still `DENIED` on condition 6 alone. That is the honest state of the system while `FIN-DEC-01` is `TBD`, and it keeps this lane clear of the `PaymentIntentDecision::Allowed` change that needs human sign-off.

Tests: each of conditions 2-5 denies for a real, specific domain reason (`DomainDenied`, not `UnavailableUpstream`); all four satisfied still denies on 6 with `UnavailableUpstream`; a non-positive quote total denies; an amount differing by one minor unit denies; a Case Manager is denied condition 4 while an Admin passes it.

- [ ] **Step 1-5:** failing test → verify fail → implement → verify pass → commit (`feat(payment): evaluate guard conditions 2-5 against real order records`).

---

### Task 7: `ApplyPaidEffects` — exactly-once across both trigger paths (AC9)

**Ratified design (Q3).** The keystone task. `ProcessWebhookEvent` deliberately only *claims* an event and applies no paid effect; this is the deferred apply step.

> **SEQUENCING CONSTRAINT — do not edit `app/Platform/Payment/Http/Controllers/VerifyManualPaymentController.php` in this task until the coordinator confirms the payment-auth hotfix has landed.** A concurrent lane is actively editing that file (adding an authorization check before the existing `verify()` call, and replacing the hardcoded `actorRole: 'authenticated_actor'` with a real resolved role). `GuardPaymentSession`, `GuardCondition`, and `ProcessWebhookEvent` are confirmed **not** touched by that hotfix and are safe to modify. If this task is reached before the hotfix lands, implement everything except the controller wiring, and report the controller hook as **NOT DONE — BLOCKED on the payment-auth hotfix** rather than editing the file anyway.

**Files:**
- Create: `app/Domain/OrderWorkflow/Actions/ApplyPaidEffects.php`, `PaidTrigger.php`
- Modify: `app/Platform/Payment/ProcessWebhookEvent.php` (call the apply step on a claimed settling event)
- Modify (**only after the hotfix lands** — see constraint above): `app/Platform/Payment/Http/Controllers/VerifyManualPaymentController.php`
- Modify: `app/Platform/Audit/SensitiveActions.php` if a new action is ratified
- Test: `tests/Feature/OrderWorkflow/ApplyPaidEffectsTest.php`

**Interfaces:**
- Produces: `PaidTrigger` (readonly: `source` — `webhook|manual_verification`, `sourceId`, `businessKey`, `Money $amount`, `occurredAt`); `ApplyPaidEffects::__invoke(Order $order, PaidTrigger $trigger): Order`.

**Why two levels of idempotency, stated so a reviewer does not "simplify" one away.** `Journal::post()`'s `$businessKey` is UNIQUE and throws on collision, which protects against replay *within* one path. It does **not** protect across paths: an approved manual verification and a late valid webhook for the same order produce `manual_verify:{id}` and `payment:{id}` — two different keys, neither colliding, both posting. The partial unique index on `order_status_events (order_id) WHERE to_status = 'DIBAYAR'` is what makes the cross-path case impossible, and it is a **database** invariant rather than application logic precisely because the race is concurrent.

All effects run in one `DB::transaction()`: transition to `DIBAYAR` via `RecordOrderStatusChange`, `Journal::post()` with the source-prefixed business key, stamp `paid_via` + `paid_source_ref`, dispatch the `payment.received.v1` notification. A duplicate arrival collides, is swallowed, and returns the **same** order — never a second confirmation and never a second journal batch.

Tests: a webhook applies effects once; a manual verification applies effects once; **the same webhook delivered twice yields one status event and one journal batch**; **a manual verification followed by a webhook for the same order yields one status event and one journal batch** (the cross-path case — this test is the reason the index exists); two concurrent triggers yield exactly one of everything; a failed journal post rolls back the status change entirely; `paid_via` records which trigger won; the amount must equal the accepted quote total.

- [ ] **Step 1: Write the failing tests** — one method per bullet, with the cross-path test written first.
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify they pass.**
- [ ] **Step 5: Mutation-check the cross-path guard** — drop the partial index, re-run the cross-path test, confirm it **fails** with two journal batches. Restore.
- [ ] **Step 6: Commit** — `feat(order-workflow): exactly-once paid effects across webhook and manual paths`

---

### Task 8: Step 9 read model and server-side fallback modes (AC13, AC7)

**Files:**
- Create: `app/Domain/OrderWorkflow/OrderReadModel.php`
- Test: `tests/Feature/OrderWorkflow/OrderReadModelTest.php`

Exposes, server-side: order reference, status (as a `StatusIntent`-resolved intent, never a raw string match), **invoice state as a read-model state only** — `FIN-DEC-02` is `TBD`, so no invoice is produced — channel-delivery state, next action, and a support reference. Distinguishes *no data yet* / *no result for this filter* / *access-restricted* rather than collapsing them to null (§6.2), returns field-keyed validation errors (§6.3), and carries a correlation reference safe to show a user while the raw id goes to logs (§6.10).

Fallback modes (`PaymentMode::MANUAL_COORDINATION`, `WhatsAppMode::EMAIL_IN_APP_FALLBACK`, `PreNeedMode::INTEREST_ONLY`, `GraveSearchMode::MANUAL_ASSISTANCE`) are read from the **server**; a front-end flag is insufficient (§6.9). Tests must assert no request input can select a mode.

**AC12 coverage is explicit, not inferred.** AC12 (preserve the admin/case-manager fallback while an operator has not responded) is otherwise satisfied only incidentally — by `MENUNGGU_KETERSEDIAAN` being non-blocking in Task 1's graph plus the next-action field here. That is sound but leaves a named acceptance criterion with no test of its own, so add one directly asserting it:

```php
public function test_the_admin_fallback_stays_reachable_while_an_operator_has_not_responded(): void
{
    $order = $this->makeOrder(OrderStatus::MENUNGGU_KETERSEDIAAN);

    $view = app(OrderReadModel::class)->forOrder($order);

    // Operator silence is a pending state, never a blocking one.
    self::assertSame('pending', $view->statusIntent);
    self::assertNotNull($view->nextAction, 'Operator silence must leave an actionable next step');

    // The admin/case-manager path out of this state is still open.
    self::assertContains('PENAWARAN_TERKIRIM', OrderTransition::allowedFrom(OrderStatus::MENUNGGU_KETERSEDIAAN));
    self::assertTrue($view->manualFallbackAvailable);
}
```

- [ ] **Step 1-5:** failing test → verify fail → implement → verify pass → commit.

---

### Task 9: Notification dispatch per the matrix (AC14)

**Files:**
- Create: `app/Domain/OrderWorkflow/Listeners/DispatchOrderNotifications.php`
- Test: `tests/Feature/OrderWorkflow/OrderNotificationTest.php`

Dispatches on the matrix rows that already exist (`docs/contracts/notification-matrix.md:61-67`): Quote issued, Quote accepted, Payment opened, Payment received, Order processing, Order completed. **Add no new rows** — the 17 rows and their order are pinned by existing tests. Channel failure never changes business state; delivery is never claimed without delivery state.

- [ ] **Step 1-5:** failing test → verify fail → implement → verify pass → commit.

---

### Task 10: PostgreSQL 18 verification and documentation

**Files:**
- Modify: `docs/domain/order-lifecycle.md` (add `MENUNGGU_VERIFIKASI_PEMBAYARAN` to §1 and §2, and the terminal-branch source states to §3 — the canonical doc is currently silent on both)
- Modify: `.kiro/specs/booking-and-order-orchestration/design.md` (fill the six Kiro sections; add a "Not covered, deliberately" section naming merchant registry, `PaymentIntentDecision::Allowed`, invoice production, and Pre-Need payment)
- Modify: `docs/domain/traceability-matrix.md`

**PostgreSQL is mandatory here.** SQLite has repeatedly hidden real bugs this session, and three things in this plan are Postgres-specific or concurrency-dependent: the partial unique index, the status CHECK constraints, and every concurrency test in Tasks 2 and 7. Run the full suite against PostgreSQL 18 and record the result honestly — `BLOCKED` or `NOT TESTED` if it cannot run, never `PASS`.

- [ ] **Step 1: Run the full suite on PostgreSQL 18.** Coordinate first — the host has ~1.1 Gi free with several lanes active.
- [ ] **Step 2: Fix anything Postgres surfaces that SQLite hid.**
- [ ] **Step 3: Update the canonical docs** — do not duplicate catalogue data; edit the canonical file.
- [ ] **Step 4: Run `ci/verify-docs.sh`.**
- [ ] **Step 5: Commit.**

---

## Self-Review

**Spec coverage.** AC1 entry point → L6's presentation half; this plan's domain half is Task 3's routing. AC2 draft persistence → already built (`app/Domain/Booking/`), unchanged here. AC3 server-side step validation → already built in `SaveBookingDraftStep`; **its per-step `match` is L6's edit site and must not be touched by this lane**. AC4/AC5 → Task 3. AC6 → Task 6. AC7 → Tasks 6 and 8. AC8 → Task 4. AC9 → Task 7. AC10 → Task 5. AC11 → Tasks 1 and 3. AC12 (operator silence keeps the manual fallback actionable) → covered by `MENUNGGU_KETERSEDIAAN` being non-blocking in Task 1's graph plus Task 8's next-action field. AC13 → Task 8. AC14 → Task 9.

**Known gaps, stated rather than hidden.** AC12's admin/case-manager fallback has no dedicated task; it is satisfied incidentally by the graph and the read model. If the reviewer disagrees, it needs its own task. The `Contracts\PaymentProvider` interface named in `retrofit-backlog.md:264` has no owner and is **not** created here — new-contract authorship was withheld from L3 and is not claimed by this lane either.

**Type consistency.** `OrderStatus`, `OrderTransition::isAllowed/assertAllowed/isTerminal`, `RecordOrderStatusChange::__invoke`, `ApplyPaidEffects::__invoke`, `PaidTrigger`, `Quote::totalMinor/isAcceptedAndUnexpired/currentFor` are used with identical names and signatures wherever they appear across tasks.

**Placeholder scan.** No TBD/TODO steps. Tasks 3, 5, 8, and 9 give test *enumerations* rather than full method bodies — deliberate, because each bullet maps one-to-one onto a test method and the invariant-critical tasks (1, 2, 4, 7) carry the full code. An implementer of those tasks should write the enumerated methods as listed.
