<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking;

use App\Domain\Booking\BookingDraftQuery;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BookingDraftQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_returns_null_for_an_unknown_id(): void
    {
        $this->assertNull(BookingDraftQuery::find('00000000-0000-0000-0000-000000000000'));
    }

    public function test_find_returns_null_for_a_non_uuid_string(): void
    {
        $this->assertNull(BookingDraftQuery::find('not-a-uuid'));
    }

    public function test_find_returns_the_draft_for_a_real_id(): void
    {
        $draft = BookingDraft::create(['city_code' => 'JAKARTA']);

        $found = BookingDraftQuery::find($draft->id);

        $this->assertNotNull($found);
        $this->assertSame($draft->id, $found->id);
    }

    public function test_summary_of_an_empty_draft_has_no_lines_and_no_total(): void
    {
        $draft = BookingDraft::create([]);

        $summary = BookingDraftQuery::summary($draft);

        $this->assertSame([], $summary['lines']);
        $this->assertNull($summary['total']);
        $this->assertTrue($summary['all_prices_available']);
    }

    public function test_summary_computes_line_totals_and_a_total_when_prices_exist(): void
    {
        PriceVersion::query()
            ->where('priceable_type', ServiceDefinition::class)
            ->whereHasMorph('priceable', [ServiceDefinition::class], fn ($q) => $q->where('code', 'DOCUMENT_PROCESSING'))
            ->whereNull('superseded_at')
            ->update(['superseded_at' => now()]);

        $service = ServiceDefinition::query()->where('code', 'DOCUMENT_PROCESSING')->firstOrFail();

        PriceVersion::create([
            'priceable_type' => ServiceDefinition::class,
            'priceable_id' => $service->id,
            'version_number' => 2,
            'amount' => 150000,
            'currency' => 'IDR',
            'effective_from' => now(),
            'superseded_at' => null,
        ]);

        $draft = BookingDraft::create([
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
            ],
        ]);

        $summary = BookingDraftQuery::summary($draft);

        $this->assertCount(1, $summary['lines']);
        $this->assertSame('DOCUMENT_PROCESSING', $summary['lines'][0]['code']);
        $this->assertSame(1, $summary['lines'][0]['quantity']);
        $this->assertSame(150000.0, $summary['lines'][0]['unit_price']);
        $this->assertSame(150000.0, $summary['lines'][0]['line_total']);
        $this->assertSame(150000.0, $summary['total']);
        $this->assertTrue($summary['all_prices_available']);
    }

    public function test_summary_marks_a_missing_price_honestly_instead_of_fabricating_a_total(): void
    {
        PriceVersion::query()
            ->where('priceable_type', ServiceDefinition::class)
            ->whereHasMorph('priceable', [ServiceDefinition::class], fn ($q) => $q->where('code', 'GRAVE_DIGGING'))
            ->whereNull('superseded_at')
            ->update(['superseded_at' => now()]);

        $draft = BookingDraft::create([
            'selected_services' => [
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ],
        ]);

        $summary = BookingDraftQuery::summary($draft);

        $this->assertNull($summary['lines'][0]['unit_price']);
        $this->assertNull($summary['lines'][0]['line_total']);
        $this->assertNull($summary['total']);
        $this->assertFalse($summary['all_prices_available']);
    }
}
