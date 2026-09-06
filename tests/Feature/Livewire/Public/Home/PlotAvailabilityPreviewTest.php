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
use Illuminate\Support\Facades\DB;
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
        // The cemetery has a real block with a real plot, so the ONLY thing
        // that can hide it is the tier guard itself — a cemetery with no
        // blocks would also be filtered out by the unrelated
        // $blocks->isEmpty() check below it, hiding a deleted tier guard
        // (verified: this test failed once, against a mutant with the tier
        // guard removed, before this block/plot setup was added).
        $aggregate = $this->makeCemetery('tpu-aggregate-only', PlotTrackingMode::AGGREGATE);

        CemeteryBlock::query()->create([
            'cemetery_id' => $aggregate->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 1,
        ])->plots()->create(['slot' => '001', 'plot_state' => PlotState::AVAILABLE]);

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

    public function test_a_second_render_within_the_cache_ttl_does_not_re_query(): void
    {
        $cemetery = $this->makeCemetery('tpu-granular-cache-check', PlotTrackingMode::GRANULAR);

        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 1,
        ]);

        GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '001',
            'plot_state' => PlotState::AVAILABLE,
        ]);

        config(['marketing.homepage_plot_preview_cemetery_slugs' => ['tpu-granular-cache-check']]);

        Livewire::test(PlotAvailabilityPreview::class)->assertSee('BLOK-A');

        DB::enableQueryLog();

        Livewire::test(PlotAvailabilityPreview::class)->assertSee('BLOK-A');

        $queries = DB::getQueryLog();

        $this->assertEmpty(
            array_filter($queries, static fn (array $q): bool => str_contains($q['query'], 'cemetery_blocks')),
            'Expected the second render within the cache TTL to read from cache, not re-query cemetery_blocks.',
        );
    }

    public function test_changing_the_configured_slug_list_bypasses_the_stale_cache_key(): void
    {
        $first = $this->makeCemetery('tpu-cache-key-a', PlotTrackingMode::GRANULAR);
        CemeteryBlock::query()->create([
            'cemetery_id' => $first->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 1,
        ])->plots()->create(['slot' => '001', 'plot_state' => PlotState::AVAILABLE]);

        $second = $this->makeCemetery('tpu-cache-key-b', PlotTrackingMode::GRANULAR);
        CemeteryBlock::query()->create([
            'cemetery_id' => $second->getKey(),
            'code' => 'BLOK-B',
            'name' => 'Blok B',
            'capacity' => 1,
        ])->plots()->create(['slot' => '001', 'plot_state' => PlotState::AVAILABLE]);

        config(['marketing.homepage_plot_preview_cemetery_slugs' => ['tpu-cache-key-a']]);
        Livewire::test(PlotAvailabilityPreview::class)->assertSee('BLOK-A')->assertDontSee('BLOK-B');

        config(['marketing.homepage_plot_preview_cemetery_slugs' => ['tpu-cache-key-b']]);
        Livewire::test(PlotAvailabilityPreview::class)->assertSee('BLOK-B')->assertDontSee('BLOK-A');
    }
}
