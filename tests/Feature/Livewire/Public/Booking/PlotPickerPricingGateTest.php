<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\CemeteryCapability\Actions\RecordCemeteryPackagePriceVersion;
use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec D4 and D5.
 *
 * Both directions are asserted on purpose: a gate that only ever closes passes
 * a "it is closed" test while testing nothing. The priced case must open.
 */
final class PlotPickerPricingGateTest extends TestCase
{
    use RefreshDatabase;

    private function makeCemetery(): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Gerbang Harga',
            'slug' => 'tpu-uji-gerbang-harga-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => PlotTrackingMode::GRANULAR,
        ]);
    }

    private function makePackage(Cemetery $cemetery): CemeteryPackage
    {
        return CemeteryPackage::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'name' => 'Makam Single',
            'availability_status' => CemeteryPackageAvailabilityStatus::AVAILABLE,
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    private function priceIt(CemeteryPackage $package): void
    {
        app(RecordCemeteryPackagePriceVersion::class)($package, '4500000.00', 'user:1', 'Penetapan harga awal');
    }

    private function makePlotIn(Cemetery $cemetery, ?int $cemeteryPackageId = null): GravePlot
    {
        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'A-1',
            'name' => 'Blok A-1',
            'capacity' => 1,
        ]);

        return GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '001',
            'plot_state' => PlotState::AVAILABLE,
            'cemetery_package_id' => $cemeteryPackageId,
        ]);
    }

    private function draftIdAtDiscovery(): string
    {
        return (new StartBookingDraft)(null)->id;
    }

    private function wizardAtPickerWithUnpricedPackage(): Testable
    {
        $cemetery = $this->makeCemetery();
        $package = $this->makePackage($cemetery);
        $this->makePlotIn($cemetery, $package->getKey());
        $draftId = $this->draftIdAtDiscovery();

        return Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openPickerFor', $cemetery->id, $package->getKey());
    }

    private function wizardAtPickerWhoseSelectedPackageWasDeleted(): Testable
    {
        $cemetery = $this->makeCemetery();
        $package = $this->makePackage($cemetery);
        $this->makePlotIn($cemetery, $package->getKey());
        $deletedPackageId = $package->getKey();
        $package->delete();
        $draftId = $this->draftIdAtDiscovery();

        return Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openPickerFor', $cemetery->id, $deletedPackageId);
    }

    private function wizardAtPickerWithPricedPackage(): Testable
    {
        $cemetery = $this->makeCemetery();
        $package = $this->makePackage($cemetery);
        $this->priceIt($package);
        $this->makePlotIn($cemetery, $package->getKey());
        $draftId = $this->draftIdAtDiscovery();

        return Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openPickerFor', $cemetery->id, $package->getKey());
    }

    public function test_the_picker_offers_nothing_before_a_package_is_selected(): void
    {
        $cemetery = $this->makeCemetery();
        $this->makePlotIn($cemetery);
        $draftId = $this->draftIdAtDiscovery();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openPickerFor', $cemetery->id);

        $component->assertSee('Pilih paket terlebih dahulu');
    }

    public function test_the_picker_offers_nothing_when_the_selected_package_has_no_firm_price(): void
    {
        $component = $this->wizardAtPickerWithUnpricedPackage();

        $component->assertSee('Harga paket ini belum tersedia');
    }

    /**
     * The other half of what used to be one compound condition.
     *
     * Deleting the `$package === null` branch leaves every other test green:
     * the unpriced-package test cannot reach it, because its package exists.
     */
    public function test_a_selection_naming_a_package_that_no_longer_exists_asks_for_a_package_again(): void
    {
        $component = $this->wizardAtPickerWhoseSelectedPackageWasDeleted();

        $component->assertSee('Pilih paket terlebih dahulu');
        $component->assertDontSee('Harga paket ini belum tersedia');
    }

    public function test_the_picker_offers_every_plot_when_the_package_is_priced(): void
    {
        $component = $this->wizardAtPickerWithPricedPackage();

        $component->assertDontSee('Harga paket ini belum tersedia');
        $component->assertSee('A-1');
    }

    /**
     * Review finding M-3: `pickerBlocks()` resets `$pickerBlocksUnavailable`
     * first thing on every call, but `$pickerUnpricedReason` was left
     * untouched by the first early return (`pickerCemeteryId === null ||
     * ! pickerAppliesTo(...)`). A client-supplied non-granular cemetery id
     * following an unpriced-package render used to leave the section
     * showing a stale "Harga paket ini belum tersedia".
     */
    public function test_switching_to_a_non_granular_cemetery_clears_a_stale_unpriced_reason(): void
    {
        $component = $this->wizardAtPickerWithUnpricedPackage();
        $component->assertSee('Harga paket ini belum tersedia');
        self::assertSame('no-price', $component->instance()->pickerUnpricedReason);

        $aggregate = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Agregat',
            'slug' => 'tpu-uji-agregat-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 2',
            'plot_tracking_mode' => PlotTrackingMode::AGGREGATE,
        ]);

        $component->call('openPickerFor', $aggregate->id);

        self::assertNull($component->instance()->pickerUnpricedReason);
        $component->assertDontSee('Harga paket ini belum tersedia');
    }
}
