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
use App\Livewire\Public\Home\PlotPreviewMisconfiguredException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
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

    /**
     * OFF-ON-PURPOSE stays silent.
     *
     * An empty setting is the shipped default, and it means an operator has
     * not pointed this section at anything yet. That is a state, not a fault,
     * and reporting it would train whoever reads the error tracker to ignore
     * this signal.
     */
    public function test_an_empty_configuration_is_silent(): void
    {
        Exceptions::fake();

        config(['marketing.homepage_plot_preview_cemetery_slugs' => []]);

        Livewire::test(PlotAvailabilityPreview::class)
            ->assertDontSee('Lihat Contoh Ketersediaan Plot');

        Exceptions::assertNotReported(PlotPreviewMisconfiguredException::class);
    }

    /**
     * CONFIGURED-BUT-BROKEN is reported — the whole point of this pair.
     *
     * Measured on 14 Sep 2026, the shipped default named two slugs that
     * existed in neither the dev database nor the public beta's. The section
     * rendered nothing on both hosts and NOTHING SAID SO, which is
     * indistinguishable from the test above. The visitor still sees nothing
     * here — a missing section is the right page either way, and a public
     * page is no place for a configuration error — but an operator now hears
     * about it.
     */
    public function test_a_configuration_that_resolves_to_nothing_is_reported(): void
    {
        Exceptions::fake();

        config(['marketing.homepage_plot_preview_cemetery_slugs' => ['tpu-tidak-ada']]);

        Livewire::test(PlotAvailabilityPreview::class)
            ->assertDontSee('Lihat Contoh Ketersediaan Plot');

        Exceptions::assertReported(
            fn (PlotPreviewMisconfiguredException $e): bool => in_array('tpu-tidak-ada', $e->slugs, true)
        );
    }

    /**
     * The report NAMES the slugs, because "something resolved to nothing" is
     * not actionable and "tpu-petamburan resolved to nothing" is one query
     * away from a fix.
     */
    public function test_the_report_names_the_offending_slugs(): void
    {
        Exceptions::fake();

        config(['marketing.homepage_plot_preview_cemetery_slugs' => ['tpu-petamburan', 'tpu-karet-bivak']]);

        Livewire::test(PlotAvailabilityPreview::class);

        Exceptions::assertReported(function (PlotPreviewMisconfiguredException $e): bool {
            return str_contains($e->getMessage(), 'tpu-petamburan')
                && str_contains($e->getMessage(), 'tpu-karet-bivak')
                && str_contains($e->getMessage(), 'HOMEPAGE_PLOT_PREVIEW_CEMETERY_SLUGS');
        });
    }

    /**
     * A WORKING configuration reports nothing. Without this, all three tests
     * above would pass against a component that reported on every render.
     */
    public function test_a_working_configuration_reports_nothing(): void
    {
        Exceptions::fake();

        $cemetery = $this->makeCemetery('tpu-granular-ok', PlotTrackingMode::GRANULAR);

        CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 1,
        ])->plots()->create(['slot' => '001', 'plot_state' => PlotState::AVAILABLE]);

        config(['marketing.homepage_plot_preview_cemetery_slugs' => ['tpu-granular-ok']]);

        Livewire::test(PlotAvailabilityPreview::class)
            ->assertSee('Lihat Contoh Ketersediaan Plot');

        Exceptions::assertNotReported(PlotPreviewMisconfiguredException::class);
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
