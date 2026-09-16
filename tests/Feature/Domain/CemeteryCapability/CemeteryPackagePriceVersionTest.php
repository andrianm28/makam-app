<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryCapability;

use App\Domain\CemeteryCapability\Actions\RecordCemeteryPackagePriceVersion;
use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Audit\SensitiveActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Stage 0, level 1 of
 * `docs/superpowers/plans/2026-09-13-bayar-penuh-di-muka-online-saja.md`: a
 * grave package can carry a firm, bookable price, versioned and audited the
 * same way a service fee is.
 *
 * The assertions that matter most here are the ones about MONEY NOT MOVING
 * SILENTLY: that a reprice supersedes rather than overwrites, that a package
 * can never end up with two current prices, and that a price cannot be
 * recorded without a reason. Those are the properties the pay-in-full-upfront
 * flow will rest its charges on.
 */
final class CemeteryPackagePriceVersionTest extends TestCase
{
    use RefreshDatabase;

    private function package(): CemeteryPackage
    {
        $cemetery = Cemetery::create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Harga',
            'slug' => 'tpu-uji-harga',
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh Uji No. 1',
        ]);

        return CemeteryPackage::create([
            'cemetery_id' => $cemetery->id,
            'name' => 'Makam Single',
            'class_label' => 'Kelas A',
            'availability_status' => CemeteryPackageAvailabilityStatus::AVAILABLE,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function record(CemeteryPackage $p, string $amount, string $reason = 'Penetapan harga awal'): void
    {
        app(RecordCemeteryPackagePriceVersion::class)($p, $amount, 'user:1', $reason);
    }

    public function test_a_package_starts_with_no_price_and_that_is_a_real_state(): void
    {
        $this->assertNull(
            $this->package()->currentPriceVersion(),
            'An unpriced package must return null, not zero — a caller has to refuse to quote it.'
        );
    }

    public function test_it_records_a_firm_price_the_package_can_read_back(): void
    {
        $p = $this->package();
        $this->record($p, '4500000.00');

        $current = $p->fresh()->currentPriceVersion();
        $this->assertNotNull($current);
        $this->assertSame('4500000.00', $current->amount);
        $this->assertSame('IDR', $current->currency);
        $this->assertSame(1, $current->version_number);
        $this->assertNull($current->superseded_at);
    }

    /**
     * The core append-only property. A reprice must leave the old figure
     * readable — an operator asked "what did we charge in September?" must get
     * an answer, not a row that was overwritten.
     */
    public function test_repricing_supersedes_the_previous_version_instead_of_overwriting_it(): void
    {
        $p = $this->package();
        $this->record($p, '4500000.00');
        $this->record($p, '5000000.00', 'Kenaikan tarif 2027');

        $all = $p->fresh()->priceVersions()->orderBy('version_number')->get();

        $this->assertCount(2, $all, 'Repricing must add a row, never replace one.');
        $this->assertSame('4500000.00', $all[0]->amount);
        $this->assertNotNull($all[0]->superseded_at, 'The old price must be stamped superseded.');
        $this->assertSame('5000000.00', $all[1]->amount);
        $this->assertNull($all[1]->superseded_at);
        $this->assertSame(2, $all[1]->version_number);
    }

    public function test_a_package_never_has_more_than_one_current_price(): void
    {
        $p = $this->package();
        foreach (['1000000.00', '2000000.00', '3000000.00'] as $amount) {
            $this->record($p, $amount, 'Perubahan harga');
        }

        $this->assertSame(
            1,
            $p->fresh()->priceVersions()->whereNull('superseded_at')->count(),
            'Two open price rows would make "what is the price?" ambiguous at the moment of charging.'
        );
        $this->assertSame('3000000.00', $p->fresh()->currentPriceVersion()->amount);
    }

    public function test_a_price_cannot_be_recorded_without_a_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->record($this->package(), '4500000.00', '   ');
    }

    public function test_it_refuses_a_zero_price(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->record($this->package(), '0.00');
    }

    public function test_it_refuses_an_amount_the_column_would_silently_round(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->record($this->package(), '4500000.999');
    }

    /**
     * The audit action must be package-specific, not the generic or the
     * service one — an operator reading the trail has to tell a burial plot
     * reprice from an ambulance-fee reprice without joining tables.
     */
    public function test_it_writes_a_package_specific_audited_reason(): void
    {
        $p = $this->package();
        $this->record($p, '4500000.00', 'Harga resmi dari pengelola TPU');

        $event = AuditEvent::query()
            ->where('action', 'CEMETERY_PACKAGE_PRICE_VERSION_RECORDED')
            ->latest('id')->first();

        $this->assertNotNull($event, 'A money change with no audit row is the failure this guards against.');
        $this->assertSame('cemetery_package', $event->subject_type);
        $this->assertSame((string) $p->id, $event->subject_id);
        $this->assertSame('Harga resmi dari pengelola TPU', $event->reason);
    }

    public function test_the_action_name_is_registered_as_requiring_a_reason(): void
    {
        $this->assertTrue(
            SensitiveActions::requiresReason('CEMETERY_PACKAGE_PRICE_VERSION_RECORDED'),
            'If this is not on the sensitive list, a price could be changed with no recorded justification.'
        );
    }

    /**
     * The indicative range and the firm price are different things and must
     * not bleed into each other — the range is a hedged marketing figure, the
     * price is what a customer is charged.
     */
    public function test_recording_a_price_leaves_the_indicative_range_untouched(): void
    {
        $p = $this->package();
        $p->forceFill(['price_min' => '3000000.00', 'price_max' => '6000000.00'])->save();

        $this->record($p, '4500000.00');

        $fresh = $p->fresh();
        $this->assertSame('3000000.00', $fresh->price_min);
        $this->assertSame('6000000.00', $fresh->price_max);
        $this->assertSame('4500000.00', $fresh->currentPriceVersion()->amount);
    }
}
