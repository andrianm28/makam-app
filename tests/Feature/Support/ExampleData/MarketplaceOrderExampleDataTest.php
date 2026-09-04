<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Support\ExampleData\MarketplaceOrderExampleData;
use App\Support\ExampleData\VendorAccountExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MarketplaceOrderExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_marketplace_orders_for_the_given_vendor(): void
    {
        $batchId = (string) Str::uuid();
        $vendor = VendorAccountExampleData::seed($batchId)['vendors'][0];

        $orders = MarketplaceOrderExampleData::seed($batchId, $vendor);

        $this->assertNotEmpty($orders);
        foreach ($orders as $order) {
            $this->assertSame($vendor->id, $order->vendor_id);
            $this->assertSame($batchId, $order->fresh()->demo_batch_id);

            // `MarketplaceOrder` carries no `recipient_email` column — the
            // recipient contact fields `PlaceMarketplaceOrder` takes are
            // written onto `vendor_orders` (`customer_name`/`customer_phone`/
            // `customer_email`), not the order root. See
            // `VendorOrder::customer_email` and `PlaceMarketplaceOrderTest`'s
            // own assertions for the confirmed real shape.
            $vendorOrder = $order->vendorOrders()->firstOrFail();
            $this->assertMatchesRegularExpression('/@example\.(com|org|net)$/', $vendorOrder->customer_email);
        }
    }
}
