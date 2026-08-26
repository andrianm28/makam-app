<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotInventory;

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Actions\CreateCemeteryBlock;
use App\Domain\PlotInventory\PlotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreateCemeteryBlockTest extends TestCase
{
    use RefreshDatabase;

    private function cemetery(): Cemetery
    {
        return Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);
    }

    public function test_creates_block_and_generates_capacity_plots(): void
    {
        $cemetery = $this->cemetery();

        $block = app(CreateCemeteryBlock::class)(
            $cemetery,
            'BLOK-A',
            'Blok A',
            3,
            'user:1',
            'operator',
        );

        $this->assertSame('BLOK-A', $block->code);
        $this->assertSame(3, $block->capacity);
        $this->assertSame(3, $block->plots()->count());
        $this->assertSame(['001', '002', '003'], $block->plots()->orderBy('slot')->pluck('slot')->all());
        foreach ($block->plots as $plot) {
            $this->assertSame(PlotState::AVAILABLE, $plot->plot_state);
        }
        $this->assertDatabaseHas('audit_events', ['action' => 'CEMETERY_BLOCK_CREATED']);
        $this->assertDatabaseHas('audit_events', ['action' => 'GRAVE_PLOTS_GENERATED']);
    }

    public function test_code_is_normalized_to_uppercase(): void
    {
        $block = app(CreateCemeteryBlock::class)($this->cemetery(), 'blok-b', 'Blok B', 1, 'user:1', 'operator');
        $this->assertSame('BLOK-B', $block->code);
    }

    public function test_capacity_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(CreateCemeteryBlock::class)($this->cemetery(), 'BLOK-C', 'Blok C', 0, 'user:1', 'operator');
    }

    public function test_class_link_is_applied_to_generated_plots(): void
    {
        $cemetery = $this->cemetery();
        $package = $cemetery->packages()->create([
            'name' => 'Paket Utama',
            'availability_status' => CemeteryPackageAvailabilityStatus::AVAILABLE,
            'is_active' => true,
        ]);

        $block = app(CreateCemeteryBlock::class)($cemetery, 'BLOK-D', 'Blok D', 2, 'user:1', 'operator', $package->getKey());

        $this->assertSame($package->getKey(), $block->plots()->first()->cemetery_package_id);
    }

    public function test_refuses_to_create_block_for_an_aggregate_tier_cemetery(): void
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::AGGREGATE]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Cannot create a block for cemetery [{$cemetery->getKey()}]: it is tracked in 'aggregate' mode. ".
            "Switch it to 'granular' via SetCemeteryPlotTrackingMode first."
        );

        app(CreateCemeteryBlock::class)($cemetery, 'BLOK-E', 'Blok E', 1, 'user:1', 'operator');
    }

    public function test_allows_creating_block_for_a_granular_tier_cemetery(): void
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);

        $block = app(CreateCemeteryBlock::class)($cemetery, 'BLOK-F', 'Blok F', 1, 'user:1', 'operator');

        $this->assertSame('BLOK-F', $block->code);
    }
}
