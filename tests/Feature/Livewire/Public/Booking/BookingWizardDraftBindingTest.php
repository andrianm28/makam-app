<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\BookingContactChannel;
use App\Domain\Booking\BookingGender;
use App\Domain\Booking\BookingPaymentMethod;
use App\Domain\Booking\BookingRelationshipCode;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Livewire\Public\Booking\BookingWizard;
use App\Models\User;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PaymentMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The booking wizard's ACCESS contract, as seen from the UI.
 *
 * From Step 6 a `booking_drafts` row carries customer and deceased PII —
 * name, mobile, email, home address, dates of birth and death. Until
 * `App\Domain\Booking\BookingDraftBinding` existed, the draft's UUID was the
 * only thing protecting all of it, and that UUID is not a secret: it travels
 * in `/pemesanan-makam/draft/{draftId}`, so it reaches browser history,
 * `Referer` headers and web-server access logs. Anyone who obtained one had
 * full READ and WRITE on a stranger's draft.
 *
 * So these tests are written from the attacker's side of that hole. Each one
 * puts a visitor in possession of a draft id they did not create and asserts
 * they get nothing: not the PII on the screen, not a write onto the row, and
 * not a distinguishable "this id exists" signal. The positive cases are here
 * too — the owning session resuming normally — because a guard that denies
 * everything is not a fix, it is an outage.
 *
 * Also pins the two other Step 6-8 corrections this component carries:
 *   - C2, privacy consent: `privacyNoticeAccepted` is a real, initially
 *     unticked checkbox whose value gates the save. The component previously
 *     stamped `privacy_notice_accepted_at` unconditionally, recording consent
 *     for users who had consented to nothing.
 *   - C3, payment reference: the MANUAL path hardcoded a `null` reference, so
 *     it could never validate and the closed-gate fallback was unreachable.
 *     `saveStep8('ONLINE')` is a plain client-callable Livewire method, so the
 *     closed-gate rejection is asserted against that direct call, not against
 *     which button the Blade view happened to render.
 *
 * Companion to `BookingWizardSaveIntegrityTest` (idempotency, versioning,
 * unskippable steps) — this file is the access half of the same contract.
 */
final class BookingWizardDraftBindingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The session key `BookingDraftBinding` issues secrets under. Restated
     * here on purpose: the constant is private, and a test that reached into
     * it by reflection would follow a rename silently. Only ONE test needs it
     * (the wrong-secret case), and if the key ever changes, that test failing
     * loudly is the correct outcome.
     */
    private const SESSION_SECRETS_KEY = 'booking_draft_secrets';

    private const VICTIM_NAME = 'Siti Rahmawati Kusumaningrum';

    private const VICTIM_MOBILE = '081298765432';

    private const VICTIM_EMAIL = 'siti.rahmawati@contoh-korban.test';

    private const VICTIM_ADDRESS = 'Jalan Melati Nomor 12, Kebayoran Baru, Jakarta Selatan';

    private const VICTIM_DECEASED_NAME = 'Bapak Hartono Kusumaningrum';

    private const VICTIM_PAYMENT_REFERENCE = 'TRF-KORBAN-99871234';

    // =====================================================================
    // Binding — the core guarantees
    // =====================================================================

    public function test_starting_a_booking_binds_the_new_draft_to_the_session_that_created_it(): void
    {
        $cemetery = $this->jakartaCemeteryWithoutPackages();

        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA, $cemetery->id, null, BookingServiceType::NEW_GRAVE, $this->basicServicesPayload())
            ->assertHasNoErrors()
            ->get('draftId');

        $this->assertIsString($draftId);

        // The row carries a hash, never the secret itself — a database read
        // alone must not be enough to reconstruct a resume token.
        $storedHash = DB::table('booking_drafts')->where('id', $draftId)->value('resume_secret_hash');

        $this->assertIsString($storedHash, 'A newly started draft must be bound at creation, not left null.');
        $this->assertSame(64, strlen($storedHash), 'Expected a SHA-256 hex digest.');

        // And the binding is usable: the session that created it reads it back.
        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('draftId', $draftId)
            ->assertSet('city', LaunchCityCode::JAKARTA);
    }

    /**
     * `currentOrNewDraft()` ends with `return (new StartBookingDraft)
     * (auth()->id());` — attributing a newly-started draft to the current
     * actor so it later appears on `/akun/draft`. No other test in this file
     * exercises that attribution for an AUTHENTICATED user through the
     * wizard's own creation path: `victimDraftWithCustomerData()` and its
     * siblings all start from a guest session, and the ownership-rescue
     * tests below create their fixture rows directly via `BookingDraft::
     * create()`, bypassing `saveStep1()`/`StartBookingDraft` entirely.
     */
    public function test_an_authenticated_user_starting_a_new_booking_gets_an_attributed_draft(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $cemetery = $this->jakartaCemeteryWithoutPackages();

        Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA, $cemetery->id, null, BookingServiceType::NEW_GRAVE, $this->basicServicesPayload())
            ->assertHasNoErrors();

        $draft = BookingDraft::query()->sole();

        $this->assertSame($user->id, $draft->user_id);
    }

    public function test_a_stranger_holding_the_draft_id_cannot_read_the_pii_on_it(): void
    {
        $draftId = $this->victimDraftWithCustomerAndDeceasedData();

        $this->becomeADifferentVisitor();

        $stranger = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);

        // A draft that exists but is not ours is reported exactly like one
        // that does not exist: a blank wizard, no id, no data.
        $stranger->assertSet('draftId', null)
            ->assertSet('customerFullName', '')
            ->assertSet('customerEmail', '')
            ->assertSet('customerMobile', '')
            ->assertSet('customerAddress', '')
            ->assertSet('deceasedFullName', '')
            ->assertSet('currentStep', BookingWizardStep::DISCOVERY);

        // Not merely absent from the bound properties — absent from the page.
        $stranger->assertDontSee(self::VICTIM_NAME)
            ->assertDontSee(self::VICTIM_MOBILE)
            ->assertDontSee(self::VICTIM_EMAIL)
            ->assertDontSee(self::VICTIM_ADDRESS)
            ->assertDontSee(self::VICTIM_DECEASED_NAME);
    }

    public function test_a_stranger_holding_the_draft_id_cannot_write_to_it(): void
    {
        $draftId = $this->victimDraftWithCustomerAndDeceasedData();
        $before = $this->rowSnapshotOf($draftId);

        $this->becomeADifferentVisitor();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('customerFullName', 'Penyerang Anonim')
            ->set('customerMobile', '081100000000')
            ->set('customerEmail', 'penyerang@contoh-penyerang.test')
            ->set('customerAddress', 'Alamat penyerang yang cukup panjang untuk lolos validasi')
            ->set('customerRelationship', BookingRelationshipCode::LAINNYA)
            ->set('customerContactChannel', BookingContactChannel::EMAIL)
            ->set('privacyNoticeAccepted', true)
            ->set('deceasedFullName', 'Nama Almarhum Palsu')
            ->set('deceasedDateOfBirth', '1950-01-01')
            ->set('deceasedDateOfDeath', '2026-01-01')
            ->set('deceasedRelationship', BookingRelationshipCode::LAINNYA)
            ->call('saveStep2')
            ->set('paymentReference', 'TRF-PENYERANG-0001')
            ->call('saveStep3', BookingPaymentMethod::MANUAL)
            ->assertHasErrors(['draft'])
            ->assertSet('autosaveState', 'failed');

        $this->assertSame(
            $before,
            $this->rowSnapshotOf($draftId),
            'A visitor who merely holds the draft id must not be able to change one byte of it.',
        );
    }

    /**
     * The write path's OWN guard, separate from `mount()`'s. Above, the
     * stranger never gets a `$draftId` at all, so a regression confined to
     * `saveStepOrShowErrors()` would slip past that test. Here the component
     * legitimately holds the id — it hydrated while bound — and only then
     * loses the session secret, which is what an expired or replaced session
     * looks like from the server's side.
     */
    public function test_a_component_that_loses_its_session_secret_cannot_write_to_the_draft_it_holds(): void
    {
        $draftId = $this->victimDraftWithCustomerAndDeceasedData();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('draftId', $draftId);

        $before = $this->rowSnapshotOf($draftId);

        $this->becomeADifferentVisitor();

        $component->set('customerFullName', 'Penyerang Anonim')
            ->set('customerMobile', '081100000000')
            ->set('customerEmail', 'penyerang@contoh-penyerang.test')
            ->set('customerAddress', 'Alamat penyerang yang cukup panjang untuk lolos validasi')
            ->set('customerRelationship', BookingRelationshipCode::LAINNYA)
            ->set('customerContactChannel', BookingContactChannel::EMAIL)
            ->set('privacyNoticeAccepted', true)
            ->set('deceasedFullName', 'Nama Almarhum Palsu')
            ->set('deceasedDateOfBirth', '1950-01-01')
            ->set('deceasedDateOfDeath', '2026-01-01')
            ->set('deceasedRelationship', BookingRelationshipCode::LAINNYA)
            ->call('saveStep2')
            ->assertHasErrors(['draft'])
            ->assertSet('autosaveState', 'failed');

        $this->assertSame(
            $before,
            $this->rowSnapshotOf($draftId),
            'A save must resolve the draft through the binding, not through the id it is already holding.',
        );
    }

    /**
     * Fails CLOSED, not open. A row with no `resume_secret_hash` — one created
     * before the binding existed, or one tampered to null — is unreadable even
     * by the session that started it. Grandfathering those rows would leave
     * exactly the population the binding was introduced to protect unprotected.
     */
    public function test_a_draft_with_no_resume_secret_hash_is_unreadable_even_by_its_own_session(): void
    {
        $draftId = $this->victimDraftWithCustomerAndDeceasedData();

        // Same session throughout — the ONLY thing that changes is the hash.
        DB::table('booking_drafts')->where('id', $draftId)->update(['resume_secret_hash' => null]);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('draftId', null)
            ->assertSet('customerFullName', '')
            ->assertSet('deceasedFullName', '')
            ->assertDontSee(self::VICTIM_NAME)
            ->assertDontSee(self::VICTIM_DECEASED_NAME);
    }

    public function test_a_session_holding_the_wrong_secret_for_a_real_draft_is_rejected(): void
    {
        $draftId = $this->victimDraftWithCustomerAndDeceasedData();

        $this->becomeADifferentVisitor();
        Session::put(self::SESSION_SECRETS_KEY.'.'.$draftId, 'bukan-rahasia-yang-diterbitkan');

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('draftId', null)
            ->assertSet('customerFullName', '')
            ->assertSet('deceasedFullName', '')
            ->assertDontSee(self::VICTIM_NAME)
            ->assertDontSee(self::VICTIM_EMAIL);
    }

    /**
     * The positive case. Everything above is a denial, and a guard that only
     * ever denies has not fixed the feature, it has removed it.
     */
    public function test_the_owning_session_can_still_resume_its_own_draft(): void
    {
        $draftId = $this->victimDraftWithCustomerAndDeceasedData();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('draftId', $draftId)
            ->assertSet('customerFullName', self::VICTIM_NAME)
            ->assertSet('customerMobile', self::VICTIM_MOBILE)
            ->assertSet('customerEmail', self::VICTIM_EMAIL)
            ->assertSet('customerAddress', self::VICTIM_ADDRESS)
            ->assertSet('customerRelationship', BookingRelationshipCode::ANAK)
            ->assertSet('customerContactChannel', BookingContactChannel::WHATSAPP)
            ->assertSet('deceasedFullName', self::VICTIM_DECEASED_NAME)
            ->assertSet('currentStep', BookingWizardStep::PAYMENT);
    }

    public function test_a_stranger_does_not_reach_the_confirmation_screen_of_someone_elses_draft(): void
    {
        $draftId = $this->victimDraftThroughPayment();

        $this->assertSame(
            BookingWizardStep::CONFIRMATION,
            BookingDraft::query()->findOrFail($draftId)->current_step,
            'This test is about the Step 9 branch; it proves nothing if the draft never got there.',
        );

        $this->becomeADifferentVisitor();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('draftId', null)
            ->assertSet('currentStep', BookingWizardStep::DISCOVERY)
            ->assertDontSee(self::VICTIM_NAME)
            ->assertDontSee(self::VICTIM_DECEASED_NAME)
            ->assertDontSee(self::VICTIM_PAYMENT_REFERENCE);
    }

    /**
     * `render()`'s own guard for the Step 9 branch, the counterpart of the
     * write-path test above: the component is already SITTING on the
     * confirmation screen when the binding goes away, so the id in hand is
     * not enough to keep re-reading the row.
     *
     * Asserted against `render()`'s `confirmationData` payload rather than
     * against the confirmation markup, for two reasons. The screen's copy and
     * structure are being reworked in parallel, and an assertion on either
     * would be testing the Blade file rather than the guard. And the
     * component's own hydrated properties legitimately still hold this
     * visitor's data — what is on trial is only what `render()` reads back out
     * of the database.
     */
    public function test_the_confirmation_branch_stops_reading_the_draft_once_the_binding_is_gone(): void
    {
        $draftId = $this->victimDraftThroughPayment();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('currentStep', BookingWizardStep::CONFIRMATION);

        // Positive control: the branch really is reading the draft back, so
        // the assertion below is about the guard and not about a screen that
        // never had the data in the first place.
        $confirmation = $component->viewData('confirmationData');

        $this->assertIsArray($confirmation);
        $this->assertSame(self::VICTIM_NAME, $confirmation['customer_name']);
        $this->assertSame(self::VICTIM_DECEASED_NAME, $confirmation['deceased_name']);
        $this->assertSame(self::VICTIM_PAYMENT_REFERENCE, $confirmation['payment_reference']);

        $this->becomeADifferentVisitor();

        $component->call('$refresh');

        $this->assertNull(
            $component->viewData('confirmationData'),
            'Holding the draft id is not enough to keep re-reading the row once the binding is gone.',
        );
    }

    // =====================================================================
    // Ownership rescue — the deliberate reversal of the session-only ruling
    //
    // `BookingDraftBinding`'s doc block used to rule out cross-device/
    // cross-session resume entirely. That ruling predates customer accounts.
    // `BookingWizard::resolveDraftById()` now additionally accepts a session
    // secret miss when the current authenticated user IS the draft's owner
    // (`$candidate->user_id === auth()->id()`) — a strictly stronger proof
    // than the session secret it supplements, since it cannot be
    // reconstructed from a shared URL. A guest, or an authenticated user who
    // is NOT the owner, must still be denied exactly as before.
    // =====================================================================

    public function test_an_authenticated_owner_resumes_their_own_draft_via_mount_after_losing_the_session_secret(): void
    {
        $owner = User::factory()->create();
        $draftId = $this->victimDraftWithCustomerAndDeceasedData();

        BookingDraft::query()->where('id', $draftId)->update(['user_id' => $owner->id]);

        $oldHash = DB::table('booking_drafts')->where('id', $draftId)->value('resume_secret_hash');

        $this->becomeADifferentAuthenticatedVisitor($owner);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('draftId', $draftId)
            ->assertSet('customerFullName', self::VICTIM_NAME)
            ->assertSet('customerMobile', self::VICTIM_MOBILE)
            ->assertSet('customerEmail', self::VICTIM_EMAIL)
            ->assertSet('customerAddress', self::VICTIM_ADDRESS)
            ->assertSet('currentStep', BookingWizardStep::PAYMENT);

        $newHash = DB::table('booking_drafts')->where('id', $draftId)->value('resume_secret_hash');

        $this->assertIsString($newHash);
        $this->assertNotSame(
            $oldHash,
            $newHash,
            'A rescued resume must mint a fresh secret via BookingDraftBinding::issue(), proving the rescue path actually ran.',
        );
    }

    public function test_an_authenticated_owner_resumes_their_own_draft_via_save_step_one_after_losing_the_session_secret(): void
    {
        $owner = User::factory()->create();
        $draftId = $this->draftAtDiscoveryComplete();

        BookingDraft::query()->where('id', $draftId)->update(['user_id' => $owner->id]);

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('draftId', $draftId);

        $oldHash = DB::table('booking_drafts')->where('id', $draftId)->value('resume_secret_hash');

        $this->becomeADifferentAuthenticatedVisitor($owner);

        $bogorCemetery = $this->cemeteryWithoutPackages(LaunchCityCode::BOGOR);

        $component->call('saveStep1', LaunchCityCode::BOGOR, $bogorCemetery->id, null, BookingServiceType::NEW_GRAVE, $this->basicServicesPayload())
            ->assertHasNoErrors()
            ->assertSet('draftId', $draftId)
            ->assertSet('city', LaunchCityCode::BOGOR);

        $this->assertSame(
            1,
            BookingDraft::query()->count(),
            'The ownership rescue must resume the SAME draft, not silently start a second one.',
        );

        $newHash = DB::table('booking_drafts')->where('id', $draftId)->value('resume_secret_hash');

        $this->assertIsString($newHash);
        $this->assertNotSame($oldHash, $newHash);
    }

    public function test_a_guest_who_loses_the_session_secret_still_gets_a_brand_new_draft_from_save_step_one(): void
    {
        $draftId = $this->draftAtDiscoveryComplete();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('draftId', $draftId);

        $this->becomeADifferentVisitor();

        $bogorCemetery = $this->cemeteryWithoutPackages(LaunchCityCode::BOGOR);

        $newDraftId = $component->call('saveStep1', LaunchCityCode::BOGOR, $bogorCemetery->id, null, BookingServiceType::NEW_GRAVE, $this->basicServicesPayload())
            ->assertHasNoErrors()
            ->get('draftId');

        $this->assertIsString($newDraftId);
        $this->assertNotSame(
            $draftId,
            $newDraftId,
            'currentOrNewDraft() must not resume a draft the current guest session cannot prove ownership of.',
        );
        $this->assertSame(
            2,
            BookingDraft::query()->count(),
            'A rescue miss must fall back to a genuinely new draft, not reuse the old one.',
        );
    }

    public function test_an_authenticated_non_owner_cannot_resume_someone_elses_draft_via_mount(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $draftId = $this->victimDraftWithCustomerAndDeceasedData();
        BookingDraft::query()->where('id', $draftId)->update(['user_id' => $owner->id]);

        $this->becomeADifferentAuthenticatedVisitor($attacker);

        $stranger = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);

        $stranger->assertSet('draftId', null)
            ->assertSet('customerFullName', '')
            ->assertSet('customerEmail', '')
            ->assertSet('customerMobile', '')
            ->assertSet('customerAddress', '')
            ->assertSet('currentStep', BookingWizardStep::DISCOVERY);

        $stranger->assertDontSee(self::VICTIM_NAME)
            ->assertDontSee(self::VICTIM_MOBILE)
            ->assertDontSee(self::VICTIM_EMAIL)
            ->assertDontSee(self::VICTIM_ADDRESS);
    }

    public function test_an_authenticated_non_owner_cannot_resume_someone_elses_draft_via_save_step_one(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $draftId = $this->draftAtDiscoveryComplete();
        BookingDraft::query()->where('id', $draftId)->update(['user_id' => $owner->id]);

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('draftId', $draftId);

        $this->becomeADifferentAuthenticatedVisitor($attacker);

        $bogorCemetery = $this->cemeteryWithoutPackages(LaunchCityCode::BOGOR);

        $newDraftId = $component->call('saveStep1', LaunchCityCode::BOGOR, $bogorCemetery->id, null, BookingServiceType::NEW_GRAVE, $this->basicServicesPayload())
            ->assertHasNoErrors()
            ->get('draftId');

        $this->assertIsString($newDraftId);
        $this->assertNotSame(
            $draftId,
            $newDraftId,
            'An authenticated user must never be able to resume a draft owned by someone else.',
        );
        $this->assertSame(2, BookingDraft::query()->count());
    }

    // =====================================================================
    // Old step numbering — an in-flight draft under the pre-reduction
    // numbering is unresumable, never silently mapped forward
    //
    // A real `booking_drafts` row created before this branch's changes
    // landed can carry a `current_step` value from the old 1-9 vocabulary
    // (`BookingWizardStep` used to have nine constants; it now has four,
    // `DISCOVERY=1..CONFIRMATION=4`). Spec Decision 6: no data migration, no
    // dual-numbering compatibility layer — such a draft is treated exactly
    // like an unknown/purged one, not silently resumed at some arbitrary new
    // step it was never saved under.
    // =====================================================================

    public function test_a_draft_at_an_old_out_of_range_current_step_is_treated_as_unresumable(): void
    {
        // A draft genuinely bound to THIS session (a real resumable draft in
        // every other respect), but whose `current_step` was written under
        // the old 9-step numbering — 8 was PAYMENT there, which is out of
        // range for the new `BookingWizardStep::isKnown()` (1-4).
        $draftId = $this->draftAtDiscoveryComplete();

        BookingDraft::query()->where('id', $draftId)->update(['current_step' => 8]);

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);

        $component->assertSee('Sesi pemesanan Anda telah berakhir')
            ->assertSet('draftId', null)
            ->assertSet('currentStep', BookingWizardStep::DISCOVERY)
            ->assertSet('stagedServiceCodes', ServiceCode::BASIC_CODES);

        // The same cleanup the adjacent "unknown draft" branch performs: the
        // stale binding is forgotten from the session, mirroring
        // `BookingDraftBinding::forget()`'s own effect.
        $this->assertFalse(
            Session::has(self::SESSION_SECRETS_KEY.'.'.$draftId),
            'An old-numbering draft must be forgotten exactly like an unknown one.',
        );
    }

    /**
     * The out-of-range case above is the EASY half. Old steps 1 (LOCATION),
     * 2 (CEMETERY) and 3 (SERVICE_TYPE) leave `current_step` at 2, 3 and 4 —
     * all IN range for the new vocabulary, so `isKnown()` waves them through.
     * Resuming one walks the customer to CONFIRMATION with `cemetery_id` /
     * `service_type` / `selected_services` never written, records a real
     * payment reference, and then `SubmitBookingDraft` throws on the null
     * `service_type` — swallowed by `saveStep3()`, so no order is created and
     * staff never see the booking. The guard therefore checks the DATA, not
     * just the number.
     *
     * @return iterable<string, array{int, list<int>, array<string, mixed>}>
     */
    public static function oldInRangeNumberingProvider(): iterable
    {
        // `current_step`, `completed_steps`, and the DISCOVERY columns the
        // old numbering had NOT reached yet at that point.
        yield 'old step 1 (LOCATION) complete' => [
            2, [1], ['cemetery_id' => null, 'service_type' => null, 'selected_services' => '[]'],
        ];

        yield 'old step 2 (CEMETERY) complete' => [
            3, [1, 2], ['service_type' => null, 'selected_services' => '[]'],
        ];

        yield 'old step 3 (SERVICE_TYPE) complete' => [
            4, [1, 2, 3], ['selected_services' => '[]'],
        ];
    }

    /**
     * @param  list<int>  $completedSteps
     * @param  array<string, mixed>  $clearedColumns
     */
    #[DataProvider('oldInRangeNumberingProvider')]
    public function test_a_draft_at_an_old_in_range_current_step_is_treated_as_unresumable(
        int $oldCurrentStep,
        array $completedSteps,
        array $clearedColumns,
    ): void {
        // Genuinely session-bound in every other respect — same construction
        // as the out-of-range test above — then rewritten in the DB to the
        // state the OLD numbering would really have left it in.
        $draftId = $this->draftAtDiscoveryComplete();

        BookingDraft::query()->where('id', $draftId)->update([
            'current_step' => $oldCurrentStep,
            'completed_steps' => json_encode($completedSteps),
            ...$clearedColumns,
        ]);

        $this->assertTrue(
            BookingWizardStep::isKnown($oldCurrentStep),
            'This case only matters BECAUSE isKnown() accepts it — if that stops being true the test is proving nothing.',
        );

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);

        $component->assertSee('Sesi pemesanan Anda telah berakhir')
            ->assertSet('draftId', null)
            ->assertSet('currentStep', BookingWizardStep::DISCOVERY)
            ->assertSet('cemeteryId', null)
            ->assertSet('serviceType', null)
            ->assertSet('stagedServiceCodes', ServiceCode::BASIC_CODES);

        $this->assertFalse(
            Session::has(self::SESSION_SECRETS_KEY.'.'.$draftId),
            'An old-numbering draft must be forgotten exactly like an unknown one.',
        );
    }

    public function test_a_genuinely_complete_discovery_draft_still_resumes(): void
    {
        // The guard above must not be over-broad: a draft that really did
        // complete the MERGED DISCOVERY step has all three columns written,
        // and resuming it is the whole point of draft persistence.
        $draftId = $this->draftAtDiscoveryComplete();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertHasNoErrors()
            ->assertSet('draftId', $draftId)
            ->assertSet('currentStep', BookingWizardStep::CUSTOMER_AND_DECEASED_DATA);
    }

    // =====================================================================
    // Reaching (and re-reaching) the read-only CONFIRMATION step
    //
    // Under the nine-step model this section also covered the SUMMARY step
    // (read-only, never recorded in `completed_steps`, so navigating away
    // and back could strand a user). SUMMARY was CUT — not merged — by the
    // step reduction (`BookingWizardStep`'s own doc block), so that whole
    // bridge-step dead-end class of bug can no longer occur (same ruling
    // `BookingWizardStepFiveToSixHandoffTest` already acted on: its own
    // three SUMMARY dead-end regression tests were removed, not migrated).
    // DISCOVERY and CUSTOMER_AND_DECEASED_DATA are both ordinary writable
    // steps now, reachable only once genuinely completed or current — same
    // as every other writable step — so there is no equivalent "reachable
    // again after leaving" guarantee to pin for them.
    //
    // CONFIRMATION is read-only and therefore never recorded in
    // `completed_steps`, so a naive "have you completed it?" reachability
    // test locks the user out the moment they navigate away. That matters
    // here specifically because CONFIRMATION is the screen the tests above
    // use to prove the confirmation branch does not leak PII: if it became
    // unreachable, those tests would be guarding a screen nobody can open.
    // =====================================================================

    public function test_leaving_the_confirmation_step_to_check_customer_and_deceased_data_does_not_lose_it_permanently(): void
    {
        $draftId = $this->victimDraftThroughPayment();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('currentStep', BookingWizardStep::CONFIRMATION)
            ->call('goToStep', BookingWizardStep::CUSTOMER_AND_DECEASED_DATA)
            ->assertSet('currentStep', BookingWizardStep::CUSTOMER_AND_DECEASED_DATA)
            ->call('goToStep', BookingWizardStep::CONFIRMATION)
            ->assertSet('currentStep', BookingWizardStep::CONFIRMATION);
    }

    // =====================================================================
    // C2 — privacy consent is a real act, not a stamped assumption
    // =====================================================================

    public function test_the_privacy_consent_checkbox_starts_unticked(): void
    {
        Livewire::test(BookingWizard::class)->assertSet('privacyNoticeAccepted', false);
    }

    public function test_step_6_without_consent_is_rejected_and_persists_no_customer_data(): void
    {
        $draftId = $this->draftAtDiscoveryComplete();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('customerFullName', self::VICTIM_NAME)
            ->set('customerMobile', self::VICTIM_MOBILE)
            ->set('customerEmail', self::VICTIM_EMAIL)
            ->set('customerAddress', self::VICTIM_ADDRESS)
            ->set('customerRelationship', BookingRelationshipCode::ANAK)
            ->set('customerContactChannel', BookingContactChannel::WHATSAPP)
            ->set('privacyNoticeAccepted', false)
            ->set('deceasedFullName', self::VICTIM_DECEASED_NAME)
            ->set('deceasedDateOfBirth', '1948-03-12')
            ->set('deceasedDateOfDeath', '2026-08-01')
            ->set('deceasedRelationship', BookingRelationshipCode::ORANG_TUA)
            ->set('deceasedGender', BookingGender::LAKI_LAKI)
            ->call('saveStep2')
            ->assertHasErrors(['privacy_notice_accepted'])
            ->assertSet('autosaveState', 'failed');

        $draft = BookingDraft::query()->findOrFail($draftId);

        $this->assertNull($draft->customer_full_name, 'A refused consent must refuse the whole step, not just the checkbox.');
        $this->assertNull($draft->customer_email);
        $this->assertNull($draft->privacy_notice_accepted_at);
    }

    public function test_step_6_with_consent_persists_the_customer_data_and_stamps_the_consent_time(): void
    {
        $draftId = $this->draftAtDiscoveryComplete();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('customerFullName', self::VICTIM_NAME)
            ->set('customerMobile', self::VICTIM_MOBILE)
            ->set('customerEmail', self::VICTIM_EMAIL)
            ->set('customerAddress', self::VICTIM_ADDRESS)
            ->set('customerRelationship', BookingRelationshipCode::ANAK)
            ->set('customerContactChannel', BookingContactChannel::WHATSAPP)
            ->set('privacyNoticeAccepted', true)
            ->set('deceasedFullName', self::VICTIM_DECEASED_NAME)
            ->set('deceasedDateOfBirth', '1948-03-12')
            ->set('deceasedDateOfDeath', '2026-08-01')
            ->set('deceasedRelationship', BookingRelationshipCode::ORANG_TUA)
            ->set('deceasedGender', BookingGender::LAKI_LAKI)
            ->call('saveStep2')
            ->assertHasNoErrors()
            ->assertSet('autosaveState', 'saved');

        $draft = BookingDraft::query()->findOrFail($draftId);

        $this->assertSame(self::VICTIM_NAME, $draft->customer_full_name);
        $this->assertSame(self::VICTIM_EMAIL, $draft->customer_email);
        $this->assertSame(self::VICTIM_DECEASED_NAME, $draft->deceased_full_name);
        $this->assertNotNull($draft->privacy_notice_accepted_at, 'Consent given must be recorded, and only then.');
    }

    public function test_resuming_a_consented_draft_rehydrates_the_checkbox_as_ticked(): void
    {
        $draftId = $this->victimDraftWithCustomerAndDeceasedData();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('privacyNoticeAccepted', true);
    }

    // =====================================================================
    // C3 — G-PAY-01 enforced server-side, and a manual fallback that works
    // =====================================================================

    public function test_a_closed_payment_gate_rejects_a_direct_online_save_call(): void
    {
        $this->withClosedPaymentGate();
        $draftId = $this->victimDraftWithCustomerAndDeceasedData();

        // Livewire exposes every public method to the client, so this IS the
        // bypass path — hiding the online button in Blade never closed it.
        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep3', BookingPaymentMethod::ONLINE)
            ->assertHasErrors(['payment_method'])
            ->assertSet('autosaveState', 'failed');

        $draft = BookingDraft::query()->findOrFail($draftId);

        $this->assertNull($draft->payment_method, 'A closed gate must leave nothing behind on the row.');
        $this->assertNull($draft->payment_reference);
    }

    public function test_a_closed_payment_gate_still_allows_the_manual_path_with_a_reference(): void
    {
        $this->withClosedPaymentGate();
        $draftId = $this->victimDraftWithCustomerAndDeceasedData();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('paymentReference', self::VICTIM_PAYMENT_REFERENCE)
            ->call('saveStep3', BookingPaymentMethod::MANUAL)
            ->assertHasNoErrors()
            ->assertSet('autosaveState', 'saved');

        $draft = BookingDraft::query()->findOrFail($draftId);

        $this->assertSame(BookingPaymentMethod::MANUAL, $draft->payment_method);
        $this->assertSame(
            self::VICTIM_PAYMENT_REFERENCE,
            $draft->payment_reference,
            'The manual path hardcoded a null reference, so it could never validate — the fallback must actually be reachable.',
        );
    }

    public function test_the_manual_path_refuses_a_blank_payment_reference(): void
    {
        $this->withClosedPaymentGate();
        $draftId = $this->victimDraftWithCustomerAndDeceasedData();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('paymentReference', '   ')
            ->call('saveStep3', BookingPaymentMethod::MANUAL)
            ->assertHasErrors(['payment_reference'])
            ->assertSet('autosaveState', 'failed');

        $this->assertNull(BookingDraft::query()->findOrFail($draftId)->payment_method);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * A DIFFERENT visitor holding the same draft id — which is all an
     * attacker ever needs, because the id leaks through browser history,
     * `Referer` headers and access logs. Everything else about the session is
     * gone.
     */
    private function becomeADifferentVisitor(): void
    {
        Session::flush();
    }

    /**
     * A different, but genuinely AUTHENTICATED, visitor — as opposed to
     * `becomeADifferentVisitor()`, which also logs out any authenticated
     * user because session-guard state lives in session data. `Session::
     * flush()` first, then `actingAs()`, so the new auth state does not
     * inherit anything from the previous session (including any resume
     * secret it held).
     */
    private function becomeADifferentAuthenticatedVisitor(User $user): void
    {
        Session::flush();
        $this->actingAs($user);
    }

    private function withClosedPaymentGate(): void
    {
        $source = new class implements GateRegistrySource
        {
            public function load(): GateRegistrySnapshot
            {
                return new GateRegistrySnapshot([
                    'G-PAY-01' => GateState::fromRecord('G-PAY-01', open: false),
                ]);
            }
        };

        $this->app->instance(ModeResolver::class, new ModeResolver(new FeatureGateResolver($source)));

        $this->assertSame(
            PaymentMode::ManualCoordination,
            app(ModeResolver::class)->paymentMode(),
            'These tests are about CLOSED-gate behaviour; if the gate resolves open they prove nothing.',
        );
    }

    private function jakartaCemeteryWithoutPackages(): Cemetery
    {
        return $this->cemeteryWithoutPackages(LaunchCityCode::JAKARTA);
    }

    private function cemeteryWithoutPackages(string $cityCode): Cemetery
    {
        return Cemetery::query()
            ->where('city', $cityCode)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();
    }

    /**
     * @return list<array{code: string, quantity: int}>
     */
    private function basicServicesPayload(): array
    {
        return array_map(
            static fn (string $code): array => ['code' => $code, 'quantity' => 1],
            ServiceCode::BASIC_CODES,
        );
    }

    /**
     * A draft owned by the CURRENT session, with DISCOVERY complete — the
     * merged step that used to be steps 1-4. `SaveBookingDraftStep`'s
     * sequencing rule lets `CUSTOMER_AND_DECEASED_DATA` through once this
     * one step is done.
     */
    private function draftAtDiscoveryComplete(): string
    {
        $cemetery = $this->jakartaCemeteryWithoutPackages();

        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA, $cemetery->id, null, BookingServiceType::NEW_GRAVE, $this->basicServicesPayload())
            ->assertHasNoErrors()
            ->get('draftId');

        $this->assertIsString($draftId);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('currentStep', BookingWizardStep::CUSTOMER_AND_DECEASED_DATA);

        return $draftId;
    }

    /**
     * The same draft carried through `saveStep2()` — the merged
     * customer+deceased save that REPLACES the old `saveStep6()`/
     * `saveStep7()` pair. Lands directly on PAYMENT, the earliest point
     * `saveStep3()` is allowed to be saved at all. Owned by the current
     * session.
     */
    private function victimDraftWithCustomerAndDeceasedData(): string
    {
        $draftId = $this->draftAtDiscoveryComplete();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('customerFullName', self::VICTIM_NAME)
            ->set('customerMobile', self::VICTIM_MOBILE)
            ->set('customerEmail', self::VICTIM_EMAIL)
            ->set('customerAddress', self::VICTIM_ADDRESS)
            ->set('customerRelationship', BookingRelationshipCode::ANAK)
            ->set('customerContactChannel', BookingContactChannel::WHATSAPP)
            ->set('privacyNoticeAccepted', true)
            ->set('deceasedFullName', self::VICTIM_DECEASED_NAME)
            ->set('deceasedDateOfBirth', '1948-03-12')
            ->set('deceasedDateOfDeath', '2026-08-01')
            ->set('deceasedRelationship', BookingRelationshipCode::ORANG_TUA)
            ->set('deceasedGender', BookingGender::LAKI_LAKI)
            ->call('saveStep2')
            ->assertHasNoErrors()
            ->assertSet('currentStep', BookingWizardStep::PAYMENT);

        return $draftId;
    }

    /**
     * Carried all the way to the CONFIRMATION screen.
     */
    private function victimDraftThroughPayment(): string
    {
        $draftId = $this->victimDraftWithCustomerAndDeceasedData();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('paymentReference', self::VICTIM_PAYMENT_REFERENCE)
            ->call('saveStep3', BookingPaymentMethod::MANUAL)
            ->assertHasNoErrors()
            ->assertSet('currentStep', BookingWizardStep::CONFIRMATION);

        return $draftId;
    }

    /**
     * Every column an unauthorised write could plausibly touch, plus the
     * optimistic-concurrency `version` — which bumps on any accepted save, so
     * it catches a write that happened to store identical values.
     *
     * @return array<string, mixed>
     */
    private function rowSnapshotOf(string $draftId): array
    {
        $draft = BookingDraft::query()->findOrFail($draftId);

        return [
            'customer_full_name' => $draft->customer_full_name,
            'customer_mobile' => $draft->customer_mobile,
            'customer_email' => $draft->customer_email,
            'customer_address' => $draft->customer_address,
            'customer_relationship' => $draft->customer_relationship,
            'customer_contact_channel' => $draft->customer_contact_channel,
            'deceased_full_name' => $draft->deceased_full_name,
            'deceased_relationship' => $draft->deceased_relationship,
            'payment_method' => $draft->payment_method,
            'payment_reference' => $draft->payment_reference,
            'current_step' => $draft->current_step,
            'completed_steps' => $draft->completed_steps,
            'version' => $draft->version,
        ];
    }
}
