<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\VendorFulfillment\Actions\CreateWorkOrderFromCycle;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DB-03 (batch M3a):
 * `2026_09_07_100000_add_missing_fk_constraints_vendor_fulfillment_care_
 * subscription.php` gives the VendorFulfillment/CareSubscription module
 * real database-level referential integrity for the first time. This test
 * asserts against `information_schema` (real Postgres FK metadata — this
 * gate is meaningless on SQLite) that every constraint the migration
 * claims to add actually exists with the right `ON DELETE` rule, plus one
 * behavioural proof each for the CASCADE and RESTRICT paths using real
 * models/Actions (`makam-testing`: no domain factories).
 */
final class AddMissingFkConstraintsVendorFulfillmentCareSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{table: string, referenced_table: string, delete_rule: string}|null
     */
    private function foreignKeyInfo(string $constraintName): ?array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT
                tc.table_name AS table_name,
                ccu.table_name AS referenced_table,
                rc.delete_rule AS delete_rule
            FROM information_schema.table_constraints tc
            JOIN information_schema.referential_constraints rc
                ON rc.constraint_name = tc.constraint_name
                AND rc.constraint_schema = tc.constraint_schema
            JOIN information_schema.constraint_column_usage ccu
                ON ccu.constraint_name = tc.constraint_name
                AND ccu.constraint_schema = tc.constraint_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
                AND tc.constraint_name = ?
        SQL, [$constraintName]);

        return $row === null ? null : (array) $row;
    }

    public static function constraintProvider(): array
    {
        return [
            'work_orders.care_plan_id' => ['work_orders_care_plan_id_fk', 'work_orders', 'care_plans', 'RESTRICT'],
            'work_orders.subscription_cycle_id' => ['work_orders_subscription_cycle_id_fk', 'work_orders', 'subscription_cycles', 'RESTRICT'],
            'work_orders.vendor_id' => ['work_orders_vendor_id_fk', 'work_orders', 'vendors', 'RESTRICT'],
            'work_orders.assigned_to' => ['work_orders_assigned_to_fk', 'work_orders', 'vendors', 'RESTRICT'],
            'work_order_tasks.work_order_id' => ['work_order_tasks_work_order_id_fk', 'work_order_tasks', 'work_orders', 'CASCADE'],
            'work_evidence.work_order_id' => ['work_evidence_work_order_id_fk', 'work_evidence', 'work_orders', 'CASCADE'],
            'work_evidence.document_id' => ['work_evidence_document_id_fk', 'work_evidence', 'documents', 'RESTRICT'],
            'service_acceptances.work_order_id' => ['service_acceptances_work_order_id_fk', 'service_acceptances', 'work_orders', 'CASCADE'],
            'service_complaints.work_order_id' => ['service_complaints_work_order_id_fk', 'service_complaints', 'work_orders', 'CASCADE'],
            'make_good_orders.original_work_order_id' => ['make_good_orders_original_work_order_id_fk', 'make_good_orders', 'work_orders', 'RESTRICT'],
            'make_good_orders.replacement_work_order_id' => ['make_good_orders_replacement_work_order_id_fk', 'make_good_orders', 'work_orders', 'RESTRICT'],
            'make_good_orders.original_cycle_id' => ['make_good_orders_original_cycle_id_fk', 'make_good_orders', 'subscription_cycles', 'RESTRICT'],
            'care_plans.vendor_id' => ['care_plans_vendor_id_fk', 'care_plans', 'vendors', 'RESTRICT'],
            // SET NULL, not RESTRICT — breaks a real circular-FK deadlock
            // with `subscription_invoices.subscription_cycle_id`'s existing
            // RESTRICT; see the migration's own doc block.
            'subscription_cycles.work_order_id' => ['subscription_cycles_work_order_id_fk', 'subscription_cycles', 'work_orders', 'SET NULL'],
            'subscription_cycles.invoice_id' => ['subscription_cycles_invoice_id_fk', 'subscription_cycles', 'subscription_invoices', 'SET NULL'],
        ];
    }

    #[DataProvider('constraintProvider')]
    public function test_the_constraint_exists_with_the_expected_delete_rule(
        string $constraintName,
        string $table,
        string $referencedTable,
        string $deleteRule,
    ): void {
        $info = $this->foreignKeyInfo($constraintName);

        $this->assertNotNull($info, "expected a real FK constraint named [{$constraintName}] on [{$table}]");
        $this->assertSame($table, $info['table_name']);
        $this->assertSame($referencedTable, $info['referenced_table']);
        $this->assertSame($deleteRule, $info['delete_rule']);
    }

    private function makeCarePlan(): CarePlan
    {
        return CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Basic Grave Care',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'product_code' => 'GC-MONTHLY',
            'status' => 'active',
            'checklist_template' => [['name' => 'Bersihkan makam', 'required_evidence' => true]],
        ]);
    }

    private function makeCycle(CarePlan $carePlan): SubscriptionCycle
    {
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

        return SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth(),
            'cycle_end' => now(),
            'status' => 'PAID',
        ]);
    }

    public function test_deleting_a_work_order_cascades_to_its_tasks(): void
    {
        $carePlan = $this->makeCarePlan();
        $cycle = $this->makeCycle($carePlan);

        $workOrder = app(CreateWorkOrderFromCycle::class)($cycle, $carePlan);

        $this->assertGreaterThan(0, DB::table('work_order_tasks')->where('work_order_id', $workOrder->getKey())->count());

        WorkOrder::query()->whereKey($workOrder->getKey())->delete();

        $this->assertSame(0, DB::table('work_order_tasks')->where('work_order_id', $workOrder->getKey())->count());
    }

    public function test_deleting_a_care_plan_still_referenced_by_a_work_order_is_restricted(): void
    {
        $carePlan = $this->makeCarePlan();
        $cycle = $this->makeCycle($carePlan);

        app(CreateWorkOrderFromCycle::class)($cycle, $carePlan);

        $this->expectException(QueryException::class);

        DB::table('care_plans')->where('id', $carePlan->getKey())->delete();
    }
}
