<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Support\ExampleData\VisitationExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VisitationExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_bookings_across_three_statuses(): void
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Contoh Kunjungan',
            'slug' => 'tpu-contoh-kunjungan-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $batchId = (string) Str::uuid();

        $bookings = VisitationExampleData::seed($batchId, $cemetery);

        $statuses = array_map(static fn ($booking): string => $booking->status, $bookings);
        $this->assertContains('requested', $statuses);
        $this->assertContains('confirmed', $statuses);
        $this->assertContains('cancelled', $statuses);

        foreach ($bookings as $booking) {
            $this->assertSame($batchId, $booking->fresh()->demo_batch_id);
        }
    }
}
