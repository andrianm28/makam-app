<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Home;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Livewire\Public\Home\PlotAvailabilityPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class PlotAvailabilityPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeCemetery(string $slug, string $trackingMode): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba',
            'slug' => $slug,
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => $trackingMode,
        ]);
    }

    public function test_renders_nothing_when_no_configured_cemetery_is_granular(): void
    {
        $this->makeCemetery('tpu-aggregate-only', PlotTrackingMode::AGGREGATE);

        config(['marketing.homepage_plot_preview_cemetery_slugs' => ['tpu-aggregate-only']]);

        Livewire::test(PlotAvailabilityPreview::class)
            ->assertDontSee('Lihat Contoh Ketersediaan Plot');
    }

    public function test_renders_the_badge_grid_for_a_granular_cemetery_with_plots(): void
    {
        $cemetery = $this->makeCemetery('tpu-granular-showcase', PlotTrackingMode::GRANULAR);

        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 2,
        ]);

        GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '001',
            'plot_state' => PlotState::AVAILABLE,
        ]);

        GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '002',
            'plot_state' => PlotState::RESERVED,
        ]);

        config(['marketing.homepage_plot_preview_cemetery_slugs' => ['tpu-granular-showcase']]);

        Livewire::test(PlotAvailabilityPreview::class)
            ->assertSee('TPU Uji Coba')
            ->assertSee('BLOK-A')
            ->assertSee('001')
            ->assertSee('Tersedia')
            ->assertSee('002')
            ->assertSee('Dipesan');
    }

    public function test_skips_a_configured_slug_that_does_not_resolve(): void
    {
        config(['marketing.homepage_plot_preview_cemetery_slugs' => [
            'tpu-'.Str::lower(Str::random(10)),
        ]]);

        Livewire::test(PlotAvailabilityPreview::class)
            ->assertDontSee('Lihat Contoh Ketersediaan Plot');
    }
}
