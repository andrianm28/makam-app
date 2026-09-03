<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Livewire\Public\Booking\BookingWizard;
use App\Platform\SiteSettings\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The manual bank-transfer destination on Step 8's "Pembayaran Manual" card
 * (`App\Support\BankTransferInfo`, `App\Filament\Admin\Resources\
 * SiteSettings\SiteSettingsResource`). Real UAT gap (25 Aug 2026): the card
 * used to show instructional copy with no actual account to transfer to.
 * `G-PAY-01` is closed by default in tests (no fixture override needed here)
 * so Step 8 renders the manual card unconditionally — the same default
 * `BookingWizardStepsSixToNineEndToEndTest` relies on.
 */
final class BookingWizardManualPaymentBankDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function componentAtPayment(): Testable
    {
        $draft = (new StartBookingDraft)();

        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, [
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $cemetery->id,
            'cemetery_package_id' => null,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [
                ['code' => ServiceCode::DOCUMENT_PROCESSING, 'quantity' => 1],
                ['code' => ServiceCode::GRAVE_DIGGING, 'quantity' => 1],
            ],
        ], 'idem-discovery-'.$draft->id);

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draft->id])
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
            ->call('saveStep2');

        $component->assertSet('currentStep', BookingWizardStep::PAYMENT);

        return $component;
    }

    public function test_it_shows_an_honest_not_configured_state_when_no_bank_details_are_set(): void
    {
        $this->componentAtPayment()
            ->assertSee('Pembayaran Manual')
            ->assertSee('Rekening tujuan belum dikonfigurasi')
            ->assertDontSee('Nomor Rekening');
    }

    public function test_it_renders_the_real_configured_bank_details(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_BANK_TRANSFER_BANK_NAME, 'value' => 'Bank Makam Sejahtera']);
        SiteSetting::query()->create(['key' => SiteSetting::KEY_BANK_TRANSFER_ACCOUNT_NUMBER, 'value' => '1234567890']);
        SiteSetting::query()->create(['key' => SiteSetting::KEY_BANK_TRANSFER_ACCOUNT_HOLDER, 'value' => 'PT Makam Digital Nusantara']);

        $this->componentAtPayment()
            ->assertSee('Pembayaran Manual')
            ->assertSee('Bank Makam Sejahtera')
            ->assertSee('1234567890')
            ->assertSee('PT Makam Digital Nusantara')
            ->assertDontSee('Rekening tujuan belum dikonfigurasi');
    }

    /**
     * A partial configuration (`BankTransferInfo::isConfigured()`'s own
     * load-bearing case) must still fall into the honest empty state, not
     * show an incomplete destination.
     */
    public function test_a_partial_configuration_still_shows_the_honest_not_configured_state(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_BANK_TRANSFER_BANK_NAME, 'value' => 'Bank Makam Sejahtera']);

        $this->componentAtPayment()
            ->assertSee('Rekening tujuan belum dikonfigurasi')
            ->assertDontSee('Nomor Rekening');
    }
}
