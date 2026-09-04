<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\CareSubscription\Actions\CreateCarePlan;
use App\Domain\CareSubscription\Actions\CreateSubscription;
use App\Domain\CareSubscription\Actions\MarkCyclePaid;
use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\VendorFulfillment\Actions\AcceptService;
use App\Domain\VendorFulfillment\Actions\AssignWorkOrder;
use App\Domain\VendorFulfillment\Actions\CompleteTask;
use App\Domain\VendorFulfillment\Actions\CreateWorkOrderFromCycle;
use App\Domain\VendorFulfillment\Actions\FileComplaint;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\WorkOrderStatus;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;

/**
 * Three care subscriptions for one demo customer/grave, driven through the
 * real 6-7-action chain (`CreateCarePlan` -> `CreateSubscription` ->
 * `MarkCyclePaid` -> `CreateWorkOrderFromCycle` -> `AssignWorkOrder` ->
 * `CompleteTask` per task -> `AcceptService`/`FileComplaint`), the densest
 * generator in this subsystem:
 *
 * 1. Active, one accepted service — the full chain through `AcceptService`.
 * 2. Active, one filed complaint — the same chain through `CompleteTask`,
 *    then `FileComplaint` instead of `AcceptService` (CARE-SUB-06's real,
 *    tested complaint path).
 * 3. Draft/unpaid — `CreateCarePlan` -> `CreateSubscription` only, stopping
 *    before `MarkCyclePaid`, showing the "just signed up" state.
 *
 * ---------------------------------------------------------------------------
 * Only `CarePlan` and `Subscription` are `TaggedAsDemoData` — confirmed by
 * reading the migration, not assumed
 * ---------------------------------------------------------------------------
 * `2026_09_03_150000_add_demo_batch_id_for_demo_seed_data.php`'s `TABLES`
 * list adds `demo_batch_id` to `care_plans`, `subscriptions` and fifteen
 * other tables — it deliberately does NOT include `subscription_cycles`,
 * `work_orders`, `work_order_tasks`, `service_acceptances`, or
 * `service_complaints`. Calling `TaggedAsDemoData::tag()` on any of those
 * five would fail against real Postgres with "column demo_batch_id does not
 * exist". Each of those rows carries a foreign key back to a tagged
 * `Subscription` (via `subscription_id`/`subscription_cycle_id`/
 * `work_order_id`, transitively), so a purge keyed off the already-tagged
 * `subscriptions`/`care_plans` rows is the coherent removal path this
 * subsystem's table selection implies — the same shape
 * `MarketplaceOrderExampleData`'s doc block already establishes for
 * `vendor_listings`/`service_areas`.
 *
 * ---------------------------------------------------------------------------
 * Confirmed real gap: no Action ever transitions `WorkOrder.status` to
 * Completed
 * ---------------------------------------------------------------------------
 * Confirmed by grepping for every write of `WorkOrderStatus::Completed`
 * anywhere in `app/` — none exists. `AcceptService`'s own doc block assumes
 * a "completed work order" as a precondition it never itself creates. This
 * is a genuine, pre-existing gap in the application's own domain modeling,
 * not something this generator invents (flagged separately for the broader
 * UAT sweep — a `WorkOrder` may never visibly read as "Selesai" anywhere in
 * the app today, only in this generator's own demo data). Resolved here,
 * narrowly, the same way `VendorAccountExampleData` resolves the
 * vendor-account gap: a direct write, placed after every task on the work
 * order is completed via `CompleteTask` and before `AcceptService`/
 * `FileComplaint` is called on it.
 *
 * ---------------------------------------------------------------------------
 * `UploadEvidence` is deliberately excluded from this generator
 * ---------------------------------------------------------------------------
 * It hard-requires a real `documents` row with `state === Accepted`, which
 * requires driving DocumentVault's real upload+scan pipeline —
 * `config('document-vault.malware_scanner')` resolves to `null` outside the
 * `development` environment, so there is currently no way to produce a real
 * Accepted document on beta at all without either bypassing a real security
 * control or depending on a pipeline that does not function there. Faking
 * an Accepted `documents` row via direct insert would misrepresent that a
 * real scan happened — this generator does not do that. This is a real,
 * separate platform finding (certificate/evidence document uploads may not
 * work at all on the beta host today outside development), independent of
 * any seed data, reported to the broader UAT sweep rather than worked
 * around here.
 */
final class CareSubscriptionExampleData
{
    private const string ACTOR_REF = 'demo-data-seeder';

    private const string ACTOR_ROLE = 'system';

    /**
     * @return list<Subscription>
     */
    public static function seed(string $batchId, int $customerId, string $graveId): array
    {
        $vendor = self::demoVendor($batchId);
        $carePlan = self::demoCarePlan($vendor, $batchId);

        return [
            self::activeWithAcceptedService($carePlan, $vendor, $customerId, $graveId, $batchId),
            self::activeWithFiledComplaint($carePlan, $vendor, $customerId, $graveId, $batchId),
            self::draftUnpaid($carePlan, $customerId, $graveId, $batchId),
        ];
    }

    private static function demoVendor(string $batchId): Vendor
    {
        $vendor = Vendor::query()->create([
            'name' => 'Vendor Perawatan Makam Contoh',
            'is_active' => true,
        ]);
        TaggedAsDemoData::tag($vendor, $batchId);

        return $vendor;
    }

    private static function demoCarePlan(Vendor $vendor, string $batchId): CarePlan
    {
        $carePlan = app(CreateCarePlan::class)(
            name: 'Paket Perawatan Rutin Contoh',
            productCode: 'GRAVE_CARE_MONTHLY',
            frequency: CarePlanFrequency::Monthly,
            priceMinor: 250_000,
            vendorId: $vendor->id,
            checklistTemplate: [
                ['name' => 'Membersihkan area makam', 'required_evidence' => true],
                ['name' => 'Mengganti bunga segar', 'required_evidence' => false],
            ],
            actorRef: self::ACTOR_REF,
            actorRole: self::ACTOR_ROLE,
        );
        TaggedAsDemoData::tag($carePlan, $batchId);

        return $carePlan;
    }

    private static function createSubscription(CarePlan $carePlan, int $customerId, string $graveId, string $batchId): Subscription
    {
        $subscription = app(CreateSubscription::class)(
            carePlan: $carePlan,
            graveId: $graveId,
            customerId: $customerId,
            frequency: $carePlan->frequency(),
            actorReference: self::ACTOR_REF,
            actorRole: self::ACTOR_ROLE,
        );
        TaggedAsDemoData::tag($subscription, $batchId);

        return $subscription->fresh();
    }

    private static function markFirstCyclePaid(Subscription $subscription): SubscriptionCycle
    {
        $cycle = $subscription->cycles()->firstOrFail();
        $amountMinor = (int) $cycle->invoice->amount_minor;

        return app(MarkCyclePaid::class)(
            cycle: $cycle,
            amountMinor: $amountMinor,
            paidSourceRef: sprintf('demo-care-payment-%s', $cycle->getKey()),
            actorReference: self::ACTOR_REF,
        );
    }

    private static function createAssignedWorkOrder(SubscriptionCycle $cycle, CarePlan $carePlan, Vendor $vendor): WorkOrder
    {
        $workOrder = app(CreateWorkOrderFromCycle::class)($cycle, $carePlan);

        return app(AssignWorkOrder::class)(
            workOrder: $workOrder,
            vendorId: $vendor->id,
            actorReference: self::ACTOR_REF,
        );
    }

    private static function completeAllTasksAndMarkWorkOrderDone(WorkOrder $workOrder): WorkOrder
    {
        foreach ($workOrder->tasks as $task) {
            app(CompleteTask::class)($task, self::ACTOR_REF, 'vendor');
        }

        // Confirmed gap (2026-09-03 plan research): no real Action transitions
        // WorkOrder.status to Completed anywhere in this codebase —
        // AcceptService's own doc block assumes it as a precondition it never
        // creates. Narrow, explicit exception to "always use real Actions",
        // matching this same generator's need for a work order that actually
        // reads as finished in a demo. Flagged separately as a real product
        // gap, not silently normalized.
        $workOrder->forceFill(['status' => WorkOrderStatus::Completed->value])->save();

        return $workOrder->fresh();
    }

    private static function activeWithAcceptedService(CarePlan $carePlan, Vendor $vendor, int $customerId, string $graveId, string $batchId): Subscription
    {
        $subscription = self::createSubscription($carePlan, $customerId, $graveId, $batchId);
        $cycle = self::markFirstCyclePaid($subscription);
        $workOrder = self::createAssignedWorkOrder($cycle, $carePlan, $vendor);
        $workOrder = self::completeAllTasksAndMarkWorkOrderDone($workOrder);

        app(AcceptService::class)(
            workOrder: $workOrder,
            customerId: $customerId,
            rating: 5,
            notes: 'Pekerjaan rapi dan tepat waktu.',
        );

        return $subscription->fresh();
    }

    private static function activeWithFiledComplaint(CarePlan $carePlan, Vendor $vendor, int $customerId, string $graveId, string $batchId): Subscription
    {
        $subscription = self::createSubscription($carePlan, $customerId, $graveId, $batchId);
        $cycle = self::markFirstCyclePaid($subscription);
        $workOrder = self::createAssignedWorkOrder($cycle, $carePlan, $vendor);
        $workOrder = self::completeAllTasksAndMarkWorkOrderDone($workOrder);

        app(FileComplaint::class)(
            workOrder: $workOrder,
            customerId: $customerId,
            complaintText: 'Bunga yang diganti terlihat layu, mohon ditindaklanjuti.',
        );

        return $subscription->fresh();
    }

    private static function draftUnpaid(CarePlan $carePlan, int $customerId, string $graveId, string $batchId): Subscription
    {
        return self::createSubscription($carePlan, $customerId, $graveId, $batchId);
    }
}
