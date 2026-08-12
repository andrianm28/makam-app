<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Renewal;

use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalStatus;
use App\Livewire\Public\Renewal\RenewalConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class RenewalConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_confirmation_shows_reference_status_and_invoice_state(): void
    {
        $renewal = Renewal::factory()->create(['reference' => 'PPJ-0007', 'status' => RenewalStatus::MENUNGGU_PEMBAYARAN]);

        Livewire::test(RenewalConfirmation::class, ['perpanjangan' => $renewal->id])
            ->assertOk()
            ->assertSee('PPJ-0007')
            ->assertSee('Menunggu pembayaran');
    }

    public function test_reloading_the_confirmation_never_creates_a_second_renewal(): void
    {
        $renewal = Renewal::factory()->create();

        Livewire::test(RenewalConfirmation::class, ['perpanjangan' => $renewal->id]);
        Livewire::test(RenewalConfirmation::class, ['perpanjangan' => $renewal->id]);

        $this->assertSame(1, Renewal::query()->count());
    }

    public function test_an_unsettled_renewal_does_not_claim_a_new_due_date(): void
    {
        $renewal = Renewal::factory()->create(['status' => RenewalStatus::MENUNGGU_PEMBAYARAN, 'settled_at' => null]);

        Livewire::test(RenewalConfirmation::class, ['perpanjangan' => $renewal->id])
            ->assertOk()
            ->assertDontSee('Jatuh tempo baru');
    }

    public function test_a_settled_renewal_shows_the_new_due_date(): void
    {
        $renewal = Renewal::factory()->create([
            'status' => RenewalStatus::DIBAYAR,
            'settled_at' => now(),
            'target_due_period' => '2027-03-01',
        ]);

        Livewire::test(RenewalConfirmation::class, ['perpanjangan' => $renewal->id])
            ->assertOk()
            ->assertSee('01 Mar 2027');
    }

    public function test_an_unknown_renewal_id_shows_an_error(): void
    {
        Livewire::test(RenewalConfirmation::class, ['perpanjangan' => '00000000-0000-0000-0000-000000000000'])
            ->assertOk()
            ->assertSee('tidak ditemukan');
    }

    public function test_support_escape_hatch_is_present(): void
    {
        $renewal = Renewal::factory()->create();

        Livewire::test(RenewalConfirmation::class, ['perpanjangan' => $renewal->id])
            ->assertOk()
            ->assertSee('/bantuan');
    }
}
