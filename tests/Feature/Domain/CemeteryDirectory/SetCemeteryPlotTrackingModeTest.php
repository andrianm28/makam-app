<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\Actions\SetCemeteryPlotTrackingMode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Actions\CreateCemeteryBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class SetCemeteryPlotTrackingModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_switches_aggregate_to_granular(): void
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::AGGREGATE]);

        $result = app(SetCemeteryPlotTrackingMode::class)(
            $cemetery,
            PlotTrackingMode::GRANULAR,
            'user:1',
            'admin',
        );

        $this->assertSame(PlotTrackingMode::GRANULAR, $result->plot_tracking_mode);
        $this->assertSame(PlotTrackingMode::GRANULAR, $cemetery->fresh()->plot_tracking_mode);
        $this->assertDatabaseHas('audit_events', ['action' => 'CEMETERY_PLOT_TRACKING_MODE_CHANGED']);
    }

    public function test_refuses_granular_to_aggregate_while_blocks_exist(): void
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);
        app(CreateCemeteryBlock::class)($cemetery, 'BLOK-G', 'Blok G', 1, 'user:1', 'operator');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Cannot switch cemetery [{$cemetery->getKey()}] to 'aggregate' mode: ".
            '1 cemetery block(s) still exist for it.'
        );

        app(SetCemeteryPlotTrackingMode::class)($cemetery, PlotTrackingMode::AGGREGATE, 'user:1', 'admin');
    }

    public function test_allows_granular_to_aggregate_when_no_blocks_exist(): void
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);

        $result = app(SetCemeteryPlotTrackingMode::class)($cemetery, PlotTrackingMode::AGGREGATE, 'user:1', 'admin');

        $this->assertSame(PlotTrackingMode::AGGREGATE, $result->plot_tracking_mode);
    }

    public function test_same_state_transition_is_a_safe_no_op(): void
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::AGGREGATE]);

        $result = app(SetCemeteryPlotTrackingMode::class)($cemetery, PlotTrackingMode::AGGREGATE, 'user:1', 'admin');

        $this->assertSame(PlotTrackingMode::AGGREGATE, $result->plot_tracking_mode);
        $this->assertDatabaseMissing('audit_events', ['action' => 'CEMETERY_PLOT_TRACKING_MODE_CHANGED']);
    }

    public function test_rejects_an_unknown_target_mode(): void
    {
        $cemetery = Cemetery::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown plot tracking mode [hybrid]. Known modes: aggregate, granular.');

        app(SetCemeteryPlotTrackingMode::class)($cemetery, 'hybrid', 'user:1', 'admin');
    }
}
