<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\PreNeed;

use App\Domain\AgreementCertificate\Actions\IssueCertificate;
use App\Domain\AgreementCertificate\CertificateType;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PreNeed\Models\PreNeedConsultationRequest;
use App\Domain\PreNeed\Models\PreNeedInterest;
use App\Domain\PreNeed\PreNeedAuditActions;
use App\Domain\PreNeed\PreNeedInterestStatus;
use App\Livewire\Public\PreNeed\PreNeedInterestPage;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\Modes\PreNeedMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Task 5 (`docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md`)
 * — `App\Livewire\Public\PreNeed\PreNeedInterestPage` (`/preneed`): the
 * non-dismissible InterestOnly banner, the interest form (registers with
 * `G-LEGAL-01` closed), the consultation request form (persists + audits),
 * and the state-only certificate status section.
 */
final class PreNeedInterestPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The §6.9 non-dismissible InterestOnly banner copy — brief's exact
     * text, asserted so a wording drift fails the suite rather than
     * silently changing the honest gate-closed promise.
     */
    private const string INTEREST_ONLY_COPY = 'layanan pra-pesan belum dapat diaktifkan; daftarkan minat atau minta konsultasi';

    /**
     * The interest form registers with the gate CLOSED (the whole point of
     * the §6.9 InterestOnly mode) and confirms honestly.
     */
    public function test_interest_registers_and_confirms_with_the_gate_closed(): void
    {
        $component = Livewire::test(PreNeedInterestPage::class)
            ->set('interestCity', LaunchCityCode::JAKARTA)
            ->set('interestName', 'Ibu Siti')
            ->set('interestContact', '0812-3456-7890')
            ->call('registerInterest');

        $component->assertSee('Minat Anda terdaftar. Tim kami akan menghubungi Anda.');

        // The page's draft-start seam created the MINIMAL PRE_NEED draft the
        // plan pins (service_type + city_code only, both saving-hook-valid).
        $draft = BookingDraft::query()->sole();
        self::assertSame(LaunchCityCode::JAKARTA, $draft->city_code);
        self::assertSame(BookingServiceType::PRE_NEED, $draft->service_type);

        $interest = PreNeedInterest::query()->sole();
        self::assertSame(PreNeedInterestStatus::INTEREST_REGISTERED->value, $interest->status);
        self::assertSame(PreNeedMode::InterestOnly->value, $interest->gate_mode);
        self::assertSame(LaunchCityCode::JAKARTA, $interest->service_area);
        self::assertSame($draft->getKey(), $interest->booking_draft_id);
    }

    /**
     * G-LEGAL-01 closed ⇒ the non-dismissible info banner (design-system.md
     * §6.9, PreNeedMode::InterestOnly) — resolved server-side, never
     * client-supplied.
     */
    public function test_the_interest_only_info_banner_shows_while_the_gate_is_closed(): void
    {
        Livewire::test(PreNeedInterestPage::class)->assertSee(self::INTEREST_ONLY_COPY);
    }

    /**
     * The banner disappears when the gate opens, but the interest flow is
     * itself NEVER gated (design-system.md §6.9 Negative criteria:
     * "the interest-registration step is never removed") — it still
     * registers, and the opened mode is recorded on the row.
     */
    public function test_the_banner_is_absent_and_interest_still_registers_when_the_gate_is_open(): void
    {
        $this->bindGateRegistryWith('G-LEGAL-01', open: true);

        $component = Livewire::test(PreNeedInterestPage::class);
        $component->assertDontSee(self::INTEREST_ONLY_COPY);

        $component->set('interestCity', LaunchCityCode::JAKARTA)
            ->set('interestName', 'Ibu Siti')
            ->set('interestContact', '0812-3456-7890')
            ->call('registerInterest');

        $interest = PreNeedInterest::query()->sole();
        self::assertSame(PreNeedMode::PaymentEnabled->value, $interest->gate_mode);
    }

    /**
     * The consultation form persists a `pre_need_consultation_requests` row
     * and its audit pair — gate-independent (the domain test proves it).
     */
    public function test_a_consultation_request_persists_from_the_form(): void
    {
        $component = Livewire::test(PreNeedInterestPage::class)
            ->set('consultName', 'Bapak Udin')
            ->set('consultContact', '0821-1111-2222')
            ->set('consultMessage', 'Saya ingin berkonsultasi lebih lanjut.')
            ->call('requestConsultation');

        $component->assertSee('Permintaan konsultasi diterima. Tim kami akan menghubungi Anda.');

        $request = PreNeedConsultationRequest::query()->sole();
        self::assertSame('Bapak Udin', $request->name);
        self::assertSame('0821-1111-2222', $request->contact);
        self::assertSame('Saya ingin berkonsultasi lebih lanjut.', $request->message);
        self::assertNull($request->pre_need_interest_id);

        self::assertDatabaseHas('audit_events', [
            'action' => PreNeedAuditActions::PRENEED_CONSULTATION_REQUESTED,
            'subject_type' => 'pre_need_consultation_request',
            'subject_id' => $request->getKey(),
            'actor_role' => 'guest',
        ]);
    }

    /**
     * A consultation filed on the same page visit as an interest
     * registration is linked to it — the page remembers the interest it
     * just registered.
     */
    public function test_a_consultation_filed_after_interest_registration_is_linked_to_it(): void
    {
        $component = Livewire::test(PreNeedInterestPage::class)
            ->set('interestCity', LaunchCityCode::JAKARTA)
            ->set('interestName', 'Ibu Siti')
            ->set('interestContact', '0812-3456-7890')
            ->call('registerInterest');

        $component->set('consultName', 'Ibu Siti')
            ->set('consultContact', '0812-3456-7890')
            ->set('consultMessage', 'Tindak lanjut minat saya.')
            ->call('requestConsultation');

        $interest = PreNeedInterest::query()->sole();
        $request = PreNeedConsultationRequest::query()->sole();

        self::assertSame($interest->getKey(), $request->pre_need_interest_id);
    }

    /**
     * AC6/`CertificateStatusView`: the section shows the state-only key set
     * (type/status/version/effective_at/issued_by_role) and NEVER the
     * restricted references — the certificate reference, the vault
     * `document_id`, or any external reference.
     */
    public function test_the_certificate_status_section_renders_state_only(): void
    {
        $order = $this->makePaidOrder();
        $document = $this->makeAcceptedDocument();

        $certificate = app(IssueCertificate::class)(
            CertificateType::OrderSettlement,
            $order,
            'user:1',
            'admin',
            $document->getKey(),
        );

        $html = Livewire::test(PreNeedInterestPage::class)
            ->set('certSubjectType', 'order')
            ->set('certSubjectId', $order->getKey())
            ->call('checkCertificateStatus')
            ->html();

        // The pinned projection key set IS rendered...
        $this->assertStringContainsString('ORDER_SETTLEMENT', $html);
        $this->assertStringContainsString('issued', $html);
        $this->assertStringContainsString('admin', $html);

        // ...and every restricted reference is NOT (tests/Feature/Domain/
        // AgreementCertificate/CertificateTest.php pins the same set).
        $this->assertStringNotContainsString($certificate->reference, $html);
        $this->assertStringNotContainsString((string) $document->getKey(), $html);
        $this->assertStringNotContainsString((string) $order->getKey(), $html);
    }

    private function makePaidOrder(): Order
    {
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MENUNGGU_PEMBAYARAN->value,
        ]);

        app(RecordOrderStatusChange::class)($order, OrderStatus::DIBAYAR, 'actor:system', 'system');

        return $order;
    }

    private function makeAcceptedDocument(): object
    {
        $documentId = (string) Str::uuid();

        DB::table('documents')->insert([
            'id' => $documentId,
            'document_kind' => DocumentKind::Certificate->value,
            'state' => DocumentState::Accepted->value,
            'owner_type' => 'order',
            'owner_id' => (string) Str::uuid(),
            'original_filename' => 'sertifikat.pdf',
            'storage_prefix' => 'accepted',
            'storage_key' => 'opaque-key-'.Str::random(8),
            'size_bytes' => 1024,
            'mime_declared' => 'application/pdf',
            'scanner_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return app(Document::class)->newQuery()->findOrFail($documentId);
    }

    /**
     * Replaces the database-backed gate registry with an in-memory source,
     * the same stub shape the domain tests use.
     */
    private function bindGateRegistryWith(string $gateId, bool $open): void
    {
        $states = [$gateId => GateState::fromRecord($gateId, open: $open)];

        $this->app->instance(GateRegistrySource::class, new class($states) implements GateRegistrySource
        {
            /**
             * @param  array<string, GateState>  $states
             */
            public function __construct(private readonly array $states) {}

            public function load(): GateRegistrySnapshot
            {
                return new GateRegistrySnapshot($this->states);
            }
        });
    }
}
