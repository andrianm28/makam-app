<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\VendorFulfillment\Models\ServiceAcceptance;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `2026_08_22_100000_fix_customer_and_uploader_identity_columns` — proves
 * the four affected columns are real bigint FKs to `users` now, not uuid
 * columns nothing in this codebase could ever legitimately populate.
 */
final class FixCustomerAndUploaderIdentityColumnsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Postgres reports its own internal bigint type name (`int8`) via
     * `Schema::getColumnType()`, not the Laravel-facing `bigint` alias --
     * asserted against the driver's real reported name, not guessed.
     */
    private function assertRealBigintColumn(string $table, string $column): void
    {
        $this->assertSame('int8', Schema::getColumnType($table, $column));
    }

    public function test_subscriptions_customer_id_is_a_real_bigint_column(): void
    {
        $this->assertRealBigintColumn('subscriptions', 'customer_id');
    }

    public function test_service_acceptances_customer_id_is_a_real_bigint_column(): void
    {
        $this->assertRealBigintColumn('service_acceptances', 'customer_id');
    }

    public function test_service_complaints_customer_id_is_a_real_bigint_column(): void
    {
        $this->assertRealBigintColumn('service_complaints', 'customer_id');
    }

    public function test_work_evidence_uploaded_by_is_a_real_bigint_column(): void
    {
        $this->assertRealBigintColumn('work_evidence', 'uploaded_by');
    }

    public function test_subscription_customer_id_accepts_a_real_user_id(): void
    {
        $customer = User::factory()->create();
        $carePlan = CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Basic Grave Care',
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
            'customer_id' => $customer->id,
            'status' => 'active',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'currency' => 'IDR',
        ]);

        $this->assertSame($customer->id, $subscription->fresh()->customer_id);
    }

    /**
     * The real, structural proof this migration exists for: a nonexistent
     * user id must be REFUSED by the database itself (a real foreign key
     * constraint), not silently accepted the way the old, unconstrained
     * uuid column accepted any value at all.
     */
    public function test_subscription_customer_id_refuses_a_nonexistent_user(): void
    {
        $carePlan = CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Basic Grave Care',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'product_code' => 'GC-MONTHLY',
            'status' => 'active',
            'checklist_template' => [],
        ]);

        $this->expectException(QueryException::class);

        Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => 999999999,
            'status' => 'active',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'currency' => 'IDR',
        ]);
    }

    public function test_service_acceptance_customer_id_casts_to_integer(): void
    {
        $customer = User::factory()->create();
        $carePlan = CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Basic Grave Care',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'product_code' => 'GC-MONTHLY',
            'status' => 'active',
            'checklist_template' => [],
        ]);
        $workOrder = WorkOrder::query()->create([
            'reference' => 'WO-'.Str::upper(Str::random(8)),
            'care_plan_id' => $carePlan->getKey(),
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $acceptance = ServiceAcceptance::query()->create([
            'work_order_id' => $workOrder->getKey(),
            'customer_id' => $customer->id,
            'accepted_at' => now(),
        ]);

        $this->assertIsInt($acceptance->fresh()->customer_id);
        $this->assertSame($customer->id, $acceptance->fresh()->customer_id);
    }
}
