<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Domain\OrderWorkflow\OrderStatus;
use App\Support\ExampleData\BookingOrderExampleData;
use App\Support\ExampleData\CertificateExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CertificateExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_an_issued_and_a_revoked_certificate(): void
    {
        $batchId = (string) Str::uuid();
        $order = BookingOrderExampleData::seed($batchId)[2]; // the DIBAYAR one
        $this->assertSame(OrderStatus::DIBAYAR->value, $order->status()->value);

        $certificates = CertificateExampleData::seed($batchId, $order);

        $this->assertCount(2, $certificates);
        $this->assertSame('issued', $certificates[0]->fresh()->status);
        $this->assertSame('revoked', $certificates[1]->fresh()->status);
        foreach ($certificates as $certificate) {
            $this->assertSame($batchId, $certificate->fresh()->demo_batch_id);
        }
    }
}
