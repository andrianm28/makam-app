# Pre-Demo Known-Gap Closure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close 5 concrete, already-flagged pre-demo gaps: a real complaint-resolution admin workflow (including a "resolve with make-good" action), marketplace listing details surfaced to shoppers, an `/akun` E2E browser suite, booking-wizard loading-state consistency, and 2 stale-documentation corrections.

**Architecture:** Extends existing domain modules (`VendorFulfillment`, `Marketplace`) with new Actions/relations following each module's own established `Audit::wrap()`/Filament-resource conventions exactly — no new subsystem, no new architectural pattern.

**Tech Stack:** Laravel 13, Filament 5, Livewire, PHPUnit (real Postgres 18), Playwright (written but not executable in this environment).

**Spec:** [docs/superpowers/specs/2026-09-04-pre-demo-known-gaps-design.md](../specs/2026-09-04-pre-demo-known-gaps-design.md)

## Global Constraints

- `declare(strict_types=1);` on every new/modified PHP file.
- New domain Actions in `VendorFulfillment` follow `FileComplaint`/`CreateMakeGood`'s exact `Audit::wrap()` shape — mutation closure, `action`, `subject`, `outcome`, `actorRef`, `actorRole`, `source`, `correlationId`. Never a raw Eloquent `update()` for a `ServiceComplaint` status transition.
- The new `ServiceComplaintsResource` mirrors `WorkOrdersResource`'s real file layout (`Resource.php`, `Pages/List*`, `Pages/View*`, `Actions/*Action.php`, `Tables/*Table.php`, `Schemas/*Infolist.php`, no create/edit form) and its exact `canAccess()`/`getAuthorizationResponse()` pattern via `MasterDataAdminAuthorizerContract` — real authorized roles: `ADMIN`, `RESTRICTED_ADMIN`, `OPERATOR`, `FINANCE` (confirmed against `MasterDataAdminAuthorizer::AUTHORISED_ROLES`, `app/Platform/IdentityAccess/MasterData/MasterDataAdminAuthorizer.php:39-44`).
- Do NOT touch `service_complaints.customer_id`'s `foreignUuid`-vs-`integer` type mismatch (confirmed real: the migration declares `foreignUuid('customer_id')` with no `.constrained()` call, while the model casts it `integer` and `FileComplaint` passes a plain `int` — `users.id` is a bigint PK). This is a real, pre-existing, separate defect. Flag it in Task 1's report; do not fix it.
- Do NOT touch `App\Domain\Marketplace\VendorProcessingStatus::KOMPLAIN` — a different, unrelated vendor-order status flag, confirmed to have zero connection to `service_complaints`.
- Real Postgres 18 (never SQLite) for every task's tests, via the pinned CI image `ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3` (host PHP 8.3.6 is below the app's required >=8.5):
  ```bash
  docker run --rm --network host --user 1000:1000 \
    -v /home/ubuntu/makam-app/.worktrees/pre-demo-known-gaps:/var/www/html -w /var/www/html \
    -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=55983 -e DB_DATABASE=makam_test -e DB_USERNAME=test -e DB_PASSWORD=test \
    -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=55984 \
    ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
    php -d memory_limit=1G vendor/bin/phpunit [optional path]
  ```
  Disposable containers `predemo-pg` (port 55983) / `predemo-redis` (port 55984) are already running — never the live `makam-nonprod-*` containers, and never the live beta database.
- `vendor/bin/pint --test` must stay clean throughout.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` (no file-path arguments — `phpstan.neon` scopes `paths: app` only; passing test-file arguments produces false-positive errors unrelated to real CI) must stay clean throughout.
- `bash ci/verify-docs.sh` must stay clean for any task touching `docs/`.
- Task 6 (the `/akun` Playwright suite) CANNOT be executed in this environment — no `node_modules`, no browser, confirmed absent. Its own task brief says so explicitly; the implementer verifies by reading the real, current `/akun` source (routes, Livewire classes, Blade views) rather than claiming an execution result that never happened. **Never report this as PASS.**

---

### Task 1: `make_good_order_id` migration, `ServiceComplaint` relations, `CreateMakeGood` audit-attribution extension

**Files:**
- Create: `database/migrations/2026_09_04_100000_add_make_good_order_id_to_service_complaints.php`
- Modify: `app/Domain/VendorFulfillment/Models/ServiceComplaint.php`
- Modify: `app/Domain/VendorFulfillment/Actions/CreateMakeGood.php`
- Test: `tests/Feature/Domain/VendorFulfillment/ComplaintFlowTest.php` (extend, don't duplicate)

**Interfaces:**
- Consumes: `MakeGoodOrder` (`app/Domain/VendorFulfillment/Models/MakeGoodOrder.php`, PK `id` uuid), `ServiceComplaint` (existing columns: `work_order_id`, `customer_id`, `complaint_text`, `status`, `resolution_notes`, `resolved_at`, `filed_at`).
- Produces: `ServiceComplaint::workOrder(): BelongsTo`, `ServiceComplaint::customer(): BelongsTo`, `ServiceComplaint.make_good_order_id` (nullable uuid column), `CreateMakeGood::__invoke(WorkOrder $originalWorkOrder, ?string $notes = null, ?string $actorRole = null, ?AuditSource $source = null, ?string $actorRef = null): MakeGoodOrder` — the 3 new trailing parameters default to `null`, and `null` for each maps to exactly today's hardcoded values (`'system'`, `AuditSource::Job`, `null`) so every existing call site needs zero changes.

- [ ] **Step 1: Write the failing test for the migration + relations**

```php
// Append to tests/Feature/Domain/VendorFulfillment/ComplaintFlowTest.php,
// inside the existing final class ComplaintFlowTest, after
// test_full_complaint_to_make_good_flow():

    public function test_service_complaint_has_a_nullable_make_good_order_id_column_and_real_relations(): void
    {
        $workOrder = $this->makeWorkOrder();

        $complaint = app(FileComplaint::class)(
            $workOrder,
            User::factory()->create()->id,
            'Grass was not trimmed.',
        );

        $this->assertNull($complaint->make_good_order_id);
        $this->assertTrue($complaint->workOrder()->exists());
        $this->assertSame((string) $workOrder->getKey(), (string) $complaint->workOrder->getKey());
        $this->assertTrue($complaint->customer()->exists());

        $makeGood = app(CreateMakeGood::class)($workOrder);

        $complaint->forceFill(['make_good_order_id' => $makeGood->getKey()])->save();

        $this->assertSame((string) $makeGood->getKey(), (string) $complaint->fresh()->make_good_order_id);
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
$RUN vendor/bin/phpunit tests/Feature/Domain/VendorFulfillment/ComplaintFlowTest.php --filter test_service_complaint_has_a_nullable_make_good_order_id_column_and_real_relations
```

Expected: FAIL — `make_good_order_id` column does not exist, `workOrder()`/`customer()` methods do not exist.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a resolved `service_complaints` row to the `make_good_orders` row
 * it caused, when one was created. Today filing a complaint and creating a
 * make-good are two Actions that both happen to key off the same
 * `WorkOrder`, with no queryable link between the two — this closes that
 * real, missing relationship (spec §1, "Schema change: link a resolved
 * complaint to its make-good"). Only `ResolveComplaint` (Task 2) ever
 * writes this column.
 *
 * No explicit `.constrained()`/FK constraint, matching this table's own
 * existing `work_order_id`/`customer_id` columns
 * (`2026_08_17_120040_create_service_complaints_table.php`) — neither of
 * those carries a DB-level FK constraint either, despite being declared
 * `foreignUuid`; this migration follows the same established (if
 * debatable) shape for consistency rather than introducing a new
 * constraint discipline mid-table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_complaints', function (Blueprint $table): void {
            $table->foreignUuid('make_good_order_id')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_complaints', function (Blueprint $table): void {
            $table->dropColumn('make_good_order_id');
        });
    }
};
```

- [ ] **Step 4: Add the two relations to `ServiceComplaint`**

Read `app/Domain/VendorFulfillment/Models/ServiceComplaint.php` in full first — add `use Illuminate\Database\Eloquent\Relations\BelongsTo;` and `use App\Domain\VendorFulfillment\Models\WorkOrder;` and `use App\Models\User;` to its imports, add `make_good_order_id` to `$fillable`, then add:

```php
    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
```

- [ ] **Step 5: Extend `CreateMakeGood`'s audit attribution**

Modify `app/Domain/VendorFulfillment/Actions/CreateMakeGood.php`'s `__invoke()` signature and its `Audit::wrap()` call:

```php
    public function __invoke(
        WorkOrder $originalWorkOrder,
        ?string $notes = null,
        ?string $actorRole = null,
        ?AuditSource $source = null,
        ?string $actorRef = null,
    ): MakeGoodOrder {
```

And change the closing `Audit::wrap()` arguments (everything else in the method body stays identical):

```php
            action: VendorFulfillmentAuditActions::MAKE_GOOD_CREATED,
            subject: new AuditSubject('work_order', $originalWorkOrder->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole ?? 'system',
            source: $source ?? AuditSource::Job,
            correlationId: app(CorrelationContext::class)->current()?->value,
```

- [ ] **Step 6: Run to verify the test passes**

```bash
$RUN vendor/bin/phpunit tests/Feature/Domain/VendorFulfillment/ComplaintFlowTest.php
```

Expected: PASS, all 4 tests (the 3 existing + the new one) — confirming the existing tests (which call `CreateMakeGood` with only 2 args) still pass unchanged, proving the new parameters are genuinely backward-compatible.

- [ ] **Step 7: Confirm `CareSubscriptionExampleData`'s existing `CreateMakeGood` usage (if any) needs no change**

```bash
grep -n "CreateMakeGood" app/Support/ExampleData/CareSubscriptionExampleData.php
```

If this returns no results, `CreateMakeGood` is only ever called via `FileComplaint`'s sibling flow or tests today — confirm and note this in the task report rather than assuming. If it does call `CreateMakeGood`, confirm the call still only passes 2 positional args and needs no change (the new params are trailing and optional).

- [ ] **Step 8: Run pint and phpstan**

```bash
$RUN vendor/bin/pint --test app/Domain/VendorFulfillment/Models/ServiceComplaint.php app/Domain/VendorFulfillment/Actions/CreateMakeGood.php database/migrations/2026_09_04_100000_add_make_good_order_id_to_service_complaints.php tests/Feature/Domain/VendorFulfillment/ComplaintFlowTest.php
$RUN php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

Expected: both clean.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_09_04_100000_add_make_good_order_id_to_service_complaints.php app/Domain/VendorFulfillment/Models/ServiceComplaint.php app/Domain/VendorFulfillment/Actions/CreateMakeGood.php tests/Feature/Domain/VendorFulfillment/ComplaintFlowTest.php
git commit -m "feat(vendor-fulfillment): link service complaints to their make-good order"
```

---

### Task 2: `StartInvestigatingComplaint`, `ResolveComplaint`, `DismissComplaint` domain Actions

**Files:**
- Create: `app/Domain/VendorFulfillment/Actions/StartInvestigatingComplaint.php`
- Create: `app/Domain/VendorFulfillment/Actions/ResolveComplaint.php`
- Create: `app/Domain/VendorFulfillment/Actions/DismissComplaint.php`
- Create: `app/Domain/VendorFulfillment/Exceptions/InvalidComplaintTransitionException.php`
- Modify: `app/Domain/VendorFulfillment/VendorFulfillmentAuditActions.php`
- Test: `tests/Feature/Domain/VendorFulfillment/ComplaintResolutionFlowTest.php`

**Interfaces:**
- Consumes: `ServiceComplaint` + its `workOrder()` relation (Task 1), `CreateMakeGood::__invoke(WorkOrder, ?string, ?string, ?AuditSource, ?string): MakeGoodOrder` (Task 1), `ComplaintStatus` enum, `Audit::wrap()`, `Outbox::record()`.
- Produces: `StartInvestigatingComplaint::__invoke(ServiceComplaint $complaint): ServiceComplaint`, `ResolveComplaint::__invoke(ServiceComplaint $complaint, string $resolutionNotes, bool $createMakeGood, ?string $makeGoodNotes = null, ?string $actorRole = null, ?AuditSource $source = null, ?string $actorRef = null): ServiceComplaint`, `DismissComplaint::__invoke(ServiceComplaint $complaint, string $reason, ?string $actorRole = null, ?AuditSource $source = null, ?string $actorRef = null): ServiceComplaint`. All three throw `InvalidComplaintTransitionException` when called on a complaint not in an allowed source state.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\VendorFulfillment;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\VendorFulfillment\Actions\CreateWorkOrderFromCycle;
use App\Domain\VendorFulfillment\Actions\DismissComplaint;
use App\Domain\VendorFulfillment\Actions\FileComplaint;
use App\Domain\VendorFulfillment\Actions\ResolveComplaint;
use App\Domain\VendorFulfillment\Actions\StartInvestigatingComplaint;
use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\Exceptions\InvalidComplaintTransitionException;
use App\Domain\VendorFulfillment\MakeGoodStatus;
use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Models\User;
use App\Platform\Audit\AuditSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ComplaintResolutionFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkOrder(): WorkOrder
    {
        $carePlan = CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Basic Care',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'product_code' => 'GC-MONTHLY',
            'status' => 'active',
            'checklist_template' => [],
        ]);
        $subscription = Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => User::factory()->create()->id,
            'status' => 'active',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'currency' => 'IDR',
        ]);
        $cycle = SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth(),
            'cycle_end' => now(),
            'status' => 'PAID',
        ]);

        return app(CreateWorkOrderFromCycle::class)($cycle, $carePlan);
    }

    private function fileComplaint(): ServiceComplaint
    {
        return app(FileComplaint::class)(
            $this->makeWorkOrder(),
            User::factory()->create()->id,
            'Service was not performed properly.',
        );
    }

    public function test_start_investigating_transitions_open_to_investigating(): void
    {
        $complaint = $this->fileComplaint();

        $result = app(StartInvestigatingComplaint::class)($complaint);

        $this->assertSame(ComplaintStatus::Investigating->value, $result->status);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'COMPLAINT_INVESTIGATING',
            'subject_type' => 'service_complaint',
            'subject_id' => (string) $complaint->getKey(),
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'care.complaint_investigating.v1',
            'aggregate_type' => 'service_complaint',
            'aggregate_id' => (string) $complaint->getKey(),
        ]);
    }

    public function test_start_investigating_refuses_a_complaint_not_open(): void
    {
        $complaint = $this->fileComplaint();
        app(StartInvestigatingComplaint::class)($complaint);

        $this->expectException(InvalidComplaintTransitionException::class);

        app(StartInvestigatingComplaint::class)($complaint->fresh());
    }

    public function test_resolve_without_make_good_sets_resolved_and_notes(): void
    {
        $complaint = $this->fileComplaint();

        $result = app(ResolveComplaint::class)($complaint, 'Talked to the vendor, agreed to redo next visit.', false);

        $this->assertSame(ComplaintStatus::Resolved->value, $result->status);
        $this->assertSame('Talked to the vendor, agreed to redo next visit.', $result->resolution_notes);
        $this->assertNotNull($result->resolved_at);
        $this->assertNull($result->make_good_order_id);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'COMPLAINT_RESOLVED',
            'subject_type' => 'service_complaint',
            'subject_id' => (string) $complaint->getKey(),
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'care.complaint_resolved.v1',
            'aggregate_type' => 'service_complaint',
            'aggregate_id' => (string) $complaint->getKey(),
        ]);
    }

    public function test_resolve_with_make_good_creates_and_links_a_make_good_order(): void
    {
        $complaint = $this->fileComplaint();

        $result = app(ResolveComplaint::class)(
            $complaint,
            'Headstone was not cleaned properly, issuing a redo.',
            true,
            'Redo the cleaning pass.',
            'admin',
            AuditSource::Panel,
            'admin-user-ref',
        );

        $this->assertSame(ComplaintStatus::Resolved->value, $result->status);
        $this->assertNotNull($result->make_good_order_id);

        $makeGood = \App\Domain\VendorFulfillment\Models\MakeGoodOrder::query()->findOrFail($result->make_good_order_id);
        $this->assertSame(MakeGoodStatus::Pending->value, $makeGood->status);
        $this->assertSame('Redo the cleaning pass.', $makeGood->notes);

        // Real actor attribution flows through to CreateMakeGood's own audit row.
        $this->assertDatabaseHas('audit_events', [
            'action' => 'MAKE_GOOD_CREATED',
            'actor_role' => 'admin',
            'actor_ref' => 'admin-user-ref',
        ]);
    }

    public function test_resolve_refuses_a_dismissed_complaint(): void
    {
        $complaint = $this->fileComplaint();
        app(DismissComplaint::class)($complaint, 'Not a valid complaint.');

        $this->expectException(InvalidComplaintTransitionException::class);

        app(ResolveComplaint::class)($complaint->fresh(), 'Too late', false);
    }

    public function test_dismiss_transitions_open_or_investigating_to_dismissed_with_reason(): void
    {
        $complaint = $this->fileComplaint();

        $result = app(DismissComplaint::class)($complaint, 'Vendor evidence shows service was completed correctly.');

        $this->assertSame(ComplaintStatus::Dismissed->value, $result->status);
        $this->assertSame('Vendor evidence shows service was completed correctly.', $result->resolution_notes);
        $this->assertNotNull($result->resolved_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'COMPLAINT_DISMISSED',
            'subject_type' => 'service_complaint',
            'subject_id' => (string) $complaint->getKey(),
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'care.complaint_dismissed.v1',
            'aggregate_type' => 'service_complaint',
            'aggregate_id' => (string) $complaint->getKey(),
        ]);
    }

    public function test_dismiss_refuses_an_already_resolved_complaint(): void
    {
        $complaint = $this->fileComplaint();
        app(ResolveComplaint::class)($complaint, 'Handled directly.', false);

        $this->expectException(InvalidComplaintTransitionException::class);

        app(DismissComplaint::class)($complaint->fresh(), 'Too late');
    }
}
```

- [ ] **Step 2: Run to verify all 7 tests fail**

```bash
$RUN vendor/bin/phpunit tests/Feature/Domain/VendorFulfillment/ComplaintResolutionFlowTest.php
```

Expected: FAIL — classes do not exist.

- [ ] **Step 3: Add the audit-action constants**

Modify `app/Domain/VendorFulfillment/VendorFulfillmentAuditActions.php`, adding after `MAKE_GOOD_CREATED`:

```php
    public const string COMPLAINT_INVESTIGATING = 'COMPLAINT_INVESTIGATING';

    public const string COMPLAINT_RESOLVED = 'COMPLAINT_RESOLVED';

    public const string COMPLAINT_DISMISSED = 'COMPLAINT_DISMISSED';
```

- [ ] **Step 4: Write the exception class**

```php
<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Exceptions;

use RuntimeException;

/**
 * Thrown when a `ServiceComplaint` status transition is attempted from a
 * status that does not allow it — e.g. resolving an already-dismissed
 * complaint. Mirrors this codebase's fail-closed discipline for domain
 * state machines (see `OrderIsGuardedException` for the same shape in the
 * `OrderWorkflow` domain).
 */
final class InvalidComplaintTransitionException extends RuntimeException {}
```

- [ ] **Step 5: Write `StartInvestigatingComplaint`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Actions;

use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\Exceptions\InvalidComplaintTransitionException;
use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Domain\VendorFulfillment\VendorFulfillmentAuditActions;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;

/**
 * Moves a complaint from OPEN to INVESTIGATING — staff acknowledging they
 * are looking into it. Refuses any other source status.
 */
final readonly class StartInvestigatingComplaint
{
    public function __invoke(
        ServiceComplaint $complaint,
        ?string $actorRole = null,
        ?AuditSource $source = null,
        ?string $actorRef = null,
    ): ServiceComplaint {
        if ($complaint->status !== ComplaintStatus::Open->value) {
            throw new InvalidComplaintTransitionException(
                "Cannot start investigating complaint [{$complaint->getKey()}] from status [{$complaint->status}]; only OPEN complaints can move to INVESTIGATING."
            );
        }

        return Audit::wrap(
            mutation: function () use ($complaint): ServiceComplaint {
                $complaint->forceFill(['status' => ComplaintStatus::Investigating->value])->save();

                Outbox::record(
                    eventName: 'care.complaint_investigating.v1',
                    eventVersion: 1,
                    aggregateType: 'service_complaint',
                    aggregateId: $complaint->getKey(),
                    data: ['complaint_id' => $complaint->getKey()],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "complaint_investigating:{$complaint->getKey()}",
                );

                return $complaint->fresh();
            },
            action: VendorFulfillmentAuditActions::COMPLAINT_INVESTIGATING,
            subject: new AuditSubject('service_complaint', $complaint->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole ?? 'system',
            source: $source ?? AuditSource::Job,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
```

- [ ] **Step 6: Write `ResolveComplaint`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Actions;

use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\Exceptions\InvalidComplaintTransitionException;
use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\VendorFulfillmentAuditActions;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Carbon\CarbonImmutable;

/**
 * Resolves a complaint (OPEN or INVESTIGATING -> RESOLVED), optionally
 * creating and linking a make-good order in the same transaction. See
 * spec §1 — the `make_good_order_id` linkage is a real, previously
 * missing relationship, not a workaround.
 */
final readonly class ResolveComplaint
{
    public function __invoke(
        ServiceComplaint $complaint,
        string $resolutionNotes,
        bool $createMakeGood,
        ?string $makeGoodNotes = null,
        ?string $actorRole = null,
        ?AuditSource $source = null,
        ?string $actorRef = null,
    ): ServiceComplaint {
        if (! in_array($complaint->status, [ComplaintStatus::Open->value, ComplaintStatus::Investigating->value], true)) {
            throw new InvalidComplaintTransitionException(
                "Cannot resolve complaint [{$complaint->getKey()}] from status [{$complaint->status}]; only OPEN or INVESTIGATING complaints can be resolved."
            );
        }

        return Audit::wrap(
            mutation: function () use ($complaint, $resolutionNotes, $createMakeGood, $makeGoodNotes, $actorRole, $source, $actorRef): ServiceComplaint {
                $makeGoodOrderId = null;

                if ($createMakeGood) {
                    /** @var WorkOrder $workOrder */
                    $workOrder = $complaint->workOrder()->firstOrFail();

                    $makeGood = app(CreateMakeGood::class)(
                        $workOrder,
                        $makeGoodNotes,
                        $actorRole,
                        $source,
                        $actorRef,
                    );

                    $makeGoodOrderId = $makeGood->getKey();
                }

                $complaint->forceFill([
                    'status' => ComplaintStatus::Resolved->value,
                    'resolution_notes' => $resolutionNotes,
                    'resolved_at' => CarbonImmutable::now(),
                    'make_good_order_id' => $makeGoodOrderId,
                ])->save();

                Outbox::record(
                    eventName: 'care.complaint_resolved.v1',
                    eventVersion: 1,
                    aggregateType: 'service_complaint',
                    aggregateId: $complaint->getKey(),
                    data: [
                        'complaint_id' => $complaint->getKey(),
                        'make_good_order_id' => $makeGoodOrderId,
                    ],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "complaint_resolved:{$complaint->getKey()}",
                );

                return $complaint->fresh();
            },
            action: VendorFulfillmentAuditActions::COMPLAINT_RESOLVED,
            subject: new AuditSubject('service_complaint', $complaint->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole ?? 'system',
            source: $source ?? AuditSource::Job,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
```

- [ ] **Step 7: Write `DismissComplaint`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Actions;

use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\Exceptions\InvalidComplaintTransitionException;
use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Domain\VendorFulfillment\VendorFulfillmentAuditActions;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Carbon\CarbonImmutable;

/**
 * Dismisses a complaint (OPEN or INVESTIGATING -> DISMISSED) with a
 * required reason, stored in the same `resolution_notes` column a real
 * resolution uses — a dismissal reason and a resolution note are the same
 * kind of "why this complaint is closed" text (spec §1).
 */
final readonly class DismissComplaint
{
    public function __invoke(
        ServiceComplaint $complaint,
        string $reason,
        ?string $actorRole = null,
        ?AuditSource $source = null,
        ?string $actorRef = null,
    ): ServiceComplaint {
        if (! in_array($complaint->status, [ComplaintStatus::Open->value, ComplaintStatus::Investigating->value], true)) {
            throw new InvalidComplaintTransitionException(
                "Cannot dismiss complaint [{$complaint->getKey()}] from status [{$complaint->status}]; only OPEN or INVESTIGATING complaints can be dismissed."
            );
        }

        return Audit::wrap(
            mutation: function () use ($complaint, $reason): ServiceComplaint {
                $complaint->forceFill([
                    'status' => ComplaintStatus::Dismissed->value,
                    'resolution_notes' => $reason,
                    'resolved_at' => CarbonImmutable::now(),
                ])->save();

                Outbox::record(
                    eventName: 'care.complaint_dismissed.v1',
                    eventVersion: 1,
                    aggregateType: 'service_complaint',
                    aggregateId: $complaint->getKey(),
                    data: ['complaint_id' => $complaint->getKey()],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "complaint_dismissed:{$complaint->getKey()}",
                );

                return $complaint->fresh();
            },
            action: VendorFulfillmentAuditActions::COMPLAINT_DISMISSED,
            subject: new AuditSubject('service_complaint', $complaint->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole ?? 'system',
            source: $source ?? AuditSource::Job,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
```

- [ ] **Step 8: Run to verify all tests pass**

```bash
$RUN vendor/bin/phpunit tests/Feature/Domain/VendorFulfillment/ComplaintResolutionFlowTest.php
```

Expected: PASS, all 7 tests. If `test_resolve_with_make_good_creates_and_links_a_make_good_order` fails on the `actor_role`/`actor_ref` audit assertion, read `app/Platform/Audit/Audit.php`'s real column names for `audit_events` (`actor_role`/`actor_ref` are this plan's best-effort guess at the real column names, matching `AuditSource`'s sibling parameters' naming — confirm against the real `audit_events` migration/model before trusting this test as written).

- [ ] **Step 9: Run the full `VendorFulfillment` domain test directory as a regression check**

```bash
$RUN vendor/bin/phpunit tests/Feature/Domain/VendorFulfillment/
```

Expected: PASS, no regressions in `ComplaintFlowTest.php` or any other file in that directory.

- [ ] **Step 10: Run pint and phpstan**

```bash
$RUN vendor/bin/pint --test app/Domain/VendorFulfillment/Actions/StartInvestigatingComplaint.php app/Domain/VendorFulfillment/Actions/ResolveComplaint.php app/Domain/VendorFulfillment/Actions/DismissComplaint.php app/Domain/VendorFulfillment/Exceptions/InvalidComplaintTransitionException.php app/Domain/VendorFulfillment/VendorFulfillmentAuditActions.php tests/Feature/Domain/VendorFulfillment/ComplaintResolutionFlowTest.php
$RUN php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

Expected: both clean.

- [ ] **Step 11: Commit**

```bash
git add app/Domain/VendorFulfillment/Actions/StartInvestigatingComplaint.php app/Domain/VendorFulfillment/Actions/ResolveComplaint.php app/Domain/VendorFulfillment/Actions/DismissComplaint.php app/Domain/VendorFulfillment/Exceptions/InvalidComplaintTransitionException.php app/Domain/VendorFulfillment/VendorFulfillmentAuditActions.php tests/Feature/Domain/VendorFulfillment/ComplaintResolutionFlowTest.php
git commit -m "feat(vendor-fulfillment): add complaint investigate/resolve/dismiss domain actions"
```

---

### Task 3: `ServiceComplaintsResource` (Filament admin)

**Files:**
- Create: `app/Filament/Admin/Resources/ServiceComplaints/ServiceComplaintsResource.php`
- Create: `app/Filament/Admin/Resources/ServiceComplaints/Pages/ListServiceComplaints.php`
- Create: `app/Filament/Admin/Resources/ServiceComplaints/Pages/ViewServiceComplaint.php`
- Create: `app/Filament/Admin/Resources/ServiceComplaints/Actions/StartInvestigatingAction.php`
- Create: `app/Filament/Admin/Resources/ServiceComplaints/Actions/ResolveComplaintAction.php`
- Create: `app/Filament/Admin/Resources/ServiceComplaints/Actions/DismissComplaintAction.php`
- Create: `app/Filament/Admin/Resources/ServiceComplaints/Tables/ServiceComplaintsTable.php`
- Create: `app/Filament/Admin/Resources/ServiceComplaints/Schemas/ServiceComplaintInfolist.php`
- Test: `tests/Feature/Filament/Admin/ServiceComplaintsResourceTest.php`

**Interfaces:**
- Consumes: `StartInvestigatingComplaint`, `ResolveComplaint`, `DismissComplaint` (Task 2), `ServiceComplaint::workOrder()`/`::customer()` (Task 1), `MasterDataAdminAuthorizerContract`, `ActorContext`.
- Produces: the `/admin/keluhan-layanan` (or similar real slug — pick one consistent with `WorkOrdersResource`'s `order-kerja` naming convention, Indonesian, kebab-case) Filament resource. Nothing later in this plan depends on this resource's own interface.

- [ ] **Step 1: Write the failing authorization test**

Mirror `tests/Feature/Filament/Admin/WorkOrderVendorReplacementTest.php`'s exact real pattern (`Tests\Support\GrantsActorRoles` trait, `actingAs()` + `forgetResolvedActorContext()`, `Livewire::test(ViewRecordClass::class, ['record' => $record->getRouteKey()])`):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\VendorFulfillment\Actions\FileComplaint;
use App\Domain\VendorFulfillment\Actions\CreateWorkOrderFromCycle;
use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Filament\Admin\Resources\ServiceComplaints\Pages\ViewServiceComplaint;
use App\Filament\Admin\Resources\ServiceComplaints\ServiceComplaintsResource;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

final class ServiceComplaintsResourceTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function actingUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, $role);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        return $user;
    }

    private function makeComplaint(): ServiceComplaint
    {
        $carePlan = CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Basic Care',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'product_code' => 'GC-MONTHLY',
            'status' => 'active',
            'checklist_template' => [],
        ]);
        $subscription = \App\Domain\CareSubscription\Models\Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => User::factory()->create()->id,
            'status' => 'active',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'currency' => 'IDR',
        ]);
        $cycle = \App\Domain\CareSubscription\Models\SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth(),
            'cycle_end' => now(),
            'status' => 'PAID',
        ]);
        $workOrder = app(CreateWorkOrderFromCycle::class)($cycle, $carePlan);

        return app(FileComplaint::class)($workOrder, User::factory()->create()->id, 'Service was not performed properly.');
    }

    public function test_the_resource_fails_closed_outside_the_back_office_roles(): void
    {
        $this->assertFalse(ServiceComplaintsResource::canAccess());

        $this->actingAs(User::factory()->create());
        $this->forgetResolvedActorContext();
        $this->assertFalse(ServiceComplaintsResource::canAccess());
    }

    public function test_back_office_roles_can_view_the_resource(): void
    {
        foreach ([
            ActorRole::ADMIN,
            ActorRole::RESTRICTED_ADMIN,
            ActorRole::OPERATOR,
            ActorRole::FINANCE,
        ] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(
                ServiceComplaintsResource::canAccess(),
                "Expected role [{$role}] to access the service complaints resource.",
            );
            $this->forgetResolvedActorContext();
        }
    }

    public function test_vendor_and_cemetery_operator_and_customer_are_denied(): void
    {
        foreach ([ActorRole::VENDOR, ActorRole::CEMETERY_OPERATOR, ActorRole::CUSTOMER] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertFalse(
                ServiceComplaintsResource::canAccess(),
                "Expected role [{$role}] to be denied the service complaints resource.",
            );
            $this->forgetResolvedActorContext();
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
$RUN vendor/bin/phpunit tests/Feature/Filament/Admin/ServiceComplaintsResourceTest.php
```

Expected: FAIL — `ServiceComplaintsResource` does not exist.

- [ ] **Step 3: Write `ServiceComplaintsResource.php`**

Mirror `app/Filament/Admin/Resources/WorkOrders/WorkOrdersResource.php` exactly (read it again now if needed — Global Constraints above has its real, current content). Key differences from that file: model is `ServiceComplaint`, slug is `'keluhan-layanan'`, table/infolist delegate to the new `ServiceComplaintsTable`/`ServiceComplaintInfolist`, `getPages()` still only registers `'index'`/`'view'` (no create/edit — complaints are only ever created via `FileComplaint`).

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints;

use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Filament\Admin\Resources\ServiceComplaints\Pages\ListServiceComplaints;
use App\Filament\Admin\Resources\ServiceComplaints\Pages\ViewServiceComplaint;
use App\Filament\Admin\Resources\ServiceComplaints\Schemas\ServiceComplaintInfolist;
use App\Filament\Admin\Resources\ServiceComplaints\Tables\ServiceComplaintsTable;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin surface for `service_complaints` — the first UI anywhere in this
 * codebase to read that table (`FileComplaint` writes it, nothing read it
 * before this file). Mirrors `WorkOrdersResource`'s exact shape: read-only
 * list/view, no create/edit form (complaints are only ever filed via
 * `FileComplaint`, from the customer-facing `CareHistoryPage`), same
 * `MasterDataAdminAuthorizerContract` view gate.
 */
final class ServiceComplaintsResource extends Resource
{
    protected static ?string $model = ServiceComplaint::class;

    protected static ?string $slug = 'keluhan-layanan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 63;

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
            return Response::deny('Anda tidak berwenang mengelola keluhan layanan.');
        }
    }

    public static function table(Table $table): Table
    {
        return ServiceComplaintsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ServiceComplaintInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceComplaints::route('/'),
            'view' => ViewServiceComplaint::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'keluhan layanan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Keluhan Layanan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Keluhan Layanan';
    }
}
```

- [ ] **Step 4: Write `Pages/ListServiceComplaints.php`**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints\Pages;

use App\Filament\Admin\Resources\ServiceComplaints\ServiceComplaintsResource;
use Filament\Resources\Pages\ListRecords;

final class ListServiceComplaints extends ListRecords
{
    protected static string $resource = ServiceComplaintsResource::class;
}
```

- [ ] **Step 5: Write `Tables/ServiceComplaintsTable.php`**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints\Tables;

use App\Domain\VendorFulfillment\ComplaintStatus;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class ServiceComplaintsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('workOrder.reference')
                    ->label('Pesanan Kerja')
                    ->placeholder('—'),

                TextColumn::make('complaint_text')
                    ->label('Keluhan')
                    ->limit(60)
                    ->tooltip(fn (string $state): ?string => strlen($state) > 60 ? $state : null),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state)),

                TextColumn::make('filed_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('resolved_at')
                    ->label('Diselesaikan')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions()),
            ])
            ->emptyStateHeading('Belum ada keluhan layanan')
            ->emptyStateDescription('Keluhan yang diajukan pelanggan akan muncul di sini.')
            ->emptyStateIcon(Heroicon::OutlinedExclamationTriangle)
            ->defaultSort('filed_at', 'desc');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            ComplaintStatus::Open->value => 'Terbuka',
            ComplaintStatus::Investigating->value => 'Sedang Diselidiki',
            ComplaintStatus::Resolved->value => 'Selesai',
            ComplaintStatus::Dismissed->value => 'Ditolak',
            default => $status,
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            ComplaintStatus::Open->value => 'danger',
            ComplaintStatus::Investigating->value => 'warning',
            ComplaintStatus::Resolved->value => 'success',
            ComplaintStatus::Dismissed->value => 'gray',
            default => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            ComplaintStatus::Open->value => 'Terbuka',
            ComplaintStatus::Investigating->value => 'Sedang Diselidiki',
            ComplaintStatus::Resolved->value => 'Selesai',
            ComplaintStatus::Dismissed->value => 'Ditolak',
        ];
    }
}
```

- [ ] **Step 6: Write `Schemas/ServiceComplaintInfolist.php`**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints\Schemas;

use App\Filament\Admin\Resources\ServiceComplaints\Tables\ServiceComplaintsTable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ServiceComplaintInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Keluhan')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('workOrder.reference')
                            ->label('Pesanan Kerja')
                            ->placeholder('—'),

                        TextEntry::make('customer.name')
                            ->label('Pelanggan')
                            ->placeholder('—'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => ServiceComplaintsTable::statusLabel($state))
                            ->color(fn (string $state): string => ServiceComplaintsTable::statusColor($state)),

                        TextEntry::make('filed_at')
                            ->label('Diajukan pada')
                            ->dateTime('d M Y H:i'),

                        TextEntry::make('complaint_text')
                            ->label('Isi Keluhan')
                            ->columnSpanFull(),

                        TextEntry::make('resolution_notes')
                            ->label('Catatan Penyelesaian')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('resolved_at')
                            ->label('Diselesaikan pada')
                            ->dateTime('d M Y H:i')
                            ->placeholder('—'),

                        TextEntry::make('make_good_order_id')
                            ->label('Pesanan Perbaikan (Make-Good)')
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
```

- [ ] **Step 7: Write the 3 Filament Actions, mirroring `ReplaceVendorAction.php`'s exact shape**

`Actions/StartInvestigatingAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints\Actions;

use App\Domain\VendorFulfillment\Actions\StartInvestigatingComplaint;
use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Filament\Admin\Resources\ServiceComplaints\ServiceComplaintsResource;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Notifications\Notification;
use Throwable;

final class StartInvestigatingAction
{
    public static function isAuthorized(): bool
    {
        return ServiceComplaintsResource::canAccess();
    }

    public static function visible(ServiceComplaint $record): bool
    {
        return $record->status === ComplaintStatus::Open->value;
    }

    public static function run(ServiceComplaint $record): void
    {
        if (! self::isAuthorized()) {
            Notification::make()->danger()->title('Anda tidak berwenang mengelola keluhan ini.')->send();

            return;
        }

        $actor = app(ActorContext::class);

        try {
            app(StartInvestigatingComplaint::class)(
                $record,
                ServiceComplaintsResource::auditRoleFor($actor),
                AuditSource::Panel,
                (string) $actor->identityReference,
            );

            Notification::make()->success()->title('Keluhan sedang diselidiki.')->send();
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Gagal memperbarui status keluhan')->body($exception->getMessage())->send();
        }
    }
}
```

`Actions/ResolveComplaintAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints\Actions;

use App\Domain\VendorFulfillment\Actions\ResolveComplaint;
use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Filament\Admin\Resources\ServiceComplaints\ServiceComplaintsResource;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Throwable;

final class ResolveComplaintAction
{
    /** @return array<Textarea|Toggle> */
    public static function schema(): array
    {
        return [
            Textarea::make('resolution_notes')
                ->label('Catatan penyelesaian')
                ->rows(3)
                ->required(),

            Toggle::make('create_make_good')
                ->label('Buat pesanan perbaikan (make-good)?')
                ->live(),

            Textarea::make('make_good_notes')
                ->label('Catatan make-good')
                ->rows(2)
                ->visible(fn ($get) => (bool) $get('create_make_good')),
        ];
    }

    public static function isAuthorized(): bool
    {
        return ServiceComplaintsResource::canAccess();
    }

    public static function visible(ServiceComplaint $record): bool
    {
        return in_array($record->status, [ComplaintStatus::Open->value, ComplaintStatus::Investigating->value], true);
    }

    public static function run(ServiceComplaint $record, array $data): void
    {
        if (! self::isAuthorized()) {
            Notification::make()->danger()->title('Anda tidak berwenang mengelola keluhan ini.')->send();

            return;
        }

        $resolutionNotes = trim((string) ($data['resolution_notes'] ?? ''));
        $createMakeGood = (bool) ($data['create_make_good'] ?? false);
        $makeGoodNotes = $data['make_good_notes'] ?? null;

        if ($resolutionNotes === '') {
            Notification::make()->danger()->title('Catatan penyelesaian wajib diisi.')->send();

            return;
        }

        $actor = app(ActorContext::class);

        try {
            app(ResolveComplaint::class)(
                $record,
                $resolutionNotes,
                $createMakeGood,
                $makeGoodNotes !== null ? (string) $makeGoodNotes : null,
                ServiceComplaintsResource::auditRoleFor($actor),
                AuditSource::Panel,
                (string) $actor->identityReference,
            );

            Notification::make()->success()->title('Keluhan diselesaikan.')->send();
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Gagal menyelesaikan keluhan')->body($exception->getMessage())->send();
        }
    }
}
```

`Actions/DismissComplaintAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints\Actions;

use App\Domain\VendorFulfillment\Actions\DismissComplaint;
use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Filament\Admin\Resources\ServiceComplaints\ServiceComplaintsResource;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Throwable;

final class DismissComplaintAction
{
    /** @return array<Textarea> */
    public static function schema(): array
    {
        return [
            Textarea::make('reason')
                ->label('Alasan penolakan')
                ->rows(3)
                ->required(),
        ];
    }

    public static function isAuthorized(): bool
    {
        return ServiceComplaintsResource::canAccess();
    }

    public static function visible(ServiceComplaint $record): bool
    {
        return in_array($record->status, [ComplaintStatus::Open->value, ComplaintStatus::Investigating->value], true);
    }

    public static function run(ServiceComplaint $record, array $data): void
    {
        if (! self::isAuthorized()) {
            Notification::make()->danger()->title('Anda tidak berwenang mengelola keluhan ini.')->send();

            return;
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            Notification::make()->danger()->title('Alasan penolakan wajib diisi.')->send();

            return;
        }

        $actor = app(ActorContext::class);

        try {
            app(DismissComplaint::class)(
                $record,
                $reason,
                ServiceComplaintsResource::auditRoleFor($actor),
                AuditSource::Panel,
                (string) $actor->identityReference,
            );

            Notification::make()->success()->title('Keluhan ditolak.')->send();
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Gagal menolak keluhan')->body($exception->getMessage())->send();
        }
    }
}
```

- [ ] **Step 8: Wire the 3 actions into `Pages/ViewServiceComplaint.php`**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints\Pages;

use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Filament\Admin\Resources\ServiceComplaints\Actions\DismissComplaintAction;
use App\Filament\Admin\Resources\ServiceComplaints\Actions\ResolveComplaintAction;
use App\Filament\Admin\Resources\ServiceComplaints\Actions\StartInvestigatingAction;
use App\Filament\Admin\Resources\ServiceComplaints\ServiceComplaintsResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewServiceComplaint extends ViewRecord
{
    protected static string $resource = ServiceComplaintsResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var ServiceComplaint $record */
        $record = $this->getRecord();

        return [
            Action::make('mulaiInvestigasi')
                ->label('Mulai Investigasi')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('warning')
                ->visible(fn (): bool => StartInvestigatingAction::visible($record))
                ->authorize(fn (): bool => StartInvestigatingAction::isAuthorized())
                ->requiresConfirmation()
                ->modalHeading('Mulai investigasi keluhan ini?')
                ->action(fn () => StartInvestigatingAction::run($record)),

            Action::make('selesaikan')
                ->label('Selesaikan')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (): bool => ResolveComplaintAction::visible($record))
                ->authorize(fn (): bool => ResolveComplaintAction::isAuthorized())
                ->modalHeading('Selesaikan keluhan ini?')
                ->schema(ResolveComplaintAction::schema())
                ->action(fn (array $data) => ResolveComplaintAction::run($record, $data)),

            Action::make('tolak')
                ->label('Tolak')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (): bool => DismissComplaintAction::visible($record))
                ->authorize(fn (): bool => DismissComplaintAction::isAuthorized())
                ->modalHeading('Tolak keluhan ini?')
                ->schema(DismissComplaintAction::schema())
                ->action(fn (array $data) => DismissComplaintAction::run($record, $data)),
        ];
    }
}
```

Add an `auditRoleFor(ActorContext $actor): string` static method to `ServiceComplaintsResource` (copy `WorkOrdersResource::auditRoleFor()`'s exact body, substituting nothing — it's a generic role-name lookup, not `WorkOrders`-specific) — the 3 Actions above call `ServiceComplaintsResource::auditRoleFor($actor)`.

- [ ] **Step 9: Run to verify Step 1's access-matrix tests pass**

```bash
$RUN vendor/bin/phpunit tests/Feature/Filament/Admin/ServiceComplaintsResourceTest.php
```

Expected: PASS, all 3 access-matrix tests from Step 1.

- [ ] **Step 10: Write and run the full-path feature tests**

Append to `tests/Feature/Filament/Admin/ServiceComplaintsResourceTest.php`, inside the existing `final class`, mirroring `WorkOrderVendorReplacementTest::test_an_admin_replaces_the_vendor_through_the_resource()`'s exact real shape (`Livewire::test(ViewRecordClass::class, ['record' => $record->getRouteKey()])->callAction('name', data: [...])->assertHasNoActionErrors()->assertNotified(...)`):

```php
    public function test_an_admin_resolves_a_complaint_with_make_good_through_the_resource_and_attributes_the_real_actor(): void
    {
        $complaint = $this->makeComplaint();
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewServiceComplaint::class, ['record' => $complaint->getRouteKey()])
            ->callAction('selesaikan', data: [
                'resolution_notes' => 'Headstone was not cleaned properly, issuing a redo.',
                'create_make_good' => true,
                'make_good_notes' => 'Redo the cleaning pass.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Keluhan diselesaikan.');

        $fresh = $complaint->fresh();
        $this->assertSame(\App\Domain\VendorFulfillment\ComplaintStatus::Resolved->value, $fresh->status);
        $this->assertNotNull($fresh->make_good_order_id);

        $makeGood = \App\Domain\VendorFulfillment\Models\MakeGoodOrder::query()->findOrFail($fresh->make_good_order_id);
        $this->assertSame('Redo the cleaning pass.', $makeGood->notes);

        // Real actor attribution flows from the Filament action through
        // ResolveComplaint into CreateMakeGood's audit row — not 'system'.
        $this->assertDatabaseHas('audit_events', [
            'action' => 'MAKE_GOOD_CREATED',
            'actor_role' => 'admin',
        ]);
    }

    public function test_operator_and_finance_can_see_the_resolve_action(): void
    {
        $complaint = $this->makeComplaint();

        foreach ([ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);
            $this->forgetResolvedActorContext();

            // OPERATOR/FINANCE are allowed to VIEW the resource
            // (MasterDataAdminAuthorizerContract) but per this task's
            // brief the resolve/dismiss/investigate ACTIONS should carry
            // no narrower gate than the resource's own view gate — unlike
            // WorkOrdersResource's 'gantiVendor', ServiceComplaintsResource
            // does not scope these 3 actions to admin/restricted_admin
            // only. Confirm this deliberate choice: all 4 authorized
            // roles should see and be able to invoke all 3 actions. If
            // the real requirement turns out to need a narrower gate
            // (matching ReplaceVendorAction's admin/restricted_admin-only
            // pattern instead), adjust ResolveComplaintAction/
            // DismissComplaintAction/StartInvestigatingAction's
            // isAuthorized() methods accordingly and update this test to
            // match — this is a judgment call this plan makes explicitly
            // rather than leaving ambiguous: no requirement anywhere
            // named a stricter bar for complaint resolution than for
            // viewing complaints, unlike vendor replacement's own
            // documented stricter bar.
            Livewire::test(ViewServiceComplaint::class, ['record' => $complaint->getRouteKey()])
                ->assertActionVisible('selesaikan');
        }
    }

    public function test_the_resolution_notes_field_is_required(): void
    {
        $complaint = $this->makeComplaint();
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewServiceComplaint::class, ['record' => $complaint->getRouteKey()])
            ->callAction('selesaikan', data: [
                'resolution_notes' => '',
                'create_make_good' => false,
            ])
            ->assertHasActionErrors(['resolution_notes' => ['required']]);

        $this->assertSame(\App\Domain\VendorFulfillment\ComplaintStatus::Open->value, $complaint->fresh()->status);
    }

    public function test_an_admin_dismisses_a_complaint_through_the_resource(): void
    {
        $complaint = $this->makeComplaint();
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewServiceComplaint::class, ['record' => $complaint->getRouteKey()])
            ->callAction('tolak', data: ['reason' => 'Vendor evidence shows service was completed correctly.'])
            ->assertHasNoActionErrors()
            ->assertNotified('Keluhan ditolak.');

        $fresh = $complaint->fresh();
        $this->assertSame(\App\Domain\VendorFulfillment\ComplaintStatus::Dismissed->value, $fresh->status);
        $this->assertSame('Vendor evidence shows service was completed correctly.', $fresh->resolution_notes);
    }
```

```bash
$RUN vendor/bin/phpunit tests/Feature/Filament/Admin/ServiceComplaintsResourceTest.php
```

Expected: PASS, all tests.

- [ ] **Step 11: Run pint and phpstan**

```bash
$RUN vendor/bin/pint --test app/Filament/Admin/Resources/ServiceComplaints/ tests/Feature/Filament/Admin/ServiceComplaintsResourceTest.php
$RUN php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

Expected: both clean.

- [ ] **Step 12: Commit**

```bash
git add app/Filament/Admin/Resources/ServiceComplaints/ tests/Feature/Filament/Admin/ServiceComplaintsResourceTest.php
git commit -m "feat(admin): add ServiceComplaints resource with investigate/resolve/dismiss actions"
```

---

### Task 4: Marketplace listing details on the public product-detail page

**Files:**
- Modify: `resources/views/livewire/public/marketplace/product-detail.blade.php`
- Test: find and extend the existing product-detail Livewire test (search `tests/Feature/Livewire/Public/Marketplace/` for `ProductDetail` — likely `ProductDetailRouteTest.php`, cited in `traceability-matrix.md:177-179`)

**Interfaces:**
- Consumes: `VendorListing` (`availability_mode`, `stock_quantity`, `production_lead_time_days`, `evidence_requirement`, `cancellation_policy` — all already resolved onto the `$listing` view variable by `ProductDetail::render()`, no query changes needed), `App\Domain\Marketplace\AvailabilityMode::{STOCKED,MADE_TO_ORDER,SCHEDULED}`, `App\Domain\Marketplace\EvidenceRequirement::{NONE,PHOTO,DOCUMENT}`.
- Produces: nothing new for later tasks.

- [ ] **Step 1: Find and read the real existing product-detail test file**

```bash
grep -rl "ProductDetail::class\|livewire.public.marketplace.product-detail" tests/Feature/Livewire/Public/Marketplace/
```

Read the file(s) this returns in full before writing Step 2 — this task extends that file, it does not create a new one.

- [ ] **Step 2: Write the failing test**

Append to the real test file found in Step 1 (exact class/namespace already exists — match its style):

```php
    public function test_it_shows_availability_stock_and_evidence_requirement_for_a_stocked_listing(): void
    {
        $product = Product::factory()->create();
        $vendor = Vendor::factory()->create();
        VendorListing::query()->create([
            'vendor_id' => $vendor->id,
            'product_id' => $product->id,
            'price_minor' => 500000,
            'availability_mode' => AvailabilityMode::STOCKED,
            'stock_quantity' => 4,
            'evidence_requirement' => EvidenceRequirement::PHOTO,
            'cancellation_policy' => 'Dapat dibatalkan hingga 24 jam sebelum jadwal.',
            'is_active' => true,
        ]);

        Livewire::test(ProductDetail::class, ['product' => $product])
            ->assertSee('Tersedia') // or whichever real STOCKED label the implementation renders — adjust to match Step 3's actual copy
            ->assertSee('4') // stock quantity surfaces somewhere
            ->assertSee('Dapat dibatalkan hingga 24 jam sebelum jadwal.');
    }

    public function test_it_hides_stock_quantity_for_a_made_to_order_listing(): void
    {
        $product = Product::factory()->create();
        $vendor = Vendor::factory()->create();
        VendorListing::query()->create([
            'vendor_id' => $vendor->id,
            'product_id' => $product->id,
            'price_minor' => 500000,
            'availability_mode' => AvailabilityMode::MADE_TO_ORDER,
            'stock_quantity' => null,
            'production_lead_time_days' => 5,
            'evidence_requirement' => EvidenceRequirement::NONE,
            'is_active' => true,
        ]);

        $html = Livewire::test(ProductDetail::class, ['product' => $product])->html();

        $this->assertStringNotContainsString('stock_quantity', $html); // sanity guard, replace with a real assertion once Step 3's markup is known
    }
```

Adjust both tests' exact assertions once Step 3's real markup/copy is written — these are drafts to drive the implementation, not final. Use whatever factory this codebase's `Product`/`Vendor` classes already have (check `database/factories/` — if `VendorListing` has no factory, `VendorListing::query()->create([...])` directly, as above, is correct, matching this file's own migration having no `.constrained()` FK to fight).

- [ ] **Step 2b: Run to verify it fails**

```bash
$RUN vendor/bin/phpunit [the file found in Step 1] --filter test_it_shows_availability_stock_and_evidence_requirement_for_a_stocked_listing
```

Expected: FAIL — the new assertions don't match current output.

- [ ] **Step 3: Add the display block to the Blade view**

Modify `resources/views/livewire/public/marketplace/product-detail.blade.php`. Insert immediately after the existing `@if ($listing !== null)` block's vendor-name paragraph (after the `</p>` on the line containing `Ditawarkan oleh {{ $listing->vendor->name }}`, still inside that same `@if ($listing !== null)` branch, i.e. right before the closing `@else` of that block):

```blade
                    <dl class="mt-3 space-y-2 text-sm text-neutral-700">
                        <div class="flex flex-wrap items-center gap-2">
                            <dt class="text-neutral-600">Ketersediaan</dt>
                            <dd>
                                <x-mk.badge :intent="match ($listing->availability_mode) {
                                    \App\Domain\Marketplace\AvailabilityMode::STOCKED => 'success',
                                    \App\Domain\Marketplace\AvailabilityMode::MADE_TO_ORDER => 'info',
                                    \App\Domain\Marketplace\AvailabilityMode::SCHEDULED => 'info',
                                    default => 'neutral',
                                }">
                                    {{ match ($listing->availability_mode) {
                                        \App\Domain\Marketplace\AvailabilityMode::STOCKED => 'Tersedia (stok)',
                                        \App\Domain\Marketplace\AvailabilityMode::MADE_TO_ORDER => 'Dibuat sesuai pesanan',
                                        \App\Domain\Marketplace\AvailabilityMode::SCHEDULED => 'Dijadwalkan',
                                        default => $listing->availability_mode,
                                    } }}
                                </x-mk.badge>
                            </dd>
                        </div>

                        @if ($listing->availability_mode === \App\Domain\Marketplace\AvailabilityMode::STOCKED && $listing->stock_quantity !== null)
                            <div class="flex flex-wrap items-center gap-2">
                                <dt class="text-neutral-600">Stok</dt>
                                <dd class="font-medium text-neutral-900">{{ $listing->stock_quantity }} unit</dd>
                            </div>
                        @endif

                        @if ($listing->production_lead_time_days !== null)
                            <div class="flex flex-wrap items-center gap-2">
                                <dt class="text-neutral-600">Waktu pengerjaan</dt>
                                <dd class="font-medium text-neutral-900">{{ $listing->production_lead_time_days }} hari</dd>
                            </div>
                        @endif

                        @if ($listing->evidence_requirement !== \App\Domain\Marketplace\EvidenceRequirement::NONE)
                            <div class="flex flex-wrap items-center gap-2">
                                <dt class="text-neutral-600">Bukti penyelesaian</dt>
                                <dd class="font-medium text-neutral-900">
                                    {{ $listing->evidence_requirement === \App\Domain\Marketplace\EvidenceRequirement::PHOTO ? 'Vendor mengunggah foto' : 'Vendor mengunggah dokumen' }}
                                </dd>
                            </div>
                        @endif

                        @if ($listing->cancellation_policy)
                            <div>
                                <dt class="text-neutral-600">Kebijakan pembatalan</dt>
                                <dd class="mt-1 text-neutral-700">{{ $listing->cancellation_policy }}</dd>
                            </div>
                        @endif
                    </dl>
```

Confirm `<x-mk.badge>` really accepts an `intent` prop with `'neutral'` as a valid value (used elsewhere on this exact page at line ~96, `<x-mk.badge intent="neutral">`) — it does, per this file's own existing usage.

- [ ] **Step 4: Update the "ordering status" section's copy to stop claiming this data is unavailable**

The existing `@if ($listing !== null)` branch inside the "Ordering status" `<section>` (around the alert titled "Detail pesanan dikonfirmasi bersama vendor") currently says schedule/area/delivery-fee are "belum ditampilkan di halaman ini" (not yet shown). Availability/stock/evidence/cancellation-policy now ARE shown (Step 3) — schedule (in the literal sense of a delivery date) and delivery fee are still genuinely not shown (they depend on a service area the shopper hasn't picked yet). Narrow this alert's copy so it no longer implies availability/stock/evidence/cancellation-policy are also missing:

```blade
                <x-mk.alert intent="neutral" title="Detail pesanan dikonfirmasi bersama vendor" live="off">
                    <p>
                        Jadwal pengiriman atau pengerjaan dan biaya pengiriman ke area Anda dikonfirmasi bersama vendor setelah pesanan dibuat.
                    </p>
```

(Only the `<p>` text inside this specific alert changes — the rest of the section, the pending-state `@else` branch, and the support escape hatch stay exactly as they are.)

- [ ] **Step 5: Update this file's own header doc-comment to match the new reality**

The comment block (lines ~10-32) currently says "This page therefore cannot render a schedule, a delivery fee, or a real §6.2 'area unavailable' state — there is no data behind any of them" and "AC2 therefore remains PARTIAL after this batch." Both claims are now stale for 3 of the 5 originally-named fields (availability/stock/evidence-requirement are now rendered; cancellation-policy was always renderable and now is). Update the comment to state precisely what changed: availability mode, stock (when STOCKED), production lead time, evidence requirement, and cancellation policy are now rendered from `$listing`; schedule (a real delivery/service date) and delivery fee remain genuinely unavailable at this page (they depend on a service area not yet chosen) — AC2 is now MORE COMPLETE but still not FULL, and say so plainly rather than leaving the old, now-inaccurate "PARTIAL" framing unexplained.

- [ ] **Step 6: Run to verify the tests pass**

```bash
$RUN vendor/bin/phpunit [the file found in Step 1]
```

Expected: PASS, all tests in that file (adjust the 2 new tests' exact assertions to match the real rendered copy from Step 3 if they don't match verbatim — the copy strings above are this plan's best draft, not guaranteed pixel-for-pixel final).

- [ ] **Step 7: Run pint and phpstan**

```bash
$RUN vendor/bin/pint --test resources/views/livewire/public/marketplace/product-detail.blade.php [the test file from Step 1]
$RUN php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

Expected: both clean. (Blade files aren't PHPStan targets, but the test file is.)

- [ ] **Step 8: Run `ci/verify-docs.sh`**

```bash
bash ci/verify-docs.sh
```

This Blade file is scanned for hardcoded design values / arbitrary Tailwind values per `CLAUDE.md`'s scope note. Expected: all gates PASS — the new markup uses only existing utility classes and `<x-mk.badge>`, no arbitrary values.

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/public/marketplace/product-detail.blade.php [the test file from Step 1]
git commit -m "feat(marketplace): surface vendor listing availability, stock, and policy details"
```

---

### Task 5: Booking-wizard loading-state consistency

**Files:**
- Modify: `resources/views/livewire/public/booking/wizard.blade.php`

**Interfaces:**
- Consumes: nothing new — existing `wire:click`/`wire:target` values already in the file.
- Produces: nothing later depends on.

- [ ] **Step 1: Read the file's own 4 already-correct spinner examples once more to copy their exact markup**

```bash
sed -n '685,700p;1098,1125p;1260,1275p;1375,1395p' resources/views/livewire/public/booking/wizard.blade.php
```

Confirm each uses the shape `<span wire:loading wire:target="<action>" role="status" class="flex items-center gap-2 text-sm text-neutral-600"><x-mk.spinner class="size-4" aria-hidden="true" /> Memproses&hellip;</span>` (or very close to it — copy the REAL current markup verbatim, do not retype from memory).

- [ ] **Step 2: Add a spinner span for `selectCity` (single shared target across a `@foreach` loop)**

`selectCity` is rendered once per city inside a `@foreach ($cities as $cityOption)` loop (around line 183-198), all sharing `wire:target="selectCity"`. Do NOT put a spinner inside the loop (it would render one spinner per city, all appearing simultaneously during any single click — wrong). Add ONE spinner immediately after the closing `</ul>` of that loop (after line 198), matching the existing working examples' shape:

```blade
                    </ul>
                    <span wire:loading wire:target="selectCity" role="status" class="mt-2 flex items-center gap-2 text-sm text-neutral-600">
                        <x-mk.spinner class="size-4" aria-hidden="true" />
                        Memproses&hellip;
                    </span>
                @endif
```

(The `@endif` here is the existing one closing the `@else` branch that contains this `@foreach` — confirm the real current structure before inserting, since line numbers may have shifted slightly from Task 1-4's own edits to other files, though none of those touch this file.)

- [ ] **Step 3: Add spinner spans for `openPickerFor`/`selectCemetery` (2 shared targets, each spanning 2 loop locations)**

Both `openPickerFor` and `selectCemetery` each appear twice (once for a cemetery with no packages, once inside a nested `@foreach ($packages as $package)` loop for a cemetery with packages — lines ~324-374). Both instances of each action share one `wire:target`. Add ONE spinner for `openPickerFor` and ONE for `selectCemetery`, placed after the outer cemetery-list loop closes (find the `@endforeach` that closes the outer cemetery iteration this whole block lives inside, add both spans once, right after it) — not duplicated per cemetery or per package, for the same reason as Step 2.

- [ ] **Step 4: Add a spinner span for `holdPlotForDiscovery` (single button, not in a loop)**

Around line 460-465 — this is a standalone button, not repeated. Add the spinner directly adjacent to it (immediately after its closing `</x-mk.button>` tag), matching the 4 working examples' exact shape.

- [ ] **Step 5: Add a spinner span for `selectServiceType`**

Around line 517-521 — check whether this is inside a loop (multiple service-type options) or standalone. If it's a loop over service-type options sharing one `wire:target="selectServiceType"`, apply the same "one spinner after the loop" rule as Steps 2-3. If standalone, apply Step 4's rule.

- [ ] **Step 6: Confirm no double-spinner regression on the 4 already-correct targets**

Do not add a second spinner for `continueFromDiscovery`, `saveStep2`, `openOnlinePayment`, or `saveStep3` — these already have one each. Grep to confirm no duplicates were accidentally introduced:

```bash
grep -c 'wire:loading wire:target="continueFromDiscovery"' resources/views/livewire/public/booking/wizard.blade.php
grep -c 'wire:loading wire:target="saveStep2"' resources/views/livewire/public/booking/wizard.blade.php
grep -c 'wire:loading wire:target="openOnlinePayment"' resources/views/livewire/public/booking/wizard.blade.php
grep -c 'wire:loading wire:target="saveStep3"' resources/views/livewire/public/booking/wizard.blade.php
```

Expected: each returns `1` (unchanged from before this task).

- [ ] **Step 7: Run the existing booking-wizard Feature tests as a regression check**

```bash
find tests/Feature -ipath "*booking*wizard*" -o -ipath "*Booking*Wizard*"
$RUN vendor/bin/phpunit [every file found above]
```

Expected: PASS, no regressions — this task only adds visible markup, no `wire:click`/`wire:target`/component logic changed.

- [ ] **Step 8: Run pint and `ci/verify-docs.sh`**

```bash
$RUN vendor/bin/pint --test resources/views/livewire/public/booking/wizard.blade.php
bash ci/verify-docs.sh
```

Expected: both clean.

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/public/booking/wizard.blade.php
git commit -m "fix(booking-wizard): add missing loading spinners for 5 action targets"
```

---

### Task 6: `/akun` E2E Playwright suite (written, NOT executed)

**Files:**
- Create: `tests/browser/e2e-akun.spec.ts`

**Interfaces:**
- Consumes: real `/akun` routes (`route('login')` = `/masuk`, `route('akun.index')` = `/akun`, `route('akun.draft')` = `/akun/draft`, `route('akun.pesanan')` = `/akun/pesanan`, `route('akun.perpanjangan')` = `/akun/perpanjangan`, `route('akun.dokumen')` = `/akun/dokumen`), the real `LoginPage` field labels (`Email`, `Kata Sandi`, submit button text `Masuk`).
- Produces: nothing later depends on.

**This task CANNOT be executed in this environment — no `node_modules`, no browser, confirmed absent repeatedly this session.** Verify entirely by reading the real, current source cited below. Never report an execution result that did not happen; the task report must say "NOT EXECUTED — verified by reading real source" explicitly, matching this session's established precedent from the wizard-step-reduction work's own Playwright fixes.

- [ ] **Step 1: Confirm the real login flow and field labels are unchanged**

```bash
cat resources/views/livewire/public/auth/login-page.blade.php
```

Confirm: form fields are `x-mk.field` with `label="Email"` and `label="Kata Sandi"` (note: capital S in "Sandi" — this is `/masuk`'s own copy, DIFFERENT from Filament's admin/vendor login which this plan's research confirmed uses lowercase "Kata sandi" — do not copy `e2e-admin-vendor.spec.ts`'s selectors verbatim), submit button text `Masuk`, route path `/masuk` (not a named-route helper — Playwright navigates by literal path).

- [ ] **Step 2: Confirm the real `/akun` sub-page content to assert against**

```bash
cat app/Livewire/Public/Auth/AkunIndex.php 2>/dev/null || find app/Livewire -iname "AkunIndex.php"
cat app/Livewire/Public/Auth/DraftList.php 2>/dev/null || find app/Livewire -iname "DraftList.php"
cat app/Livewire/Public/Auth/OrderList.php 2>/dev/null || find app/Livewire -iname "OrderList.php"
cat app/Livewire/Public/Auth/RenewalList.php 2>/dev/null || find app/Livewire -iname "RenewalList.php"
cat app/Livewire/Public/Auth/DocumentList.php 2>/dev/null || find app/Livewire -iname "DocumentList.php"
```

(These classes may live under a different namespace than `App\Livewire\Public\Auth` — the `find` fallback locates them regardless. Read each real class + its Blade view.) Confirm the real, current literal strings this plan cites from `docs/product/screen-inventory.md`: `AkunIndex`'s 4-tile grid, `DraftList`'s empty state "Belum ada draft pemesanan.", `OrderList`'s empty state "Belum ada pesanan.", `RenewalList`/`DocumentList`'s gate-closed pages (`<x-mk.gate-closed-page>`). If any string has changed since this plan's research, use the real current string.

- [ ] **Step 3: Write the spec file**

```typescript
import { test, expect } from '@playwright/test';

/**
 * `/akun` customer account area — login, index, draft list, order list,
 * and the two gate-closed stub pages (perpanjangan, dokumen).
 *
 * Every string asserted against below is read directly from the real,
 * current source at the time this file was written — see the task's own
 * dispatch brief for exact file:line citations. If a real run of this
 * file (once a browser/node_modules environment is available) fails on a
 * string mismatch, the fix is almost certainly this file drifting from a
 * later UI change, not a real bug — re-read the live source before
 * assuming otherwise, matching this repo's `e2e-admin-vendor.spec.ts`
 * fixture-data discipline.
 *
 * Unlike `e2e-admin-vendor.spec.ts`'s Filament-panel login (`/admin/login`,
 * `/vendor/login`, both `Filament\Auth\Pages\Login`), `/akun` is a plain
 * Laravel-auth area — login is `/masuk` (`App\Livewire\Public\Auth\LoginPage`),
 * field labels are "Email" / "Kata Sandi" (capital S — different from
 * Filament's lowercase "Kata sandi"), submit button text "Masuk". No shared
 * rate-limit or session-caching helper from `support/admin-session.ts`
 * applies here — confirm at execution time whether `/masuk` has its own
 * rate limiter worth caching around; this file does a fresh login per test
 * file run (test.describe.configure({ mode: 'serial' }) if login attempts
 * need budgeting, matching e2e-admin-vendor.spec.ts's own reasoning).
 */

test.describe.configure({ mode: 'serial' });

async function registerAndLoginTestCustomer(page: import('@playwright/test').Page): Promise<void> {
  // This helper needs a real, seeded or freshly-registered customer account
  // to log in as. Read `/daftar`'s real RegisterPage fields (mirror
  // LoginPage's own field-reading discipline from Step 1) and either:
  //   (a) register a fresh throwaway account via the UI at test start, or
  //   (b) rely on a fixture account this repo's E2E setup already seeds
  //       (check `database/migrations/2026_08_22_100000_seed_e2e_admin_vendor_test_users.php`
  //       for precedent — is there an analogous customer-account seed, or
  //       does one need to be proposed as a follow-up rather than invented
  //       here unasked?).
  // This function body is intentionally left for the implementer to fill
  // in once (a) vs (b) is decided by reading the real fixture-seeding
  // migrations directory — do not guess a customer's email/password here.
  throw new Error('Fill in using this repo\'s real customer-account E2E fixture strategy — see the task brief.');
}

test.describe('akun — login', () => {
  test('a customer can log in and reach the account index', async ({ page }) => {
    await registerAndLoginTestCustomer(page);
    await expect(page).toHaveURL(/\/akun\/?$/);
    // Assert the real 4-tile grid heading/copy read in Step 2.
  });
});

test.describe('akun — with an authenticated customer session', () => {
  test('draft list shows the real empty state when there are no drafts', async ({ page }) => {
    await registerAndLoginTestCustomer(page);
    await page.goto('/akun/draft');
    await expect(page.getByText('Belum ada draft pemesanan.')).toBeVisible();
  });

  test('order list shows the real empty state when there are no orders', async ({ page }) => {
    await registerAndLoginTestCustomer(page);
    await page.goto('/akun/pesanan');
    await expect(page.getByText('Belum ada pesanan.')).toBeVisible();
  });

  test('renewal (akun) shows the gate-closed page with a working fallback link', async ({ page }) => {
    await registerAndLoginTestCustomer(page);
    await page.goto('/akun/perpanjangan');
    // Assert the real <x-mk.gate-closed-page> heading/copy read in Step 2,
    // and that its fallback link to the public renewal flow (PUB-030) is
    // present and points somewhere real — read RenewalList's real Blade
    // view for the exact href before asserting it.
  });

  test('document (akun) shows the gate-closed page', async ({ page }) => {
    await registerAndLoginTestCustomer(page);
    await page.goto('/akun/dokumen');
    // Assert the real <x-mk.gate-closed-page> heading/copy read in Step 2.
  });
});

test.describe('akun — guest access', () => {
  test('a guest visiting /akun is redirected to login with intended-URL round-trip', async ({ page }) => {
    await page.goto('/akun/draft');
    await expect(page).toHaveURL(/\/masuk/);
    // Log in, then assert the redirect lands back on /akun/draft — the
    // `redirectIntended(...)` behavior documented in routes/web.php's own
    // comment block for this route group.
  });
});
```

This file intentionally contains 2 unfilled sections (`registerAndLoginTestCustomer`'s body, and a few `// Assert the real ...` comments) — the task's own report must state explicitly which of these were resolved by reading real source during implementation vs. which remain genuinely open questions for whoever eventually runs this suite for the first time (e.g. "does a customer-account E2E fixture strategy already exist, or does this file's `registerAndLoginTestCustomer` need one proposed as a small follow-up before this suite can run at all").

- [ ] **Step 4: Resolve every `// Assert the real ...` placeholder and the fixture-strategy question by reading the real source**

Fill in every comment left in Step 3 with real, concrete assertions (exact heading text, exact link hrefs) — per this plan's own "No Placeholders" discipline, the file committed in Step 6 must not contain unresolved `// Assert the real ...` comments. Resolve `registerAndLoginTestCustomer`'s fixture strategy for real (read `database/migrations/` for any existing customer-account E2E seed; if none exists, implement option (a) — register a fresh throwaway account via the real `/daftar` UI at test start, reading `RegisterPage`'s real field labels the same way Step 1 read `LoginPage`'s).

- [ ] **Step 5: Confirm no execution happened, document that explicitly**

```bash
ls node_modules 2>&1
```

Expected: `No such file or directory` (or equivalent) — confirms execution genuinely isn't possible here. The task report must state this file's real check status as `NOT EXECUTED — verified by reading real source (routes/web.php:550, login-page.blade.php, and each /akun sub-page's real Livewire class + Blade view)`, never `PASS`.

- [ ] **Step 6: Commit**

```bash
git add tests/browser/e2e-akun.spec.ts
git commit -m "test(e2e): add /akun account area browser suite (unexecuted, verified by reading source)"
```

---

### Task 7: Documentation corrections in `docs/domain/traceability-matrix.md`

**Files:**
- Modify: `docs/domain/traceability-matrix.md`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing.

- [ ] **Step 1: Correct the v0.21 note's `CreateCemeteryBlock` claim (line 138)**

Read the real, current line 138 first (its exact wording may have shifted slightly by the time this task runs — earlier tasks in this plan don't touch this file, but confirm). It currently reads (in relevant part): *"...in particular that the aggregate-tier exclusion is an explicitly enforced check rather than a structural guarantee, because `CreateCemeteryBlock` does not currently refuse a block on an aggregate-tier cemetery; that pre-existing gap is recorded as open follow-up work and is claimed by no row."*

Per this repo's own convention (never delete a superseded note, append a correction), add a new sentence to the END of that same note (do not delete or rewrite the existing text):

> **Correction, 4 Sep 2026:** the `CreateCemeteryBlock` gap this note describes was closed the same day this note was written — `app/Domain/PlotInventory/Actions/CreateCemeteryBlock.php:86-91` now guards against creating a block on a non-`GRANULAR`-tier cemetery (commit `ca434723`, merged via PR #215, `fix/cemetery-block-tier-guard`). This note is kept verbatim above for its own historical record; the gap is closed, not open follow-up work.

- [ ] **Step 2: Correct the PLOT-03-widening section's identical claim (line 543)**

The "Scope of the claim" paragraph under "PLOT-03 evidence widened — 29 Aug 2026 (v0.21, Phase F booking-flow shortening)" makes the same now-stale claim: *"...`App\Domain\PlotInventory\Actions\CreateCemeteryBlock` does not refuse a block on an aggregate-tier cemetery... That gap belongs to the earlier cemetery-plot-tracking-mode phase, is **not** fixed by Phase F, and is **not** claimed as covered by any row here — it is recorded as open follow-up work."*

Add, at the end of that same paragraph:

> **Correction, 4 Sep 2026:** as recorded in this file's v0.21 revision note above, this gap was in fact closed the same day (PR #215) — this paragraph's characterization of it as unfixed open follow-up work is stale. Kept verbatim above for the historical record of what was true when Phase F's own scope was being reasoned about.

- [ ] **Step 3: Correct the MKT-01…03 scope note's marketplace-schema claim (line 330)**

The note currently reads: *"They do **not** claim AC2 completeness — schedule, service area, delivery fee, stock/availability, and evidence requirement have no column on `products` or `product_variants` (verified 08 Aug 2026)..."*

This was true on 8 Aug 2026 but is now stale — `vendor_listings` (created 12 Aug 2026) and `service_areas` (created 12 Aug 2026) carry 5 of these attributes, and as of this plan's Task 4, availability/stock/production-lead-time/evidence-requirement/cancellation-policy are genuinely rendered on the public product-detail page. Add, at the end of that same sentence (before the next sentence about MKT-04/MKT-05):

> **Correction, 4 Sep 2026:** this clause is now stale on two counts. First, `vendor_listings` (`2026_08_12_100020_create_vendor_listings_table.php`) and `service_areas` (`2026_08_12_100030_create_service_areas_table.php`) do carry availability mode, stock quantity, production lead time, cancellation policy, evidence requirement, service area, and delivery fee — just not as columns on `products`/`product_variants` directly, which is what this 8 Aug 2026 note actually checked. Second, as of `docs/superpowers/plans/2026-09-04-pre-demo-known-gaps.md` Task 4, the public product-detail page (PUB-021) now renders availability mode, stock (when applicable), production lead time, evidence requirement, and cancellation policy from `$listing`. Schedule (a real delivery/service date) and delivery fee remain genuinely unshown at this page — they depend on a service area the shopper has not yet chosen — so AC2 is more complete than this note implies but still not full.

- [ ] **Step 4: Run `ci/verify-docs.sh`**

```bash
bash ci/verify-docs.sh
```

Expected: all 13 gates PASS — this is a pure prose addition to an existing Markdown file, following the file's own established correction-note convention exactly (Gate 5's "spec structural integrity" and Gate 7's "traceability 'Covered' rows name a real test file" checks should be unaffected, since no row's status or evidence cell changes, only prose notes gain corrections).

- [ ] **Step 5: Commit**

```bash
git add docs/domain/traceability-matrix.md
git commit -m "docs(traceability): correct 2 stale notes (CreateCemeteryBlock guard, marketplace listing fields)"
```

---

## Final Whole-Branch Review

After all 7 tasks are complete, per `superpowers:subagent-driven-development`: dispatch the final whole-branch code reviewer on the most capable available model. Give it this plan and the spec, and ask it to specifically check, beyond the normal review rubric:

1. Every `ServiceComplaint` status transition genuinely goes through `StartInvestigatingComplaint`/`ResolveComplaint`/`DismissComplaint` — grep the whole diff for any raw `ServiceComplaint::query()->update(...)` or `$complaint->status = ...` outside those 3 Actions.
2. `CreateMakeGood`'s 3 new optional parameters are genuinely backward-compatible — every pre-existing call site (the domain test, and `CareSubscriptionExampleData` if it calls this Action) still passes with zero changes.
3. `ResolveComplaint`'s `CreateMakeGood` call and the complaint's own status/`make_good_order_id` update genuinely happen inside the SAME `Audit::wrap()` mutation closure (same transaction) — a partial failure must not leave a `MakeGoodOrder` created but the complaint still `Open`/`Investigating`.
4. `ServiceComplaintsResource`'s authorization genuinely denies `CUSTOMER`/`VENDOR`/`CEMETERY_OPERATOR`/`CASE_MANAGER` and allows `ADMIN`/`RESTRICTED_ADMIN`/`OPERATOR`/`FINANCE` — re-verify Task 3's access-matrix test actually exercises this, not just asserts a mocked/stubbed authorizer.
5. Task 5's booking-wizard fix: confirm each of the 4 already-correct spinner spans still appears exactly once (no accidental duplication), and each of the 5 newly-added spans is genuinely placed once per shared `wire:target` (not once per loop iteration) — a regression here would visually break the wizard in a way no automated test catches (Task 5 has no new test by design).
6. Task 6's Playwright file contains zero unresolved placeholder comments and its task report honestly states `NOT EXECUTED`, never `PASS`, for anything Playwright-related.
7. `docs/domain/traceability-matrix.md`'s 3 corrections (Task 7) are appended, not destructive rewrites of the original notes — confirm the original v0.21/MKT-01…03 text is still present verbatim above each correction.

Once the final review is clean, hand off to `superpowers:finishing-a-development-branch`: push and open a PR (never merge without the human sign-off `AGENTS.md` requires — this branch touches admin authorization surfaces and a real audit-attribution change, both flagged categories).
