<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Support\ExampleData\BookingOrderExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BookingOrderExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_orders_across_the_five_named_states(): void
    {
        $batchId = (string) Str::uuid();

        $orders = BookingOrderExampleData::seed($batchId);

        $statuses = array_map(static fn ($order): string => $order->status()->value, $orders);

        $this->assertContains(OrderStatus::DIVERIFIKASI->value, $statuses);
        $this->assertContains(OrderStatus::PENAWARAN_TERKIRIM->value, $statuses);
        $this->assertContains(OrderStatus::DIBAYAR->value, $statuses);
        $this->assertContains(OrderStatus::SELESAI->value, $statuses);
        $this->assertContains(OrderStatus::DITOLAK->value, $statuses);

        foreach ($orders as $order) {
            $this->assertSame($batchId, $order->fresh()->demo_batch_id);
        }
    }

    public function test_every_seeded_order_uses_safe_contact_data(): void
    {
        $batchId = (string) Str::uuid();

        BookingOrderExampleData::seed($batchId);

        $this->assertDatabaseMissing('booking_drafts', [
            'customer_email' => null,
        ]);

        $drafts = BookingDraft::query()->where('demo_batch_id', $batchId)->get();
        foreach ($drafts as $draft) {
            $this->assertMatchesRegularExpression('/@example\.(com|org|net)$/', (string) $draft->customer_email);
            $this->assertMatchesRegularExpression('/^08118990\d{4}$/', (string) $draft->customer_mobile);
        }
    }
}
