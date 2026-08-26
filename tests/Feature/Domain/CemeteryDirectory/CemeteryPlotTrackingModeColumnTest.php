<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CemeteryPlotTrackingModeColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_column_exists_and_defaults_to_aggregate(): void
    {
        $this->assertTrue(Schema::hasColumn('cemeteries', 'plot_tracking_mode'));

        $cemetery = Cemetery::factory()->create();

        $this->assertSame(PlotTrackingMode::AGGREGATE, $cemetery->fresh()->plot_tracking_mode);
    }

    public function test_mode_is_mass_assignable(): void
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);

        $this->assertSame(PlotTrackingMode::GRANULAR, $cemetery->fresh()->plot_tracking_mode);
    }
}
