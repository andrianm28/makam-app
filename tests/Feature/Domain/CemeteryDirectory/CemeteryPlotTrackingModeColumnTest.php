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

    public function test_a_freshly_constructed_instance_reads_the_default_without_refreshing(): void
    {
        $cemetery = Cemetery::factory()->create();

        // Deliberately NOT calling ->fresh() here — this is exactly the
        // gap the final review found: an un-refreshed instance must still
        // read the correct default, not null.
        $this->assertSame(PlotTrackingMode::AGGREGATE, $cemetery->plot_tracking_mode);
    }
}
