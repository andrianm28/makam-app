<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotInventory;

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PlotInventory\Actions\CreateCemeteryBlock;
use App\Domain\PlotInventory\PlotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreateCemeteryBlockTest extends TestCase
{
    use RefreshDatabase;

    private function cemetery(): Cemetery
    {
        return Cemetery::factory()->create();
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
}
