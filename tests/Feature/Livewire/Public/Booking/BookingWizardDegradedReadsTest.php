<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingPaymentMethod;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `BookingWizard::render()`'s reads, when the read itself fails.
 *
 * The wizard already had this discipline for the cemetery list
 * (`BookingWizardRouteTest::test_a_failed_cemetery_read_degrades_honestly_instead_of_500ing`)
 * and, since the screen consolidation widened when it runs, for the
 * services catalogue (`BookingWizardStepsFourAndFiveTest::
 * test_a_failed_services_catalog_read_degrades_honestly_instead_of_500ing`).
 * The two reads covered here — Screen 2's Ringkasan and Step 9's
 * confirmation — were left unguarded, and the consolidation made the first
 * of them meaningfully more exposed: its render condition moved from the
 * single exact step `SUMMARY` to `currentScreen() === 2`, which is three
 * steps, so it now runs on three times as many renders.
 *
 * Failure is injected by taking `service_definitions` away, which is what
 * both reads reach through (`BookingDraftQuery::summary()` resolves every
 * selected line by code). Only the three FOREIGN KEY CONSTRAINTS pointing
 * at it are dropped, not the dependent tables — the cheaper teardown
 * `BookingWizardStepsFourAndFiveTest` established for this exact table,
 * which has exactly three direct referencers.
 *
 * Both assertions are about a PUBLIC screen: a customer mid-booking must be
 * told plainly that something could not be loaded, and never handed a 500 —
 * least of all on Step 9, where the alternative to an honest message is a
 * page that appears to confirm an order it could not actually read.
 */
final class BookingWizardDegradedReadsTest extends TestCase
{
    use RefreshDatabase;

    private function makeServiceCatalogUnreadable(): void
    {
        Schema::table('service_package_items', function (Blueprint $table): void {
            $table->dropForeign(['service_definition_id']);
        });
        Schema::table('substitution_policies', function (Blueprint $table): void {
            $table->dropForeign(['substitute_service_definition_id']);
        });
        Schema::table('quote_lines', function (Blueprint $table): void {
            $table->dropForeign(['service_definition_id']);
        });
        Schema::dropIfExists('service_definitions');
    }

    private function componentAtStepThree(): Testable
    {
        $draft = (new StartBookingDraft)();
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-a');

        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, ['cemetery_id' => $cemetery->id], 'idem-b');
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICE_TYPE, ['service_type' => 'NEW_GRAVE'], 'idem-c');

        return Livewire::test(BookingWizard::class, ['draftId' => $draft->id]);
    }

    private function driveToSummary(Testable $component): Testable
    {
        return $component
            ->call('saveStep4', [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ])
            ->assertSet('currentStep', BookingWizardStep::SUMMARY);
    }

    private function driveToConfirmation(Testable $component): Testable
    {
        return $this->driveToSummary($component)
            ->call('goToStep', BookingWizardStep::CUSTOMER_DATA)
            ->set('customerFullName', 'Uji Coba')
            ->set('customerMobile', '081234567890')
            ->set('customerEmail', 'uji@example.com')
            ->set('customerAddress', 'Jl. Contoh No. 1')
            ->set('customerRelationship', 'PASANGAN')
            ->set('customerContactChannel', 'WHATSAPP')
            ->set('privacyNoticeAccepted', true)
            ->call('saveStep6')
            ->set('deceasedFullName', 'Almarhum Uji')
            ->set('deceasedDateOfBirth', '1980-05-10')
            ->set('deceasedDateOfDeath', '2026-08-01')
            ->set('deceasedRelationship', 'PASANGAN')
            ->set('deceasedGender', 'LAKI_LAKI')
            ->call('saveStep7')
            ->set('paymentReference', 'REF-001')
            ->call('saveStep8', BookingPaymentMethod::MANUAL)
            ->assertSet('currentStep', BookingWizardStep::CONFIRMATION);
    }

    /**
     * The customer's own choices are still safely on the draft — only the
     * priced presentation of them could not be built — so the alert says
     * exactly that, and Screen 2's forward path into Step 6 stays open.
     * Silently rendering nothing where the Ringkasan card belongs (the
     * behaviour before this guard, since `$summary` was simply left null)
     * would leave a customer looking at a heading with no table and no
     * explanation.
     */
    public function test_a_failed_summary_read_degrades_honestly_instead_of_500ing(): void
    {
        $component = $this->driveToSummary($this->componentAtStepThree());

        $this->makeServiceCatalogUnreadable();

        $component->call('goToStep', BookingWizardStep::SUMMARY)
            ->assertOk()
            ->assertSee('Ringkasan pesanan sedang tidak dapat dimuat')
            ->assertSee('Lanjut ke Data Pemesan');

        $this->assertSame(2, $component->instance()->currentScreen());
    }

    /**
     * Step 9's guard degrades to null `$confirmationData` when the read
     * fails, same as an unresolvable draft — but NOT to the same COPY. By
     * this point in the journey `saveStep8()` has already created a real
     * order (this test's own draft/order both exist on disk; only the
     * SUBSEQUENT read that renders them is what fails), so the view must
     * show the "try reloading, do not start over" state
     * (`$confirmationUnavailable`), never the "start a new booking" one —
     * see `test_a_failed_confirmation_read_after_a_real_order_exists_never_suggests_starting_over`
     * for the concrete duplicate-order risk this distinction exists to
     * prevent.
     */
    public function test_a_failed_confirmation_read_degrades_honestly_instead_of_500ing(): void
    {
        $component = $this->driveToConfirmation($this->componentAtStepThree());

        $this->makeServiceCatalogUnreadable();

        $component->call('goToStep', BookingWizardStep::CONFIRMATION)
            ->assertOk()
            ->assertSee('Konfirmasi pesanan sedang tidak dapat dimuat')
            ->assertSee('Muat Ulang')
            ->assertDontSee('Mulai Pemesanan Baru');

        $this->assertSame(4, $component->instance()->currentScreen());
    }

    /**
     * The concrete scenario the fix wave's final review flagged: by Step 9
     * an order usually already exists (`SubmitBookingDraft`'s idempotency is
     * keyed per DRAFT id, not per order), so if the confirmation READ then
     * fails, telling the customer to "start a new booking" would open a
     * genuinely new draft that idempotency cannot recognise as a duplicate
     * of the order that already exists — creating a real second order. This
     * test proves the real order exists on disk (not merely that the UI
     * looks right) BEFORE inducing the read failure, then asserts the safe
     * copy renders once it does.
     *
     * No post-failure `assertDatabaseHas()` here: `makeServiceCatalogUnreadable()`
     * poisons this test's own ambient RefreshDatabase transaction the same
     * way it poisons the request's (Postgres aborts every later statement
     * in that transaction, `SQLSTATE 25P02`, regardless of table) — the
     * exact mechanic this whole guard exists to degrade honestly from. The
     * pre-failure existence check above already proves the order is real;
     * re-querying it afterward in the same poisoned transaction is not
     * possible, matching the same limitation this codebase's other
     * poisoned-transaction tests already accept.
     */
    public function test_a_failed_confirmation_read_after_a_real_order_exists_never_suggests_starting_over(): void
    {
        $component = $this->driveToConfirmation($this->componentAtStepThree());

        $draftId = $component->get('draftId');
        $order = Order::query()
            ->where('booking_draft_id', $draftId)
            ->firstOrFail();
        $this->assertNotNull($order->reference);

        $this->makeServiceCatalogUnreadable();

        $component->call('goToStep', BookingWizardStep::CONFIRMATION)
            ->assertOk()
            ->assertSee('Jangan memesan ulang')
            ->assertSee('Muat Ulang')
            ->assertDontSee('Mulai Pemesanan Baru');
    }
}
