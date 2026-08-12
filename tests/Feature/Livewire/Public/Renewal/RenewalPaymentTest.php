<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Renewal;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalQuote;
use App\Livewire\Public\Renewal\RenewalPayment;
use App\Platform\FeatureGate\Models\FeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class RenewalPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function openThePaymentGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'open']);
    }

    private function closeThePaymentGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'closed']);
    }

    private function cemeteryWithPrice(): Cemetery
    {
        return Cemetery::query()->where('slug', 'tpu-jakarta-menteng')->sole();
    }

    private function createRenewalWithQuote(): Renewal
    {
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);
        $renewal = Renewal::factory()->create(['grave_record_id' => $grave->id]);
        RenewalQuote::factory()->accepted()->create(['renewal_id' => $renewal->id]);

        return $renewal;
    }

    public function test_the_payment_screen_shows_manual_coordination_when_gate_is_closed(): void
    {
        $this->closeThePaymentGate();
        $renewal = $this->createRenewalWithQuote();

        Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
            ->assertOk()
            ->assertSee('Pembayaran')
            ->assertSee('koordinasi manual');
    }

    public function test_the_payment_step_is_never_removed_when_gate_is_closed(): void
    {
        $this->closeThePaymentGate();
        $renewal = $this->createRenewalWithQuote();

        Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
            ->assertOk()
            ->assertSee('Langkah 5 dari 6');

        foreach (['Kota', 'TPU/TPS', 'Cari Makam', 'Biaya', 'Pembayaran', 'Konfirmasi'] as $label) {
            Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
                ->assertSee($label);
        }
    }

    public function test_the_payment_screen_shows_manual_coordination_when_gate_is_open(): void
    {
        $this->openThePaymentGate();
        $renewal = $this->createRenewalWithQuote();

        Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
            ->assertOk()
            ->assertSee('Pembayaran')
            ->assertSee('koordinasi manual');
    }

    public function test_an_unknown_renewal_id_shows_an_error(): void
    {
        Livewire::test(RenewalPayment::class, ['perpanjangan' => '00000000-0000-0000-0000-000000000000'])
            ->assertOk()
            ->assertSee('tidak ditemukan');
    }

    public function test_support_escape_hatch_is_present(): void
    {
        $this->openThePaymentGate();
        $renewal = $this->createRenewalWithQuote();

        Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
            ->assertOk()
            ->assertSee('/bantuan');
    }
}
