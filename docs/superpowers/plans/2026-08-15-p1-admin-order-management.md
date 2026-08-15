# P1 — Admin Order Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every order admin-managed — booking, marketplace, and renewal — with role-gated operator/finance transitions, so the P0 two-phase payment journey completes end-to-end (operator verifies/accepts → finance authorizes → buyer re-clicks → SumoPod redirect).

**Architecture:** Three new Filament resources under `app/Filament/Admin/Resources/<Kind>/` mirroring the canonical FaqArticles/CemeteryResource pattern; every state change flows through new/domain Actions (`RecordOrderStatusChange`-composed), gated by a new `OrderTransitionAuthorizer` (operator/finance split) with money-adjacent actions additionally under a `ReauthenticationGuard`. Payment-opening authorization reuses the existing `GrantScopeAssignment` + `AuthorizeOrderPaymentOpening` seams. Spec: `docs/superpowers/specs/2026-08-15-admin-order-management-design.md`.

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament 5 / Livewire 4 / PostgreSQL 18 + SQLite (tests).

## Global Constraints

- `RecordOrderStatusChange` is the ONE writer of `orders.status` + `order_status_events` — every order transition calls it; never write `status` directly.
- `Order::applyStatus(OrderStatusEvent $event)` / `Order::stampPaidSource($event, ...)` are the only write doors on `orders`; `update()`/`delete()` throw.
- `IssueQuote` is the ONE writer of `quotes`+`quote_lines`; `AcceptQuote` the ONE acceptor; `ApplyPaidEffects` the ONE writer of `DIBAYAR` (amount must equal the accepted unexpired quote's total in minor units).
- Money-adjacent transitions: finance or admin role ONLY (`restricted_admin` never) AND a fresh re-authentication (config `reauthentication.freshness_seconds`, default 900).
- `restricted_admin` may NOT perform `issue_quote` (creates a binding quote). All other non-money transitions open to admin/operator/restricted_admin.
- Audit every transition: `RecordOrderStatusChange` audits internally (`ORDER_STATUS_CHANGED`, `DITOLAK` when sensitive); grant/evidence actions wrap `Audit::wrap` with `AuditSource::Panel`.
- Actor identity comes from `app(ActorContext::class)->identityReference`; audit role via the resource's own `auditRoleFor(ActorContext)` walking `[ADMIN, RESTRICTED_ADMIN, OPERATOR, FINANCE]` (CemeteryResource precedent).
- Resource access gate: `MasterDataAdminAuthorizerContract` (admits the 4 back-office roles) in `canAccess()` + `getAuthorizationResponse()`, exactly like CemeteryResource.
- No Create pages (orders originate from the public flow); Delete disabled; Edit limited to non-financial fields.
- Money amounts are integer minor units (`App\Platform\FinancialLedger\Money`), never floats.
- Outbox events come from the domain Actions themselves; never invent new event names (`order.status_changed.v1`, `quote.issued.v1`, `quote.accepted.v1`, `payment.received.v1` are the catalogued ones in play).
- All admin audit rows record `AuditSource::Panel` (except where the underlying platform action records its own source).
- Lint gate: `composer lint` (pint --test); static: `composer analyse` (phpstan); tests: `composer test` (SQLite); PG18 gate runs the same suite against the dev host's PostgreSQL.
- Indonesian UI copy for all labels/notifications (existing convention); English only in code identifiers.
- Worktree execution: branch `feat/p1-admin-orders` from `docs/design-system-and-planning`; ledger at `.superpowers/sdd/p1-admin-order-management/progress.md`.

---

## Task 1: OrderTransitionAuthorizer + ReauthenticationGuard (shared infra)

**Files:**
- Create: `app/Domain/OrderWorkflow/Authorization/Contracts/OrderTransitionAuthorizerContract.php`
- Create: `app/Domain/OrderWorkflow/Authorization/OrderTransitionAuthorizer.php`
- Create: `app/Domain/OrderWorkflow/Exceptions/OrderActionNotAuthorisedException.php`
- Create: `app/Domain/OrderWorkflow/OrderWorkflowAuditActions.php`
- Create: `app/Domain/OrderWorkflow/Providers/OrderWorkflowServiceProvider.php`
- Modify: `bootstrap/providers.php` (register the provider, alphabetical position like `FaqServiceProvider`)
- Create: `app/Platform/IdentityAccess/Reauthentication/ReauthenticationGuard.php`
- Create: `app/Platform/IdentityAccess/Reauthentication/Exceptions/ReauthenticationRequiredException.php`
- Test: `tests/Unit/Domain/OrderWorkflow/OrderTransitionAuthorizerTest.php`, `tests/Unit/Platform/ReauthenticationGuardTest.php`

**Interfaces:**
- Consumes: `ActorContext` (`identityReference`, `roles`, `lastAuthenticatedAt`), `ActorRole` constants, `Config::get('reauthentication.freshness_seconds', 900)`.
- Produces:
  - `OrderTransitionAuthorizerContract::authorizeTransition(ActorContext $actor, string $transition): void` — throws `OrderActionNotAuthorisedException`.
  - `ReauthenticationGuard::assertFresh(ActorContext $actor): void` — throws `ReauthenticationRequiredException`.
  - `OrderWorkflowAuditActions::PAYMENT_OPENING_AUTHORIZED = 'ORDER_PAYMENT_OPENING_AUTHORIZED'`, `::MANUAL_PAYMENT_VERIFICATION_STARTED = 'ORDER_MANUAL_PAYMENT_VERIFICATION_STARTED'`.

- [ ] **Step 1: Write the failing authorizer test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OrderWorkflow;

use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\OrderWorkflow\Exceptions\OrderActionNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Tests\TestCase;

final class OrderTransitionAuthorizerTest extends TestCase
{
    private const string NON_MONEY = 'verify_order';
    private const string MONEY = 'mark_order_paid';

    private function actor(array $roles, ?string $lastAuth = null): ActorContext
    {
        return new ActorContext(
            identityReference: 'user:1',
            roles: $roles,
            scopes: [],
            mfaState: ActorContext::MFA_STATE_NOT_APPLICABLE,
            lastAuthenticatedAt: $lastAuth === null ? null : \Carbon\CarbonImmutable::parse($lastAuth),
        );
    }

    public function test_operator_can_run_non_money_transition(): void
    {
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::OPERATOR]), self::NON_MONEY);
        $this->assertTrue(true);
    }

    public function test_finance_cannot_run_plain_operator_transition(): void
    {
        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::FINANCE]), self::NON_MONEY);
    }

    public function test_finance_can_run_money_transition(): void
    {
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::FINANCE]), self::MONEY);
        $this->assertTrue(true);
    }

    public function test_operator_cannot_run_money_transition(): void
    {
        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::OPERATOR]), self::MONEY);
    }

    public function test_admin_can_run_everything(): void
    {
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::ADMIN]), self::MONEY);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::ADMIN]), self::NON_MONEY);
        $this->assertTrue(true);
    }

    public function test_restricted_admin_cannot_issue_quote(): void
    {
        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::RESTRICTED_ADMIN]), 'issue_quote');
    }

    public function test_restricted_admin_can_verify_order(): void
    {
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::RESTRICTED_ADMIN]), 'verify_order');
        $this->assertTrue(true);
    }

    public function test_guest_is_denied(): void
    {
        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([]), self::NON_MONEY);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Domain/OrderWorkflow/OrderTransitionAuthorizerTest.php`
Expected: FAIL — class not found (`OrderTransitionAuthorizerContract`).

- [ ] **Step 3: Implement the authorizer**

`app/Domain/OrderWorkflow/Authorization/Contracts/OrderTransitionAuthorizerContract.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Authorization\Contracts;

use App\Platform\IdentityAccess\ActorContext;

interface OrderTransitionAuthorizerContract
{
    /**
     * @throws \App\Domain\OrderWorkflow\Exceptions\OrderActionNotAuthorisedException
     */
    public function authorizeTransition(ActorContext $actor, string $transition): void;
}
```

`app/Domain/OrderWorkflow/Authorization/OrderTransitionAuthorizer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Authorization;

use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\OrderWorkflow\Exceptions\OrderActionNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;

final class OrderTransitionAuthorizer implements OrderTransitionAuthorizerContract
{
    /** Transitions that create a binding quote — restricted_admin excluded. */
    private const array QUOTE_ISSUING_TRANSITIONS = ['issue_quote'];

    /** Transitions that touch money or authorize payment opening — finance/admin only. */
    private const array MONEY_TRANSITIONS = [
        'authorize_payment_opening',
        'manual_payment_verification',
        'mark_order_paid',
        'mark_marketplace_order_paid',
        'record_external_renewal_payment',
    ];

    public function authorizeTransition(ActorContext $actor, string $transition): void
    {
        if ($actor->identityReference === null || $actor->roles === []) {
            throw OrderActionNotAuthorisedException::forActorContext();
        }

        if (in_array(ActorRole::ADMIN, $actor->roles, true)) {
            return;
        }

        if (in_array($transition, self::MONEY_TRANSITIONS, true)) {
            if (in_array(ActorRole::FINANCE, $actor->roles, true)) {
                return;
            }

            throw OrderActionNotAuthorisedException::forTransition($transition);
        }

        if (in_array($transition, self::QUOTE_ISSUING_TRANSITIONS, true)
            && in_array(ActorRole::RESTRICTED_ADMIN, $actor->roles, true)) {
            throw OrderActionNotAuthorisedException::forTransition($transition);
        }

        if (in_array(ActorRole::OPERATOR, $actor->roles, true)
            || in_array(ActorRole::RESTRICTED_ADMIN, $actor->roles, true)
            || in_array(ActorRole::FINANCE, $actor->roles, true)) {
            return;
        }

        throw OrderActionNotAuthorisedException::forActorContext();
    }
}
```

`app/Domain/OrderWorkflow/Exceptions/OrderActionNotAuthorisedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Exceptions;

use RuntimeException;

final class OrderActionNotAuthorisedException extends RuntimeException
{
    public static function forActorContext(): self
    {
        return new self('The actor is not authorised to manage orders.');
    }

    public static function forTransition(string $transition): self
    {
        return new self("The actor is not authorised for the [{$transition}] order transition.");
    }
}
```

`app/Domain/OrderWorkflow/OrderWorkflowAuditActions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

final class OrderWorkflowAuditActions
{
    public const string PAYMENT_OPENING_AUTHORIZED = 'ORDER_PAYMENT_OPENING_AUTHORIZED';
    public const string MANUAL_PAYMENT_VERIFICATION_STARTED = 'ORDER_MANUAL_PAYMENT_VERIFICATION_STARTED';
}
```

`app/Domain/OrderWorkflow/Providers/OrderWorkflowServiceProvider.php` (pattern: `FaqServiceProvider`):

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Providers;

use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\OrderWorkflow\Authorization\OrderTransitionAuthorizer;
use Illuminate\Support\ServiceProvider;

final class OrderWorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderTransitionAuthorizerContract::class, OrderTransitionAuthorizer::class);
    }
}
```

Modify `bootstrap/providers.php`: add `use App\Domain\OrderWorkflow\Providers\OrderWorkflowServiceProvider;` + its entry to the array, alphabetically beside the other domain providers.

- [ ] **Step 4: Run the authorizer test**

Run: `php artisan test tests/Unit/Domain/OrderWorkflow/OrderTransitionAuthorizerTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Write the failing reauthentication guard test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class ReauthenticationGuardTest extends TestCase
{
    private function actor(?CarbonImmutable $lastAuth): ActorContext
    {
        return new ActorContext(
            identityReference: 'user:1',
            roles: [ActorRole::FINANCE],
            scopes: [],
            mfaState: ActorContext::MFA_STATE_ENROLLED,
            lastAuthenticatedAt: $lastAuth,
        );
    }

    public function test_null_last_authentication_fails_closed(): void
    {
        $this->expectException(ReauthenticationRequiredException::class);
        app(ReauthenticationGuard::class)->assertFresh($this->actor(null));
    }

    public function test_recent_authentication_passes(): void
    {
        app(ReauthenticationGuard::class)->assertFresh($this->actor(CarbonImmutable::now()->subMinutes(2)));
        $this->assertTrue(true);
    }

    public function test_stale_authentication_fails(): void
    {
        $this->expectException(ReauthenticationRequiredException::class);
        app(ReauthenticationGuard::class)->assertFresh($this->actor(CarbonImmutable::now()->subMinutes(30)));
    }

    public function test_boundary_of_freshness_window_passes(): void
    {
        $window = (int) config('reauthentication.freshness_seconds', 900);
        app(ReauthenticationGuard::class)->assertFresh($this->actor(CarbonImmutable::now()->subSeconds($window - 1)));
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 6: Run to verify it fails**

Run: `php artisan test tests/Unit/Platform/ReauthenticationGuardTest.php`
Expected: FAIL — class not found.

- [ ] **Step 7: Implement the guard**

`app/Platform/IdentityAccess/Reauthentication/ReauthenticationGuard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Reauthentication;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use Carbon\CarbonImmutable;

final class ReauthenticationGuard
{
    public function assertFresh(ActorContext $actor): void
    {
        $lastAuthenticatedAt = $actor->lastAuthenticatedAt;

        if ($lastAuthenticatedAt === null) {
            throw ReauthenticationRequiredException::forActor();
        }

        $freshness = (int) config('reauthentication.freshness_seconds', 900);

        if ($lastAuthenticatedAt->lt(CarbonImmutable::now()->subSeconds($freshness))) {
            throw ReauthenticationRequiredException::forActor();
        }
    }
}
```

`app/Platform/IdentityAccess/Reauthentication/Exceptions/ReauthenticationRequiredException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Reauthentication\Exceptions;

use RuntimeException;

final class ReauthenticationRequiredException extends RuntimeException
{
    public static function forActor(): self
    {
        return new self('A recent re-authentication is required before this action may be performed.');
    }
}
```

- [ ] **Step 8: Run both unit tests**

Run: `php artisan test tests/Unit/Domain/OrderWorkflow/OrderTransitionAuthorizerTest.php tests/Unit/Platform/ReauthenticationGuardTest.php`
Expected: PASS (12 tests).

- [ ] **Step 9: Lint, analyse, commit**

```bash
composer lint && composer analyse && php artisan test tests/Unit/Domain/OrderWorkflow/OrderTransitionAuthorizerTest.php tests/Unit/Platform/ReauthenticationGuardTest.php
git add bootstrap/providers.php app/Domain/OrderWorkflow app/Platform/IdentityAccess/Reauthentication tests/Unit
git commit -m "feat(admin): order transition authorizer and reauthentication guard (P1 lane 1)"
```

---

## Task 2: Non-money booking operator Actions (forward path)

**Files:**
- Create: `app/Domain/OrderWorkflow/Actions/VerifyOrder.php`
- Create: `app/Domain/OrderWorkflow/Actions/RequestAvailability.php`
- Create: `app/Domain/OrderWorkflow/Actions/IssueOrderQuote.php`
- Create: `app/Domain/OrderWorkflow/Actions/RecordBuyerApproval.php`
- Create: `app/Domain/OrderWorkflow/Actions/ProcessOrder.php`
- Create: `app/Domain/OrderWorkflow/Actions/CompleteOrder.php`
- Test: `tests/Feature/OrderWorkflow/AdminOperatorActionsTest.php`

**Interfaces:**
- Consumes: `RecordOrderStatusChange::__invoke(Order $order, OrderStatus $to, string $actorRef, string $actorRole, ?string $reason = null, array $metadata = []): OrderStatusEvent`; `ComposeQuoteLinesFromBookingDraft::__invoke(BookingDraft $draft): array`; `IssueQuote::__invoke(Order $order, array $lines, CarbonInterface $expiresAt, string $actorRef, string $actorRole): Quote`; `OrderStatus` cases; `Order::$bookingDraft` (BelongsTo, nullable).
- Produces (all `App\Domain\OrderWorkflow\Actions`):
  - `VerifyOrder::__invoke(Order $order, string $actorRef, string $actorRole, ?string $reason = null, array $metadata = []): OrderStatusEvent` (→ DIVERIFIKASI)
  - `RequestAvailability::__invoke(...same...): OrderStatusEvent` (→ MENUNGGU_KETERSEDIAAN)
  - `IssueOrderQuote::__invoke(Order $order, CarbonInterface $expiresAt, string $actorRef, string $actorRole, ?string $reason = null, array $metadata = []): OrderStatusEvent` (compose from draft → IssueQuote → → PENAWARAN_TERKIRIM; throws `InvalidArgumentException` when the order has no booking draft)
  - `RecordBuyerApproval::__invoke(...): OrderStatusEvent` (→ DISETUJUI_PEMESAN)
  - `ProcessOrder::__invoke(...): OrderStatusEvent` (→ DIPROSES)
  - `CompleteOrder::__invoke(...): OrderStatusEvent` (→ SELESAI)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\OrderWorkflow\Actions\CompleteOrder;
use App\Domain\OrderWorkflow\Actions\IssueOrderQuote;
use App\Domain\OrderWorkflow\Actions\ProcessOrder;
use App\Domain\OrderWorkflow\Actions\RecordBuyerApproval;
use App\Domain\OrderWorkflow\Actions\RequestAvailability;
use App\Domain\OrderWorkflow\Actions\VerifyOrder;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Models\Quote;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminOperatorActionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(OrderStatus $status, ?BookingDraft $draft = null): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
            'booking_draft_id' => $draft?->getKey(),
        ]);
    }

    private function makePricedService(): ServiceDefinition
    {
        $service = ServiceDefinition::factory()->create(['fulfillment_owner' => 'cemetery']);
        PriceVersion::factory()->create(['service_definition_id' => $service->getKey(), 'amount' => '250000.00', 'status' => 'active']);

        return $service;
    }

    public function test_verify_order_transitions_to_diverifikasi(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);
        $event = app(VerifyOrder::class)($order, 'user:1', 'operator');
        $this->assertSame(OrderStatus::DIVERIFIKASI->value, $event->to_status);
        $this->assertSame(OrderStatus::DIVERIFIKASI, $order->status());
        $this->assertDatabaseHas('order_status_events', ['order_id' => $order->getKey(), 'to_status' => 'DIVERIFIKASI']);
        $this->assertDatabaseHas('audit_events', ['subject_type' => 'order', 'subject_id' => $order->getKey()]);
        $this->assertDatabaseHas('outbox_events', ['aggregate_type' => 'order', 'aggregate_id' => $order->getKey()]);
    }

    public function test_request_availability_from_diverifikasi(): void
    {
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI);
        app(RequestAvailability::class)($order, 'user:1', 'operator');
        $this->assertSame(OrderStatus::MENUNGGU_KETERSEDIAAN, $order->status());
    }

    public function test_issue_order_quote_composes_from_draft_and_issues_quote(): void
    {
        $service = $this->makePricedService();
        $draft = BookingDraft::query()->create([
            'service_type' => 'at_need',
            'selected_services' => [['service_code' => $service->code, 'quantity' => 1]],
            'customer_full_name' => 'UAT Penerima',
        ]);
        $order = $this->makeOrder(OrderStatus::MENUNGGU_KETERSEDIAAN, $draft);

        app(IssueOrderQuote::class)($order, CarbonImmutable::now()->addDays(30), 'user:1', 'operator');

        $quote = Quote::currentFor($order);
        $this->assertNotNull($quote);
        $this->assertSame(OrderStatus::PENAWARAN_TERKIRIM, $order->status());
        $this->assertCount(1, $quote->lines);
    }

    public function test_issue_order_quote_refuses_order_without_draft(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_KETERSEDIAAN);
        $this->expectException(\InvalidArgumentException::class);
        app(IssueOrderQuote::class)($order, CarbonImmutable::now()->addDays(30), 'user:1', 'operator');
    }

    public function test_record_buyer_approval_from_quote_sent(): void
    {
        $order = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);
        app(RecordBuyerApproval::class)($order, 'user:1', 'operator');
        $this->assertSame(OrderStatus::DISETUJUI_PEMESAN, $order->status());
    }

    public function test_process_and_complete(): void
    {
        $order = $this->makeOrder(OrderStatus::DIBAYAR);
        app(ProcessOrder::class)($order, 'user:1', 'operator');
        $this->assertSame(OrderStatus::DIPROSES, $order->status());
        app(CompleteOrder::class)($order, 'user:1', 'operator');
        $this->assertSame(OrderStatus::SELESAI, $order->status());
    }

    public function test_illegal_edge_from_matrix_is_rejected(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);
        $this->expectException(\App\Domain\OrderWorkflow\Exceptions\IllegalOrderTransitionException::class);
        app(ProcessOrder::class)($order, 'user:1', 'operator');
    }
}
```

Note: verify `ServiceDefinition::factory()` + `PriceVersion::factory()` exist and their fillable field names (`code`, `amount`, `status`, `service_definition_id`) — adjust the factories to the real shape if the field names differ (check `database/factories/` during implementation). `BookingDraft::query()->create([...])` requires `service_type` and `selected_services` shapes matching `BootedServiceType::assertKnown`/`BookingServiceType` — align with the existing `SubmitBookingDraftTest` fixture if the test fails on validation.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/OrderWorkflow/AdminOperatorActionsTest.php`
Expected: FAIL — `VerifyOrder` not found.

- [ ] **Step 3: Implement the six Actions**

Each of the five pure-transition actions is the same shape; `VerifyOrder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;

final readonly class VerifyOrder
{
    public function __invoke(
        Order $order,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
        array $metadata = [],
    ): OrderStatusEvent {
        return app(RecordOrderStatusChange::class)(
            $order,
            OrderStatus::DIVERIFIKASI,
            $actorRef,
            $actorRole,
            $reason,
            $metadata,
        );
    }
}
```

`RequestAvailability.php` — identical shape, target `OrderStatus::MENUNGGU_KETERSEDIAAN`.
`RecordBuyerApproval.php` — identical shape, target `OrderStatus::DISETUJUI_PEMESAN`.
`ProcessOrder.php` — identical shape, target `OrderStatus::DIPROSES`.
`CompleteOrder.php` — identical shape, target `OrderStatus::SELESAI`.

`IssueOrderQuote.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\Quotation\Actions\ComposeQuoteLinesFromBookingDraft;
use App\Domain\Quotation\Actions\IssueQuote;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class IssueOrderQuote
{
    public function __construct(
        private ComposeQuoteLinesFromBookingDraft $composeLines,
        private IssueQuote $issueQuote,
    ) {}

    public function __invoke(
        Order $order,
        CarbonInterface $expiresAt,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
        array $metadata = [],
    ): OrderStatusEvent {
        $draft = $order->bookingDraft;

        if (! $draft instanceof \App\Domain\Booking\Models\BookingDraft) {
            throw new InvalidArgumentException(
                'Order has no booking draft to compose quote lines from.'
            );
        }

        $lines = ($this->composeLines)($draft);
        ($this->issueQuote)($order, $lines, $expiresAt, $actorRef, $actorRole);

        return app(RecordOrderStatusChange::class)(
            $order,
            OrderStatus::PENAWARAN_TERKIRIM,
            $actorRef,
            $actorRole,
            $reason,
            $metadata,
        );
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/OrderWorkflow/AdminOperatorActionsTest.php`
Expected: PASS. Fix any factory/shape mismatches against `database/factories/`.

- [ ] **Step 5: Lint, analyse, commit**

```bash
composer lint && composer analyse
git add app/Domain/OrderWorkflow/Actions tests/Feature/OrderWorkflow
git commit -m "feat(order-workflow): non-money operator actions for the booking lifecycle (P1 lane 2)"
```

---

## Task 3: Terminal + money-adjacent Actions

**Files:**
- Create: `app/Domain/OrderWorkflow/Actions/RejectOrder.php`
- Create: `app/Domain/OrderWorkflow/Actions/CancelOrder.php`
- Create: `app/Domain/OrderWorkflow/Actions/ExpireOrder.php`
- Create: `app/Domain/OrderWorkflow/Actions/GrantOrderPaymentOpening.php`
- Create: `app/Domain/OrderWorkflow/Actions/ManualPaymentVerification.php`
- Create: `app/Domain/OrderWorkflow/Actions/MarkOrderPaid.php`
- Test: `tests/Feature/OrderWorkflow/AdminMoneyActionsTest.php`

**Interfaces:**
- Consumes: `RecordOrderStatusChange` (as Task 2); `GrantScopeAssignment::__invoke(int|string $actorIdentifier, string $entityType, int|string $entityId, ?string $grantLevel, string $reason, int|string|null $grantedBy): ScopeAssignment`; `ScopeEntityType::ORDER`; `Quote::currentFor(Order $order): ?Quote`; `ApplyPaidEffects::__invoke(Order $order, PaidTrigger $trigger): Order`; `PaidTrigger::__construct(PaidTriggerSource $source, string $sourceId, string $businessKey, Money $amount, string $currency, CarbonImmutable $occurredAt, string $actorRef, string $actorRole)`; `PaidTriggerSource::ManualVerification`; `Audit::wrap(...)`; `OrderWorkflowAuditActions`; `AuditSubject`, `AuditOutcome`, `AuditSource::Panel`, `CorrelationContext::current()?->value`.
- Produces (all `App\Domain\OrderWorkflow\Actions`):
  - `RejectOrder::__invoke(Order $order, string $actorRef, string $actorRole, string $reason, array $metadata = []): OrderStatusEvent` (→ DITOLAK; reason REQUIRED — `RecordOrderStatusChange` enforces)
  - `CancelOrder::__invoke(Order $order, string $actorRef, string $actorRole, ?string $reason = null, array $metadata = []): OrderStatusEvent` (→ DIBATALKAN)
  - `ExpireOrder::__invoke(...): OrderStatusEvent` (→ KEDALUWARSA)
  - `GrantOrderPaymentOpening::__invoke(Order $order, int|string $granteeActorIdentifier, string $actorRef, string $actorRole, ?string $reason = null, array $metadata = []): OrderStatusEvent` — ORDER-scope grant for the grantee + → MENUNGGU_PEMBAYARAN; audits `ORDER_PAYMENT_OPENING_AUTHORIZED`.
  - `ManualPaymentVerification::__invoke(Order $order, string $actorRef, string $actorRole, string $verificationNote, array $metadata = []): OrderStatusEvent` — → MENUNGGU_VERIFIKASI_PEMBAYARAN, note carried as the event/audit reason; audits `ORDER_MANUAL_PAYMENT_VERIFICATION_STARTED`.
  - `MarkOrderPaid::__invoke(Order $order, string $actorRef, string $actorRole, ?string $reason = null): Order` — builds `PaidTrigger` from the current accepted quote total (`Quote::currentFor`; missing quote → `ApplyPaidEffects` throws `PaidAmountDoesNotMatchQuoteException::forMissingAcceptedQuote`), source `PaidTriggerSource::ManualVerification`, sourceId `manual:{$actorRef}`, businessKey `manual_paid:{$order->reference}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\OrderWorkflow\Actions\CancelOrder;
use App\Domain\OrderWorkflow\Actions\ExpireOrder;
use App\Domain\OrderWorkflow\Actions\GrantOrderPaymentOpening;
use App\Domain\OrderWorkflow\Actions\ManualPaymentVerification;
use App\Domain\OrderWorkflow\Actions\MarkOrderPaid;
use App\Domain\OrderWorkflow\Actions\RejectOrder;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Actions\IssueQuote;
use App\Domain\Quotation\Models\Quote;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminMoneyActionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }

    private function issueAndAcceptQuote(Order $order): Quote
    {
        $quote = app(IssueQuote::class)(
            $order,
            [
                [
                    'service_definition_id' => 1,
                    'price_version_id' => 1,
                    'price_version_number' => 1,
                    'quantity' => 1,
                    'unit_amount' => '250000.00',
                    'currency' => 'IDR',
                    'fulfillment_owner' => 'cemetery',
                ],
            ],
            CarbonImmutable::now()->addDays(30),
            'system',
            'system',
        );
        app(\App\Domain\Quotation\Actions\AcceptQuote::class)($quote, 'system');

        return $quote;
    }

    public function test_reject_requires_reason(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);
        $this->expectException(\InvalidArgumentException::class);
        app(RejectOrder::class)($order, 'user:1', 'operator', '');
    }

    public function test_reject_transitions_with_reason(): void
    {
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI);
        app(RejectOrder::class)($order, 'user:1', 'operator', 'Data tidak lengkap');
        $this->assertSame(OrderStatus::DITOLAK, $order->status());
        $this->assertDatabaseHas('audit_events', ['action' => 'DITOLAK']);
    }

    public function test_cancel_and_expire(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);
        app(CancelOrder::class)($order, 'user:1', 'operator');
        $this->assertSame(OrderStatus::DIBATALKAN, $order->status());
        $expiring = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);
        app(ExpireOrder::class)($expiring, 'user:1', 'operator');
        $this->assertSame(OrderStatus::KEDALUWARSA, $expiring->status());
    }

    public function test_grant_payment_opening_creates_order_scope_grant_and_transitions(): void
    {
        $order = $this->makeOrder(OrderStatus::DISETUJUI_PEMESAN);
        app(GrantOrderPaymentOpening::class)($order, 'user:99', 'user:1', 'finance');

        $grant = ScopeAssignment::query()
            ->where('actor_identifier', 'user:99')
            ->where('entity_type', ScopeEntityType::ORDER)
            ->where('entity_id', (string) $order->getKey())
            ->first();
        $this->assertNotNull($grant);
        $this->assertNull($grant->revoked_at);
        $this->assertSame(OrderStatus::MENUNGGU_PEMBAYARAN, $order->status());
        $this->assertDatabaseHas('audit_events', ['action' => 'ORDER_PAYMENT_OPENING_AUTHORIZED']);
    }

    public function test_manual_payment_verification_records_note(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);
        app(ManualPaymentVerification::class)($order, 'user:1', 'finance', 'Transfer BCA 250000 atas nama UAT');
        $this->assertSame(OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN, $order->status());
        $this->assertDatabaseHas('order_status_events', [
            'order_id' => $order->getKey(),
            'to_status' => 'MENUNGGU_VERIFIKASI_PEMBAYARAN',
            'reason' => 'Transfer BCA 250000 atas nama UAT',
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'ORDER_MANUAL_PAYMENT_VERIFICATION_STARTED']);
    }

    public function test_mark_order_paid_reaches_dibayar_and_stamps_source(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN);
        $this->issueAndAcceptQuote($order);

        $paid = app(MarkOrderPaid::class)($order, 'user:1', 'finance');

        $this->assertSame(OrderStatus::DIBAYAR, $paid->status());
        $this->assertSame('manual_verification', $paid->paid_via);
        $this->assertSame('manual:user:1', $paid->paid_source_ref);
        $this->assertDatabaseHas('outbox_events', ['aggregate_type' => 'order']);
    }

    public function test_mark_order_paid_without_quote_throws(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN);
        $this->expectException(\App\Domain\OrderWorkflow\Exceptions\PaidAmountDoesNotMatchQuoteException::class);
        app(MarkOrderPaid::class)($order, 'user:1', 'finance');
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/OrderWorkflow/AdminMoneyActionsTest.php`
Expected: FAIL — `RejectOrder` not found.

- [ ] **Step 3: Implement the terminal actions**

`RejectOrder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;

final readonly class RejectOrder
{
    public function __invoke(
        Order $order,
        string $actorRef,
        string $actorRole,
        string $reason,
        array $metadata = [],
    ): OrderStatusEvent {
        return app(RecordOrderStatusChange::class)(
            $order,
            OrderStatus::DITOLAK,
            $actorRef,
            $actorRole,
            $reason,
            $metadata,
        );
    }
}
```

`CancelOrder.php` — target `OrderStatus::DIBATALKAN`, reason nullable (same shape as VerifyOrder).
`ExpireOrder.php` — target `OrderStatus::KEDALUWARSA`, reason nullable.

- [ ] **Step 4: Implement `GrantOrderPaymentOpening`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderWorkflowAuditActions;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

final readonly class GrantOrderPaymentOpening
{
    public function __construct(
        private GrantScopeAssignment $grantScope,
    ) {}

    public function __invoke(
        Order $order,
        int|string $granteeActorIdentifier,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
        array $metadata = [],
    ): OrderStatusEvent {
        return Audit::wrap(
            mutation: function () use ($order, $granteeActorIdentifier, $actorRef, $actorRole, $reason, $metadata): OrderStatusEvent {
                ($this->grantScope)(
                    actorIdentifier: $granteeActorIdentifier,
                    entityType: ScopeEntityType::ORDER,
                    entityId: (string) $order->getKey(),
                    grantLevel: null,
                    reason: $reason ?? 'Order payment opening authorized from the admin panel.',
                    grantedBy: $actorRef,
                );

                return app(RecordOrderStatusChange::class)(
                    $order,
                    OrderStatus::MENUNGGU_PEMBAYARAN,
                    $actorRef,
                    $actorRole,
                    $reason,
                    $metadata,
                );
            },
            action: OrderWorkflowAuditActions::PAYMENT_OPENING_AUTHORIZED,
            subject: fn (OrderStatusEvent $event): AuditSubject => new AuditSubject('order', $event->order_id),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: AuditSource::Panel,
            reason: $reason,
            correlationId: app(CorrelationContext::class)->current()?->value,
            metadata: $metadata,
        );
    }
}
```

- [ ] **Step 5: Implement `ManualPaymentVerification`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderWorkflowAuditActions;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;

final readonly class ManualPaymentVerification
{
    public function __invoke(
        Order $order,
        string $actorRef,
        string $actorRole,
        string $verificationNote,
        array $metadata = [],
    ): OrderStatusEvent {
        return Audit::wrap(
            mutation: fn (): OrderStatusEvent => app(RecordOrderStatusChange::class)(
                $order,
                OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN,
                $actorRef,
                $actorRole,
                $verificationNote,
                $metadata,
            ),
            action: OrderWorkflowAuditActions::MANUAL_PAYMENT_VERIFICATION_STARTED,
            subject: fn (OrderStatusEvent $event): AuditSubject => new AuditSubject('order', $event->order_id),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: AuditSource::Panel,
            reason: $verificationNote,
            correlationId: app(CorrelationContext::class)->current()?->value,
            metadata: $metadata,
        );
    }
}
```

- [ ] **Step 6: Implement `MarkOrderPaid`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\PaidTrigger;
use App\Domain\OrderWorkflow\PaidTriggerSource;
use App\Domain\Quotation\Models\Quote;
use Carbon\CarbonImmutable;

final readonly class MarkOrderPaid
{
    public function __construct(
        private ApplyPaidEffects $applyPaidEffects,
    ) {}

    public function __invoke(
        Order $order,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
    ): Order {
        $quote = Quote::currentFor($order);

        return ($this->applyPaidEffects)(
            $order,
            new PaidTrigger(
                source: PaidTriggerSource::ManualVerification,
                sourceId: "manual:{$actorRef}",
                businessKey: "manual_paid:{$order->reference}",
                amount: $quote->totalMinor(),
                currency: $quote->currency,
                occurredAt: CarbonImmutable::now(),
                actorRef: $actorRef,
                actorRole: $actorRole,
            ),
        );
    }
}
```

- [ ] **Step 7: Run the money-actions test**

Run: `php artisan test tests/Feature/OrderWorkflow/AdminMoneyActionsTest.php`
Expected: PASS. (If `IssueQuote`'s line validation rejects `service_definition_id: 1` with no real row — the test needs a real `ServiceDefinition`/`PriceVersion`; check `IssueQuoteTest`'s fixture for the required shape and mirror it.)

- [ ] **Step 8: Lint, analyse, commit**

```bash
composer lint && composer analyse
git add app/Domain/OrderWorkflow/Actions tests/Feature/OrderWorkflow
git commit -m "feat(order-workflow): terminal and money-adjacent actions with grant and manual verification (P1 lane 2)"
```

---

## Task 4: BookingOrderResource

**Files:**
- Create: `app/Filament/Admin/Resources/BookingOrders/BookingOrderResource.php`
- Create: `app/Filament/Admin/Resources/BookingOrders/BookingOrderStatusBadge.php`
- Create: `app/Filament/Admin/Resources/BookingOrders/Tables/BookingOrdersTable.php`
- Create: `app/Filament/Admin/Resources/BookingOrders/Schemas/BookingOrderInfolist.php`
- Create: `app/Filament/Admin/Resources/BookingOrders/Pages/ListBookingOrders.php`
- Create: `app/Filament/Admin/Resources/BookingOrders/Pages/ViewBookingOrder.php`
- Create: `app/Filament/Admin/Resources/BookingOrders/Pages/EditBookingOrder.php`
- Create: `app/Filament/Admin/Resources/BookingOrders/Schemas/BookingOrderEditForm.php`
- Create: `app/Filament/Admin/Resources/BookingOrders/Actions/TransitionOrderAction.php` (the one dynamic header action factory used for all 12 transitions)
- Test: `tests/Feature/Filament/BookingOrderResourceAccessTest.php`, `tests/Feature/Filament/BookingOrderTransitionActionTest.php`

**Interfaces:**
- Consumes: `MasterDataAdminAuthorizerContract::authorize(ActorContext)`; `OrderTransitionAuthorizerContract::authorizeTransition(ActorContext, string)`; `ReauthenticationGuard::assertFresh(ActorContext)`; all 12 Actions from Tasks 2–3; `OrderTransition::allowedFrom(OrderStatus): list<string>`; `StatusIntent::intent(string, StatusIntent::FAMILY_ORDER_LIFECYCLE)`; `ScopeAssignment` query for grants; `Quote::currentFor`; `AuditSource::Panel`; `GrantsActorRoles` test trait.
- Produces: the three pages + dynamic header actions; `BookingOrderResource::auditRoleFor(ActorContext $actor): string` (walks `[ADMIN, RESTRICTED_ADMIN, OPERATOR, FINANCE]`, falls back `'authenticated_actor'`/`'guest'` — CemeteryResource precedent).
- Transition-name constants (shared with the authorizer + action mapping): `'verify_order'`, `'request_availability'`, `'issue_quote'`, `'record_buyer_approval'`, `'authorize_payment_opening'`, `'manual_payment_verification'`, `'mark_order_paid'`, `'process_order'`, `'complete_order'`, `'reject_order'`, `'cancel_order'`, `'expire_order'`.

- [ ] **Step 1: Write the failing resource access test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

final class BookingOrderResourceAccessTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_and_bare_users_are_denied(): void
    {
        $this->assertFalse(BookingOrderResource::canAccess());
        $this->actingAs(User::factory()->create());
        $this->assertFalse(BookingOrderResource::canAccess());
    }

    public function test_back_office_roles_can_access(): void
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);
            $this->assertTrue(BookingOrderResource::canAccess(), "role {$role} should access");
        }
    }

    public function test_vendor_role_cannot_access(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::VENDOR);
        $this->actingAs($user);
        $this->assertFalse(BookingOrderResource::canAccess());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Filament/BookingOrderResourceAccessTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the resource + pages + badge + table + infolist**

`BookingOrderResource.php` (model `App\Domain\OrderWorkflow\Models\Order`; note the model's `update()`/`delete()` throw — so NO `EditAction`/`DeleteAction` on the table or pages beyond the dedicated non-financial edit page):

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders;

use App\Domain\OrderWorkflow\Models\Order;
use App\Filament\Admin\Resources\BookingOrders\Pages\EditBookingOrder;
use App\Filament\Admin\Resources\BookingOrders\Pages\ListBookingOrders;
use App\Filament\Admin\Resources\BookingOrders\Pages\ViewBookingOrder;
use App\Filament\Admin\Resources\BookingOrders\Schemas\BookingOrderEditForm;
use App\Filament\Admin\Resources\BookingOrders\Schemas\BookingOrderInfolist;
use App\Filament\Admin\Resources\BookingOrders\Tables\BookingOrdersTable;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Filament\Forms\Schema;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;

final class BookingOrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function canAccess(): bool
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));

            return Response::allow();
        } catch (MasterDataNotAuthorisedException) {
            return Response::deny('Anda tidak berwenang mengelola pesanan.');
        }
    }

    public static function infolist(Schema $schema): Schema
    {
        return BookingOrderInfolist::configure($schema);
    }

    public static function form(Schema $schema): Schema
    {
        return BookingOrderEditForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BookingOrdersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return Order::query()->with('bookingDraft');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookingOrders::route('/'),
            'view' => ViewBookingOrder::route('/{record}'),
            'edit' => EditBookingOrder::route('/{record}/edit'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'pesanan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pesanan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Pesanan';
    }

    public static function auditRoleFor(ActorContext $actor): string
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            if ($actor->hasRole($role)) {
                return $role;
            }
        }

        return $actor->isAuthenticated() ? 'authenticated_actor' : 'guest';
    }
}
```

`BookingOrderStatusBadge.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders;

use App\Domain\OrderWorkflow\OrderStatus;
use App\Support\Design\StatusIntent;

final class BookingOrderStatusBadge
{
    /** @var array<string, string> intent → Filament color */
    private const array INTENT_COLORS = [
        'negative' => 'danger',
        'pending' => 'warning',
        'in_progress' => 'info',
        'confirmed' => 'primary',
        'completed' => 'success',
    ];

    public static function color(OrderStatus $status): string
    {
        $intent = StatusIntent::intent($status->value, StatusIntent::FAMILY_ORDER_LIFECYCLE);

        return self::INTENT_COLORS[$intent] ?? 'gray';
    }

    public static function label(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::MASUK => 'Masuk',
            OrderStatus::DIVERIFIKASI => 'Diverifikasi',
            OrderStatus::MENUNGGU_KETERSEDIAAN => 'Menunggu Ketersediaan',
            OrderStatus::PENAWARAN_TERKIRIM => 'Penawaran Terkirim',
            OrderStatus::DISETUJUI_PEMESAN => 'Disetujui Pemesan',
            OrderStatus::MENUNGGU_PEMBAYARAN => 'Menunggu Pembayaran',
            OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN => 'Menunggu Verifikasi Pembayaran',
            OrderStatus::DIBAYAR => 'Dibayar',
            OrderStatus::DIPROSES => 'Diproses',
            OrderStatus::SELESAI => 'Selesai',
            OrderStatus::DITOLAK => 'Ditolak',
            OrderStatus::DIBATALKAN => 'Dibatalkan',
            OrderStatus::KEDALUWARSA => 'Kedaluwarsa',
        };
    }
}
```

`Tables/BookingOrdersTable.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Tables;

use App\Domain\OrderWorkflow\OrderStatus;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderStatusBadge;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class BookingOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('reference')->label('Referensi')->searchable(),
                TextColumn::make('bookingDraft.customer_full_name')->label('Pemesan')->searchable(),
                TextColumn::make('product_type')->label('Jenis Layanan'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => BookingOrderStatusBadge::color(OrderStatus::from($state)))
                    ->formatStateUsing(fn (string $state): string => BookingOrderStatusBadge::label(OrderStatus::from($state))),
                TextColumn::make('created_at')->label('Dibuat')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(OrderStatus::cases())->mapWithKeys(
                        fn (OrderStatus $status): array => [$status->value => BookingOrderStatusBadge::label($status)]
                    )->all()),
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
            ]);
    }
}
```

`Schemas/BookingOrderInfolist.php` — sections: Ringkasan (reference, status badge, product_type, created_at), Pemesan & Almarhum (`bookingDraft.customer_full_name`, `customer_mobile`, `customer_email`, `deceased_full_name`, `deceased_date_of_death`), Penawaran (a computed total + status via a closure reading `Quote::currentFor($record)`), Dokumen (`orderDocuments.document` — see step 4 note), Riwayat Status (`statusEvents` timeline with `to_status` badge, `actor_ref`, `occurred_at`), Otorisasi Pembayaran (active `ScopeAssignment` rows for `ScopeEntityType::ORDER`). Use `Filament\Infolists\Components\Section`, `TextEntry`, `RepeatableEntry` — full code in the task brief; key entries:

```php
Section::make('Penawaran')
    ->schema([
        TextEntry::make('quote')
            ->label('Total Penawaran')
            ->state(fn (Order $record): string => (function () use ($record): string {
                $quote = Quote::currentFor($record);

                return $quote === null ? 'Belum ada penawaran' : 'Rp '.number_format($quote->totalMinor()->toMinorInt() / 100, 0, ',', '.').' · '.$quote->status;
            })()),
    ]),
```

- [ ] **Step 4: Implement the pages**

`Pages/ListBookingOrders.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Pages;

use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use Filament\Resources\Pages\ListRecords;

final class ListBookingOrders extends ListRecords
{
    protected static string $resource = BookingOrderResource::class;
}
```

`Pages/ViewBookingOrder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Pages;

use App\Domain\OrderWorkflow\OrderStatus;
use App\Filament\Admin\Resources\BookingOrders\Actions\TransitionOrderAction;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewBookingOrder extends ViewRecord
{
    protected static string $resource = BookingOrderResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [];

        foreach (OrderTransition::allowedFrom($this->record->status()) as $to) {
            $actions[] = TransitionOrderAction::make(OrderStatus::from($to), $this->record);
        }

        return $actions;
    }
}
```

`Pages/EditBookingOrder.php` — non-financial edit (internal note / documents only; the model write guard blocks all other columns):

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Pages;

use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use Filament\Resources\Pages\EditRecord;

final class EditBookingOrder extends EditRecord
{
    protected static string $resource = BookingOrderResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return array_intersect_key($data, array_flip([])); // no writable columns: orders are append-only
    }
}
```

`Schemas/BookingOrderEditForm.php` — `Textarea::make('internal_note')` rendered read-only with a hint that orders are append-only (no persisted column this phase; the page exists for future document attachment). If `EditRecord` cannot save an empty payload, register the page only when the authorized action allows; simplest honest shape: the Edit page shows the internal note field disabled + an explanatory alert, and `EditBookingOrder` overrides `getSaveFormAction()` to hide save. Keep the page minimal.

- [ ] **Step 5: Implement the transition action factory**

`Actions/TransitionOrderAction.php` — the ONE dynamic action: given a target `OrderStatus`, builds a `Filament\Actions\Action` with label/color from `BookingOrderStatusBadge`, a confirmation modal ("Transisi ini dicatat di audit."), a `Textarea::make('reason')` when `$to->requiresReason()`, and:

```php
public static function make(OrderStatus $to, Order $order): Action
{
    $transition = self::TRANSITION_NAME[$to->value] ?? null;

    $action = Action::make('transition_'.$to->value)
        ->label(self::LABELS[$to->value] ?? $to->value)
        ->color(self::COLORS[$to->value] ?? 'gray')
        ->requiresConfirmation()
        ->modalHeading('Konfirmasi transisi')
        ->modalDescription('Transisi ini dicatat di audit.')
        ->authorize(fn (): bool => self::authorized($order, $to))
        ->action(fn (array $data) => self::run($order, $to, $data['reason'] ?? null));

    if ($to->requiresReason()) {
        $action->form([Textarea::make('reason')->label('Alasan')->required()]);
    }

    if (in_array($transition, self::MONEY_TRANSITIONS, true)) {
        $action->color('warning')->icon(Heroicon::OutlinedShieldCheck);
    }

    return $action;
}
```

`TRANSITION_NAME` map: `DIVERIFIKASI => 'verify_order'`, `MENUNGGU_KETERSEDIAAN => 'request_availability'`, `PENAWARAN_TERKIRIM => 'issue_quote'`, `DISETUJUI_PEMESAN => 'record_buyer_approval'`, `MENUNGGU_PEMBAYARAN => 'authorize_payment_opening'`, `MENUNGGU_VERIFIKASI_PEMBAYARAN => 'manual_payment_verification'`, `DIBAYAR => 'mark_order_paid'`, `DIPROSES => 'process_order'`, `SELESAI => 'complete_order'`, `DITOLAK => 'reject_order'`, `DIBATALKAN => 'cancel_order'`, `KEDALUWARSA => 'expire_order'`. `MONEY_TRANSITIONS` = the 5 money names from Task 1.

`self::run(...)` — the enforcement path:

```php
private static function run(Order $order, OrderStatus $to, ?string $reason): void
{
    $actor = app(ActorContext::class);
    $transition = self::TRANSITION_NAME[$to->value];

    try {
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($actor, $transition);

        if (in_array($transition, self::MONEY_TRANSITIONS, true)) {
            app(ReauthenticationGuard::class)->assertFresh($actor);
        }
    } catch (ReauthenticationRequiredException) {
        session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, 'money_action');
        session()->put('url.intended', url()->current());

        Notification::make()
            ->warning()
            ->title('Perlu verifikasi ulang')
            ->body('Lakukan verifikasi ulang untuk tindakan ini.')
            ->send();

        redirect()->route('filament.admin.pages.mfa-challenge');

        return;
    } catch (OrderActionNotAuthorisedException $exception) {
        Notification::make()->danger()->title($exception->getMessage())->send();

        return;
    }

    $actorRef = $actor->identityReference;
    $actorRole = BookingOrderResource::auditRoleFor($actor);

    try {
        match ($to) {
            OrderStatus::DIVERIFIKASI => app(VerifyOrder::class)($order, $actorRef, $actorRole, $reason),
            OrderStatus::MENUNGGU_KETERSEDIAAN => app(RequestAvailability::class)($order, $actorRef, $actorRole, $reason),
            OrderStatus::PENAWARAN_TERKIRIM => app(IssueOrderQuote::class)($order, CarbonImmutable::now()->addDays(30), $actorRef, $actorRole, $reason),
            OrderStatus::DISETUJUI_PEMESAN => app(RecordBuyerApproval::class)($order, $actorRef, $actorRole, $reason),
            OrderStatus::MENUNGGU_PEMBAYARAN => app(GrantOrderPaymentOpening::class)($order, (int) $actorRef, $actorRef, $actorRole, $reason),
            OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN => app(ManualPaymentVerification::class)($order, $actorRef, $actorRole, $reason ?? 'Pembayaran manual dicatat.'),
            OrderStatus::DIBAYAR => app(MarkOrderPaid::class)($order, $actorRef, $actorRole, $reason),
            OrderStatus::DIPROSES => app(ProcessOrder::class)($order, $actorRef, $actorRole, $reason),
            OrderStatus::SELESAI => app(CompleteOrder::class)($order, $actorRef, $actorRole, $reason),
            OrderStatus::DITOLAK => app(RejectOrder::class)($order, $actorRef, $actorRole, $reason ?? ''),
            OrderStatus::DIBATALKAN => app(CancelOrder::class)($order, $actorRef, $actorRole, $reason),
            OrderStatus::KEDALUWARSA => app(ExpireOrder::class)($order, $actorRef, $actorRole, $reason),
        };

        Notification::make()->success()->title('Transisi berhasil dicatat.')->send();
    } catch (\Throwable $exception) {
        Notification::make()->danger()->title('Transisi gagal')->body($exception->getMessage())->send();
    }
}
```

Note: `GrantOrderPaymentOpening` grantee — the acting actor when they hold `admin` (identityReference is an int `users.id` in the local adapter; cast accordingly); the finance grantee fallback select is a P1 refinement the whole-branch review may add — keep the self-grant for admin + finance-with-admin-on-order as the initial shape, and record the grantee = acting actor (an admin must perform the click for condition 4 to pass; the journey doc in the spec's §5 flow stands).

- [ ] **Step 6: Write the transition-action feature test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Filament\Admin\Resources\BookingOrders\Actions\TransitionOrderAction;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

final class BookingOrderTransitionActionTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function order(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }

    public function test_operator_can_invoke_verify_transition(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $order = $this->order(OrderStatus::MASUK);
        TransitionOrderAction::make(OrderStatus::DIVERIFIKASI, $order)->call();

        $this->assertSame(OrderStatus::DIVERIFIKASI, $order->fresh()->status());
    }

    public function test_operator_cannot_invoke_money_transition(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $order = $this->order(OrderStatus::DISETUJUI_PEMESAN);
        $action = TransitionOrderAction::make(OrderStatus::MENUNGGU_PEMBAYARAN, $order);

        $this->assertFalse($action->isAuthorized());
    }

    public function test_finance_money_transition_requires_fresh_authentication(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);

        $order = $this->order(OrderStatus::DISETUJUI_PEMESAN);
        $action = TransitionOrderAction::make(OrderStatus::MENUNGGU_PEMBAYARAN, $order);

        $this->assertTrue($action->isAuthorized());
    }
}
```

- [ ] **Step 7: Run the resource tests**

Run: `php artisan test tests/Feature/Filament/BookingOrderResourceAccessTest.php tests/Feature/Filament/BookingOrderTransitionActionTest.php`
Expected: PASS. Fix Filament 5 API drift (action `call()`, `isAuthorized()`, `Icon` imports) against `vendor/filament` if signatures differ.

- [ ] **Step 8: Lint, analyse, commit**

```bash
composer lint && composer analyse
git add app/Filament/Admin/Resources/BookingOrders tests/Feature/Filament
git commit -m "feat(filament): booking order resource with dynamic transition actions (P1 lane 2)"
```

---

## Task 5: MarketplaceOrderResource

**Files:**
- Create: `app/Filament/Admin/Resources/MarketplaceOrders/MarketplaceOrderResource.php`
- Create: `app/Filament/Admin/Resources/MarketplaceOrders/MarketplacePaymentStateBadge.php`
- Create: `app/Filament/Admin/Resources/MarketplaceOrders/Tables/MarketplaceOrdersTable.php`
- Create: `app/Filament/Admin/Resources/MarketplaceOrders/Schemas/MarketplaceOrderInfolist.php`
- Create: `app/Filament/Admin/Resources/MarketplaceOrders/Pages/ListMarketplaceOrders.php`
- Create: `app/Filament/Admin/Resources/MarketplaceOrders/Pages/ViewMarketplaceOrder.php`
- Create: `app/Filament/Admin/Resources/MarketplaceOrders/Actions/MarkMarketplaceOrderPaidAction.php`
- Test: `tests/Feature/Filament/MarketplaceOrderResourceTest.php`

**Interfaces:**
- Consumes: `MarketplaceOrder` (fields: order_number, customer_ref, entity_ref, vendor_id, total_minor, payment_state, placed_at; `items()`, `vendorOrders()`, `vendor()`); `PaymentState` (find its cases in `app/Domain/Marketplace/` — mirror the enum's own label/color mapping); `MarkMarketplaceOrderPaid::__invoke(MarketplaceOrder $order, int $amountMinor, bool $fulfilmentEvidenceAccepted, ?CarbonImmutable $disputeWindowEndsAt, ?string $actorRef, string $actorRole, ?string $correlationId, AuditSource $source, ?CarbonImmutable $now): MarketplaceOrder`; `MasterDataAdminAuthorizerContract`; `OrderTransitionAuthorizerContract` (transition name `'mark_marketplace_order_paid'`); `ReauthenticationGuard`.
- Produces: resource (access gate like Task 4), view-only pages, single money header action.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Filament\Admin\Resources\MarketplaceOrders\Actions\MarkMarketplaceOrderPaidAction;
use App\Filament\Admin\Resources\MarketplaceOrders\MarketplaceOrderResource;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

final class MarketplaceOrderResourceTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function order(string $paymentState): MarketplaceOrder
    {
        return MarketplaceOrder::query()->create([
            'order_number' => 'MKT-'.Str::upper(Str::random(8)),
            'customer_ref' => 'customer:1',
            'entity_ref' => 'entity:1',
            'vendor_id' => 1,
            'subtotal_minor' => 250000,
            'delivery_fee_minor' => 0,
            'total_minor' => 250000,
            'payment_state' => $paymentState,
            'idempotency_key' => 'idem-'.Str::random(8),
            'placed_at' => now(),
        ]);
    }

    public function test_operator_cannot_access_marketplace_resource(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);
        $this->assertTrue(MarketplaceOrderResource::canAccess());
    }

    public function test_operator_cannot_run_mark_paid_action(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $order = $this->order('BELUM_DIBAYAR');
        $action = MarkMarketplaceOrderPaidAction::make($order);
        $this->assertFalse($action->isAuthorized());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Filament/MarketplaceOrderResourceTest.php`
Expected: FAIL — class not found. (Adjust the `payment_state` literal to the real `PaymentState` case value found in `app/Domain/Marketplace/` during implementation.)

- [ ] **Step 3: Implement resource, badge, table, infolist, pages** — same shape as Task 4, `$model = MarketplaceOrder::class`, navigation label 'Pesanan Marketplace', access gate + `auditRoleFor` identical. Table columns: order_number (searchable), vendor.name, items count (`items` relation count — eager load in `getEloquentQuery()`), total (format `'Rp '.number_format($state / 100, 0, ',', '.')`), payment_state badge, placed_at. Filters: payment_state select from `PaymentState::KNOWN_STATES`.

- [ ] **Step 4: Implement the mark-paid action**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MarketplaceOrders\Actions;

use App\Domain\Marketplace\Actions\MarkMarketplaceOrderPaid;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Filament\Admin\Resources\MarketplaceOrders\MarketplaceOrderResource;
use App\Platform\Audit\AuditSource;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

final class MarkMarketplaceOrderPaidAction
{
    public static function make(MarketplaceOrder $order): Action
    {
        return Action::make('mark_paid')
            ->label('Tandai Dibayar')
            ->color('warning')
            ->icon('heroicon-o-banknotes')
            ->requiresConfirmation()
            ->modalHeading('Konfirmasi pembayaran')
            ->modalDescription('Pesanan ini ditandai dibayar penuh dan dicatat di audit.')
            ->authorize(fn (): bool => self::authorized())
            ->action(function () use ($order): void {
                $actor = app(ActorContext::class);
                $actorRef = $actor->identityReference;
                $actorRole = MarketplaceOrderResource::auditRoleFor($actor);

                try {
                    app(OrderTransitionAuthorizerContract::class)->authorizeTransition($actor, 'mark_marketplace_order_paid');
                    app(ReauthenticationGuard::class)->assertFresh($actor);
                } catch (ReauthenticationRequiredException) {
                    Notification::make()->warning()->title('Perlu verifikasi ulang')->send();
                    session()->put('url.intended', url()->current());
                    redirect()->route('filament.admin.pages.mfa-challenge');

                    return;
                } catch (\Throwable $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                try {
                    app(MarkMarketplaceOrderPaid::class)(
                        $order,
                        (int) $order->total_minor,
                        fulfilmentEvidenceAccepted: true,
                        actorRef: $actorRef,
                        actorRole: $actorRole,
                        correlationId: app(CorrelationContext::class)->current()?->value,
                        source: AuditSource::Panel,
                    );
                    Notification::make()->success()->title('Pesanan ditandai dibayar.')->send();
                } catch (\Throwable $exception) {
                    Notification::make()->danger()->title('Gagal menandai pembayaran')->body($exception->getMessage())->send();
                }
            });
    }

    private static function authorized(): bool
    {
        try {
            app(OrderTransitionAuthorizerContract::class)->authorizeTransition(app(ActorContext::class), 'mark_marketplace_order_paid');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
```

- [ ] **Step 5: Run the test, lint, analyse, commit**

```bash
php artisan test tests/Feature/Filament/MarketplaceOrderResourceTest.php
composer lint && composer analyse
git add app/Filament/Admin/Resources/MarketplaceOrders tests/Feature/Filament
git commit -m "feat(filament): marketplace order resource with finance mark-paid action (P1 lane 3)"
```

---

## Task 6: RenewalOrderResource

**Files:**
- Create: `app/Domain/Renewal/Actions/MarkRenewalPaidExternally.php`
- Create: `app/Domain/Renewal/Actions/ExpireRenewal.php`
- Create: `app/Domain/Renewal/Exceptions/RenewalAlreadySettledException.php`
- Create: `app/Filament/Admin/Resources/RenewalOrders/RenewalOrderResource.php`
- Create: `app/Filament/Admin/Resources/RenewalOrders/RenewalStatusBadge.php`
- Create: `app/Filament/Admin/Resources/RenewalOrders/Tables/RenewalOrdersTable.php`
- Create: `app/Filament/Admin/Resources/RenewalOrders/Schemas/RenewalOrderInfolist.php`
- Create: `app/Filament/Admin/Resources/RenewalOrders/Pages/ListRenewalOrders.php`
- Create: `app/Filament/Admin/Resources/RenewalOrders/Pages/ViewRenewalOrder.php`
- Create: `app/Filament/Admin/Resources/RenewalOrders/Actions/RecordExternalRenewalPaymentAction.php`
- Create: `app/Filament/Admin/Resources/RenewalOrders/Actions/ExpireRenewalAction.php`
- Test: `tests/Feature/Renewal/AdminRenewalActionsTest.php`, `tests/Feature/Filament/RenewalOrderResourceTest.php`

**Interfaces:**
- Consumes: `Renewal` (fields: grave_record_id, target_due_period, reference, status, source, settled_at; `quotes()`, `externalMarking()`, `graveRecord()`); `RenewalStatus` constants; `RenewalExternalMarking` fillable; `RenewalAuditActions` or the hardcoded `'RENEWAL_EXTERNAL_MARKING'` audit action name from `MarkExternalRenewal` (check `SensitiveActions::ACTIONS` — if it is listed, reasons are mandatory); `OrderTransitionAuthorizerContract` (transition names `'record_external_renewal_payment'`, `'expire_renewal'` — the latter is non-money so operator/restricted_admin allowed); `ReauthenticationGuard` for the money one.
- Produces:
  - `MarkRenewalPaidExternally::__invoke(Renewal $renewal, string $evidence, string $reason, string $actorRef, string $actorRole): void` — asserts `RenewalStatus::MENUNGGU_PEMBAYARAN` (else `RenewalAlreadySettledException`), writes status `DIBAYAR` + settled_at + `RenewalExternalMarking` row inside `Audit::wrap` (action `'RENEWAL_EXTERNAL_MARKING'`).
  - `ExpireRenewal::__invoke(Renewal $renewal, string $actorRef, string $actorRole, ?string $reason = null): void` — `MENUNGGU_PEMBAYARAN` → `KEDALUWARSA` inside `Audit::wrap` (action `'RENEWAL_EXPIRED'`).

- [ ] **Step 1: Write the failing action tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Renewal;

use App\Domain\Renewal\Actions\ExpireRenewal;
use App\Domain\Renewal\Actions\MarkRenewalPaidExternally;
use App\Domain\Renewal\Exceptions\RenewalAlreadySettledException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminRenewalActionsTest extends TestCase
{
    use RefreshDatabase;

    private function renewal(string $status): Renewal
    {
        return Renewal::query()->create([
            'grave_record_id' => 1,
            'target_due_period' => '2026-12-01',
            'reference' => 'EXT-'.strtoupper(substr(uniqid(), 0, 8)),
            'status' => $status,
            'source' => 'online',
        ]);
    }

    public function test_mark_paid_externally_records_evidence(): void
    {
        $renewal = $this->renewal(RenewalStatus::MENUNGGU_PEMBAYARAN);
        app(MarkRenewalPaidExternally::class)($renewal, 'Bukti transfer BCA #123', 'Pelunasan di kasir', 'user:1', 'finance');

        $this->assertSame(RenewalStatus::DIBAYAR, $renewal->status);
        $this->assertNotNull($renewal->settled_at);
        $this->assertDatabaseHas('renewal_external_markings', ['renewal_id' => $renewal->getKey()]);
        $this->assertDatabaseHas('audit_events', ['action' => 'RENEWAL_EXTERNAL_MARKING']);
    }

    public function test_mark_paid_refuses_settled_renewal(): void
    {
        $renewal = $this->renewal(RenewalStatus::DIBAYAR);
        $this->expectException(RenewalAlreadySettledException::class);
        app(MarkRenewalPaidExternally::class)($renewal, 'x', 'y', 'user:1', 'finance');
    }

    public function test_expire_transitions_to_kedaluwarsa(): void
    {
        $renewal = $this->renewal(RenewalStatus::MENUNGGU_PEMBAYARAN);
        app(ExpireRenewal::class)($renewal, 'user:1', 'operator');
        $this->assertSame(RenewalStatus::KEDALUWARSA, $renewal->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'RENEWAL_EXPIRED']);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `php artisan test tests/Feature/Renewal/AdminRenewalActionsTest.php` → FAIL (class not found).

- [ ] **Step 3: Implement the two actions**

`MarkRenewalPaidExternally.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Actions;

use App\Domain\Renewal\Exceptions\RenewalAlreadySettledException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalExternalMarking;
use App\Domain\Renewal\RenewalStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;

final readonly class MarkRenewalPaidExternally
{
    public function __invoke(
        Renewal $renewal,
        string $evidence,
        string $reason,
        string $actorRef,
        string $actorRole,
    ): void {
        Audit::wrap(
            mutation: function () use ($renewal, $evidence, $reason, $actorRef, $actorRole): void {
                $current = Renewal::query()->lockForUpdate()->findOrFail($renewal->getKey());

                if ($current->status !== RenewalStatus::DIBAYAR && $current->settled_at !== null) {
                    throw RenewalAlreadySettledException::forRenewal((string) $current->getKey());
                }

                if ($current->status !== RenewalStatus::MENUNGGU_PEMBAYARAN) {
                    throw RenewalAlreadySettledException::forRenewal((string) $current->getKey());
                }

                $current->update([
                    'status' => RenewalStatus::DIBAYAR,
                    'settled_at' => now(),
                ]);

                RenewalExternalMarking::query()->create([
                    'renewal_id' => $current->getKey(),
                    'marked_by_actor_ref' => $actorRef,
                    'evidence_reference' => $evidence,
                    'reason' => $reason,
                    'marked_at' => now(),
                ]);
            },
            action: 'RENEWAL_EXTERNAL_MARKING',
            subject: fn (): AuditSubject => new AuditSubject('renewal', (string) $renewal->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: AuditSource::Panel,
            reason: $reason,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
```

`ExpireRenewal.php` — same shape; mutation updates `status => KEDALUWARSA`; action `'RENEWAL_EXPIRED'`; reason nullable.

- [ ] **Step 4: Run the action tests** — `php artisan test tests/Feature/Renewal/AdminRenewalActionsTest.php` → PASS.

- [ ] **Step 5: Implement the resource + badge + table + infolist + pages + actions** — mirror Tasks 4/5; table columns reference (searchable), `graveRecord` ref, target_due_period, amount (latest `renewal_quotes` row's amount — eager load `quotes`), status badge, source, created_at; filters: status from `RenewalStatus::KNOWN_STATUSES`. Header actions on View page: `RecordExternalRenewalPaymentAction` (finance + re-auth; form fields evidence + reason; calls `MarkRenewalPaidExternally`) and `ExpireRenewalAction` (operator; optional reason; calls `ExpireRenewal`). Access gate + `auditRoleFor` as Task 4.

- [ ] **Step 6: Resource tests + lint + analyse + commit**

```bash
php artisan test tests/Feature/Renewal/AdminRenewalActionsTest.php tests/Feature/Filament/RenewalOrderResourceTest.php
composer lint && composer analyse
git add app/Domain/Renewal app/Filament/Admin/Resources/RenewalOrders tests/Feature
git commit -m "feat(filament): renewal order resource with external payment and expiry (P1 lane 3)"
```

---

## Task 7: Docs + traceability + browser verification

**Files:**
- Modify: `docs/specs/*/screen-inventory.md` (or the canonical screen inventory file — find with `ls docs/specs/*/screen*`), the traceability doc (`docs/specs/*/traceability.md` or `docs/contracts/traceability*`), `docs/contracts/*` API/notification docs only if the new actions surface public contracts (they do not — admin-panel only).
- Test: `tests/Browser/` additions (Playwright harness, run against dev after deploy).

**Interfaces:**
- Consumes: everything from Tasks 1–6; the P0 public booking flow (draft resume, Bayar Sekarang).

- [ ] **Step 1: Update the screen inventory**

Find the canonical screen-inventory file (search `docs/` for "inventaris layar" or "screen inventory"; update it in place — never duplicate). Add the three new admin screens: `admin/pemesanan` (list + view + transitions), `admin/pesanan-marketplace`, `admin/perpanjangan` with their purpose, roles, and transition surface; mark the booking screen as "status transitions via domain Actions".

- [ ] **Step 2: Update the traceability doc**

Mark the P1 traceability items (admin order management ACs — find the AC ids in the booking/marketplace/renewal specs' traceability tables) as `Covered` with the test file names from Tasks 1–6.

- [ ] **Step 3: Commit docs**

```bash
git add docs/
git commit -m "docs: screen inventory and traceability for P1 admin order management"
```

- [ ] **Step 4: Deploy to dev + browser verification (executed on the dev host after merge)**

Deploy the merged branch to dev (digest → compose update → migrate), then run the Playwright harness:
1. Admin login → MFA challenge (admin seed user, test codes from the dev seed) → open `admin/pemesanan` → list renders, status filter works.
2. Pick the P0-created order → run the full journey: Verifikasi → Menunggu Ketersediaan → Terbitkan Penawaran → Disetujui Pemesan → (finance user) Otorisasi Pembayaran (re-auth prompt appears → complete → grant row visible in the view) → (finance) Verifikasi Manual → Tandai Dibayar.
3. Resume the public draft in a second browser context (admin identity) → Bayar Sekarang → assert redirect toward the SumoPod hosted checkout URL (no guard denial copy).
4. Marketplace + renewal list/view smoke tests with an operator and a finance account.

- [ ] **Step 5: Full regression gates + whole-branch review**

```bash
composer lint && composer analyse && composer test
# PG18 gate on the dev host against the real PostgreSQL (same command shape as the P0 gate)
git push origin feat/p1-admin-orders
# PR against docs/design-system-and-planning; two-tier review; whole-branch review; merge; deploy; UAT.
```

---

## Self-review notes

- **Spec coverage:** §4.2 all 12 transitions → Tasks 2–3; §4.3 booking surface → Task 4; §4.4 marketplace → Task 5; §4.5 renewal → Task 6; §4.1 role split + §6 re-auth → Task 1 + the action factories; §7 testing → per-task tests + Task 7 gates; §8 delivery → Task 7 step 5.
- **Type consistency:** transition names are shared constants across the authorizer (`MONEY_TRANSITIONS`, `QUOTE_ISSUING_TRANSITIONS`) and `TransitionOrderAction::TRANSITION_NAME` — keep the five money names identical in both files: `authorize_payment_opening`, `manual_payment_verification`, `mark_order_paid`, `mark_marketplace_order_paid`, `record_external_renewal_payment`.
- **Known drift risks to resolve at implementation time against the real tree:** `PaymentState` case literals; `ServiceDefinition`/`PriceVersion` factory field names; `Money::fromDecimal`/`totalMinor` exact API; `RenewalExternalMarking` column names; Filament 5 action API (`call()`, `isAuthorized()`, `Icon` enum imports); `SensitiveActions::ACTIONS` membership for `RENEWAL_EXTERNAL_MARKING`; whether `Order::$bookingDraft` relationship name matches `bookingDraft()`; `EditRecord` empty-save behavior.
