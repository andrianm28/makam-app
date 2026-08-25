<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\PlotInventory;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CemeteryBlockModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_plot_delete_blocked_when_not_available(): void
    {
        $cemetery = Cemetery::factory()->create();
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => PlotState::OCCUPIED]);

        $this->expectException(\InvalidArgumentException::class);
        $plot->delete();
    }

    public function test_plot_state_must_be_known(): void
    {
        $cemetery = Cemetery::factory()->create();
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);

        $this->expectException(\InvalidArgumentException::class);
        GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'bogus']);
    }
}
