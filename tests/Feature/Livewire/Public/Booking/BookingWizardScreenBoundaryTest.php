<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\BookingPaymentMethod;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Each of the four steps renders its own screen, and nothing else.
 *
 * `currentScreen()` is an identity function now that steps and screens
 * converge 1:1, so asserting the mapping alone would prove almost nothing.
 * What still needs proving — and what the step reduction actually changed —
 * is that the MARKUP behind each `@if ($this->currentScreen() === N)` block
 * in `wizard.blade.php` really renders for the step that reaches it, and that
 * the screens are mutually exclusive. Every step below is reached through the
 * real save path, never by hand-setting `$currentStep`.
 */
final class BookingWizardScreenBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function atDiscovery(): Testable
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        return Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->call('selectCemetery', $cemetery->id)
            ->call('selectServiceType', BookingServiceType::NEW_GRAVE);
    }

    private function atCustomerAndDeceasedData(): Testable
    {
        $draftId = $this->atDiscovery()->call('continueFromDiscovery')->get('draftId');

        return Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('currentStep', BookingWizardStep::CUSTOMER_AND_DECEASED_DATA);
    }

    private function atPayment(): Testable
    {
        return $this->atCustomerAndDeceasedData()
            ->set('customerFullName', 'Test User')
            ->set('customerMobile', '081234567890')
            ->set('customerEmail', 'test@example.com')
            ->set('customerAddress', 'Jl. Contoh No. 1')
            ->set('customerRelationship', 'PASANGAN')
            ->set('customerContactChannel', 'WHATSAPP')
            ->set('privacyNoticeAccepted', true)
            ->set('deceasedFullName', 'Almarhum Test')
            ->set('deceasedDateOfBirth', '1980-05-10')
            ->set('deceasedDateOfDeath', '2026-08-01')
            ->set('deceasedRelationship', 'PASANGAN')
            ->set('deceasedGender', 'LAKI_LAKI')
            ->call('saveStep2')
            ->assertSet('currentStep', BookingWizardStep::PAYMENT);
    }

    public function test_screen_1_renders_the_discovery_sections_and_no_later_screen(): void
    {
        $this->atDiscovery()
            ->assertSee('Langkah 1 &mdash; Cari &amp; Pilih', false)
            ->assertSee('Pilih Lokasi')
            ->assertDontSee('Ringkasan Pesanan')
            ->assertDontSee('Langkah 3 &mdash; Pembayaran', false)
            ->assertDontSee('Langkah 4 &mdash; Konfirmasi', false);
    }

    public function test_screen_2_renders_the_merged_data_form_with_the_ringkasan_card(): void
    {
        $this->atCustomerAndDeceasedData()
            ->assertSee('Langkah 2 &mdash; Data Pemesan &amp; Data Almarhum', false)
            ->assertSee('Ringkasan Pesanan')
            ->assertSee('Nama Lengkap Almarhum')
            // ONE form for both halves — the merged step has a single save.
            ->assertSeeHtml('wire:submit="saveStep2"')
            ->assertDontSee('Langkah 1 &mdash; Cari &amp; Pilih', false)
            ->assertDontSee('Langkah 3 &mdash; Pembayaran', false);
    }

    public function test_screen_3_renders_the_payment_screen(): void
    {
        $this->atPayment()
            ->assertSee('Langkah 3 &mdash; Pembayaran', false)
            ->assertSeeHtml("saveStep3('".BookingPaymentMethod::MANUAL."')")
            ->assertDontSee('Langkah 2 &mdash; Data Pemesan &amp; Data Almarhum', false)
            ->assertDontSee('Langkah 4 &mdash; Konfirmasi', false);
    }

    public function test_screen_4_renders_the_confirmation_screen(): void
    {
        $this->atPayment()
            ->set('paymentReference', 'TRF-12345')
            ->call('saveStep3', BookingPaymentMethod::MANUAL)
            ->assertSet('currentStep', BookingWizardStep::CONFIRMATION)
            ->assertSee('Langkah 4 &mdash; Konfirmasi', false)
            ->assertDontSee('Langkah 3 &mdash; Pembayaran', false);
    }

    /**
     * Back-navigation from a later screen re-reveals the WHOLE of screen 1,
     * not just its first section: the reveal reads the restored selections,
     * which a resumed draft carries in full.
     */
    public function test_returning_to_screen_1_from_screen_2_reveals_every_discovery_section(): void
    {
        $this->atCustomerAndDeceasedData()
            ->call('goToStep', BookingWizardStep::DISCOVERY)
            ->assertSee('Pilih Lokasi')
            ->assertSee('Pilih TPU/TPS')
            ->assertSee('Pilih Jenis Layanan')
            ->assertSee('Pilih Layanan');
    }
}
