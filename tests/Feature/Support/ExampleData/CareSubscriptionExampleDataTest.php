<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Models\User;
use App\Support\ExampleData\CareSubscriptionExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareSubscriptionExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_subscriptions_across_three_states(): void
    {
        $batchId = (string) Str::uuid();
        $customer = User::query()->create([
            'name' => 'Contoh Pelanggan',
            'email' => 'demo.customer.care@example.com',
            'password' => Hash::make('DemoContoh2026!'),
        ]);
        $grave = GraveRecord::query()->firstOrFail();

        $subscriptions = CareSubscriptionExampleData::seed($batchId, $customer->id, $grave->id);

        $this->assertCount(3, $subscriptions);
        foreach ($subscriptions as $subscription) {
            $this->assertSame($batchId, $subscription->fresh()->demo_batch_id);
        }

        $this->assertDatabaseHas('service_acceptances', ['customer_id' => $customer->id]);
        $this->assertDatabaseHas('service_complaints', ['customer_id' => $customer->id]);
    }
}
