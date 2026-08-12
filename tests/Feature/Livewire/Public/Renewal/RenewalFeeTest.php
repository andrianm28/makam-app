<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Renewal;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Livewire\Public\Renewal\RenewalFee;
use App\Platform\FeatureGate\Models\FeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class RenewalFeeTest extends TestCase
{
    use RefreshDatabase;

    private function openTheDataGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open']);
    }

    private function cemeteryWithPrice(): Cemetery
    {
        return Cemetery::query()->where('slug', 'tpu-jakarta-menteng')->sole();
    }

    public function test_the_fee_screen_always_shows_the_tariff_source_and_last_update(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->assertOk()
            ->assertSee('Sumber tarif')
            ->assertSee('Terakhir diperbarui');
    }

    public function test_no_late_fine_figure_is_rendered_when_there_is_no_written_basis(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $cemetery->id,
            'due_date' => now()->subYears(3)->format('Y-m-d'),
        ]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->assertOk()
            ->assertDontSee('Denda');
    }

    public function test_the_fee_screen_shows_the_renewal_amount(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->assertOk()
            ->assertSee('Rp');
    }

    public function test_a_grave_without_a_tariff_source_renders_a_useful_error(): void
    {
        $this->openTheDataGate();
        $cemetery = Cemetery::query()->where('slug', 'tpu-jakarta-menteng')->sole();

        DB::table('cemeteries')
            ->where('id', $cemetery->id)
            ->update(['price_min' => null, 'price_source' => null, 'price_effective_at' => null]);

        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->assertOk()
            ->assertSee('tarif');
    }

    public function test_the_stepper_shows_step_4_as_current(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);

        $component = Livewire::test(RenewalFee::class, ['makam' => $grave->id]);

        $component->assertSee('Langkah 4 dari 6');

        foreach (['Kota', 'TPU/TPS', 'Cari Makam', 'Biaya', 'Pembayaran', 'Konfirmasi'] as $label) {
            $component->assertSee($label);
        }
    }

    public function test_support_escape_hatch_is_present(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->assertOk()
            ->assertSee('/bantuan');
    }
}
