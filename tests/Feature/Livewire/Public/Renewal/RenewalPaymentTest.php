<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Renewal;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalQuote;
use App\Domain\Renewal\RenewalGraveSelection;
use App\Domain\Renewal\RenewalStatus;
use App\Livewire\Public\Renewal\RenewalPayment;
use App\Livewire\Public\Renewal\RenewalStart;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\SessionState;
use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `App\Livewire\Public\Renewal\RenewalPayment`'s Blade view now genuinely
 * branches on `paymentState` (2026-08-25 online-payment-gateway lane, Task
 * 3) — previously it rendered the manual-coordination card unconditionally
 * even when the component had already computed `paymentState === 'online'`.
 *
 * `createRenewalWithQuote()`'s fixture (published grave, accepted+unexpired
 * quote, amount trivially equal to itself) satisfies all four of
 * `GuardRenewalPaymentOpening`'s conditions on its own — so with `G-PAY-01`
 * OPEN it is genuinely online-eligible, and with the gate CLOSED it is
 * genuinely `manualCoordinationRequired`. Both are exercised below.
 */
final class RenewalPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const string MERCHANT_REF = 'mk-merchant-dev';

    private const string BADAN_USAHA_REF = 'badan-usaha-dev';

    private function openThePaymentGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'open']);
    }

    private function openTheDataGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open']);
    }

    private function closeThePaymentGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'closed']);
    }

    /**
     * `FeatureGateResolver` resolves its gate snapshot ONCE and caches it in
     * a plain property for the rest of the (scoped) container lifetime — see
     * that class's own doc block. Within a single test method, a Livewire
     * component's later `->call(...)` does not cross a real HTTP request
     * boundary, so mutating the `feature_gates` row after the component has
     * already resolved a gate once (as `openThePaymentGate()`/
     * `closeThePaymentGate()` do) does NOT change what a later call in the
     * SAME test observes. To genuinely simulate "the gate flips between
     * render and click" within one test method, rebind `ModeResolver`
     * itself to a fresh instance wrapping a fresh `FeatureGateResolver` —
     * exactly `BookingWizardOnlinePaymentTest::withPaymentGate()`'s own
     * pattern, mirrored here rather than re-invented.
     */
    private function bindPaymentGate(bool $open): void
    {
        $source = new class($open) implements GateRegistrySource
        {
            public function __construct(private readonly bool $open) {}

            public function load(): GateRegistrySnapshot
            {
                return new GateRegistrySnapshot([
                    'G-PAY-01' => GateState::fromRecord('G-PAY-01', open: $this->open),
                ]);
            }
        };

        $this->app->instance(ModeResolver::class, new ModeResolver(new FeatureGateResolver($source)));
    }

    private function cemeteryWithPrice(): Cemetery
    {
        return Cemetery::query()->where('slug', CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])->sole();
    }

    private function createRenewalWithQuote(): Renewal
    {
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);
        $renewal = Renewal::factory()->create(['grave_record_id' => $grave->id]);
        RenewalQuote::factory()->accepted()->create(['renewal_id' => $renewal->id]);

        return $renewal;
    }

    private function configurePaymentProvider(): void
    {
        config([
            'payment.merchant_ref' => self::MERCHANT_REF,
            'payment.badan_usaha_ref' => self::BADAN_USAHA_REF,
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.api_key' => 'test-key',
        ]);
    }

    /**
     * `RenewalQuoteFactory`'s default `amount_minor` (`150_000_00`) is
     * Rp 150.000 in the provider's whole-rupiah wire unit.
     */
    private function fakeProviderSuccess(): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response([
                'payment_id' => 'uuid-renewal-1',
                'order_id' => 'PPJ-1',
                'amount' => 150_000,
                'fee' => 3_880,
                'net_amount' => 146_120,
                'payment_link_url' => 'https://checkout.sumopod.com/renewal-x',
                'status' => 'pending',
            ], 201),
        ]);
    }

    public function test_the_payment_screen_shows_manual_coordination_when_gate_is_closed(): void
    {
        $this->closeThePaymentGate();
        $renewal = $this->createRenewalWithQuote();

        Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
            ->assertOk()
            ->assertSee('Pembayaran')
            ->assertSee('koordinasi manual')
            ->assertDontSee('Bayar Sekarang');
    }

    public function test_the_payment_step_is_never_removed_when_gate_is_closed(): void
    {
        $this->closeThePaymentGate();
        $renewal = $this->createRenewalWithQuote();

        Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
            ->assertOk()
            ->assertSee('Langkah 2 dari 3');

        foreach (['Cari Makam', 'Biaya & Bayar', 'Konfirmasi'] as $label) {
            Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
                ->assertSee($label);
        }
    }

    /**
     * With `G-PAY-01` open and every guard condition satisfied,
     * `GuardRenewalPaymentOpening` returns `allowed(manualCoordinationRequired:
     * false)` — `resolveState()` computes `paymentState === 'online'`, and
     * the view must render the real "Bayar Sekarang" affordance instead of
     * the manual-coordination copy. Before this task's fix the view rendered
     * the manual card unconditionally regardless of `paymentState`.
     */
    public function test_the_payment_screen_shows_the_bayar_sekarang_button_when_eligible_and_gate_is_open(): void
    {
        $this->openThePaymentGate();
        $renewal = $this->createRenewalWithQuote();

        Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
            ->assertOk()
            ->assertSee('Pembayaran Online')
            ->assertSee('Bayar Sekarang')
            ->assertDontSee('koordinasi manual');
    }

    /**
     * ADR-0035 item 1's mitigation, mirrored from the booking wizard's own
     * online card: the sandbox warning must be unmissable before the
     * "Bayar Sekarang" button whenever the active provider is the sandbox
     * (the only provider configured in tests, per `TestCase`/`phpunit.xml`).
     */
    public function test_the_sandbox_warning_shows_before_the_bayar_sekarang_button(): void
    {
        $this->openThePaymentGate();
        $renewal = $this->createRenewalWithQuote();

        Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
            ->assertSeeInOrder([
                'ANDA TIDAK AKAN MENGIRIM UANG SUNGGUHAN',
                'Bayar Sekarang',
            ]);
    }

    /**
     * A real, eligible renewal — clicking "Bayar Sekarang" (`payOnline()`)
     * opens a genuine `PaymentSession` via `OpenPaymentSession::
     * authorizeRenewal()` and redirects to the provider's hosted-checkout
     * link, exactly as `BookingWizardOnlinePaymentTest`'s happy path proves
     * for booking.
     */
    public function test_pay_online_opens_a_session_and_redirects_to_the_hosted_checkout(): void
    {
        $this->openThePaymentGate();
        $this->configurePaymentProvider();
        $this->fakeProviderSuccess();

        $renewal = $this->createRenewalWithQuote();
        $quote = $renewal->quotes()->latest()->first();

        Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
            ->call('payOnline')
            ->assertRedirect('https://checkout.sumopod.com/renewal-x');

        $session = PaymentSession::query()->sole();

        $this->assertSame(SessionState::AwaitingPayment->value, $session->state);
        $this->assertSame($quote->amountAsMoney()->toMinorInt(), $session->amount_minor);
        $this->assertSame(self::MERCHANT_REF, $session->merchant_ref);
        $this->assertSame('https://checkout.sumopod.com/renewal-x', $session->payment_link_url);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/payments'));
    }

    /**
     * The re-click guard (whole-branch review fix wave, 25 Aug 2026):
     * clicking "Bayar Sekarang" twice — the ordinary "backed out of the
     * hosted checkout and returned" flow `#[Url]` makes bookmarkable — must
     * NOT open a second real `PaymentSession` and must NOT call the provider
     * a second time. The second call re-points at the SAME stored session's
     * `link_url` instead, mirroring `CheckoutOnlinePaymentTest::
     * test_reclicking_bayar_online_is_idempotent()` exactly.
     */
    public function test_reclicking_bayar_sekarang_does_not_open_a_second_session(): void
    {
        $this->openThePaymentGate();
        $this->configurePaymentProvider();
        $this->fakeProviderSuccess();

        $renewal = $this->createRenewalWithQuote();

        $component = Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id]);

        $component->call('payOnline')->assertRedirect('https://checkout.sumopod.com/renewal-x');
        $component->call('payOnline')->assertRedirect('https://checkout.sumopod.com/renewal-x');

        $this->assertSame(1, PaymentSession::query()->count());
        Http::assertSentCount(1);
    }

    /**
     * A stored session that has already reached a TERMINAL state (`Paid`
     * here — settled by the webhook while the customer's tab sat idle) must
     * never be re-opened or re-redirected-to from a stale click. The
     * manual-coordination card / webhook-driven state governs recovery
     * instead, mirroring `Checkout::payOnline()`'s own terminal branch.
     */
    public function test_reclicking_after_the_stored_session_is_already_paid_does_not_redirect_or_reopen(): void
    {
        $this->openThePaymentGate();
        $this->configurePaymentProvider();
        $this->fakeProviderSuccess();

        $renewal = $this->createRenewalWithQuote();

        $component = Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id]);
        $component->call('payOnline')->assertRedirect('https://checkout.sumopod.com/renewal-x');

        $session = PaymentSession::query()->sole();
        $session->forceFill(['state' => SessionState::Paid->value])->save();

        Http::fake();

        $component->call('payOnline')->assertNoRedirect();

        $this->assertSame(1, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    /**
     * A renewal already `DIBAYAR` (a resumed/duplicate tab, or a race with a
     * webhook that just settled it) must be refused by `OpenPaymentSession::
     * authorizeRenewal()`'s pre-guard `assertRenewalNotAlreadySettled()`
     * check — a `PaymentSessionOrderAlreadyPaidException`, genuinely
     * DIFFERENT from a guard denial (`PaymentSessionOpeningDeniedException`).
     * No second session is opened, and the component shows honest copy
     * instead of a 500.
     */
    public function test_pay_online_on_an_already_settled_renewal_is_refused_without_opening_a_second_session(): void
    {
        $this->openThePaymentGate();
        $this->configurePaymentProvider();
        Http::fake();

        $renewal = $this->createRenewalWithQuote();
        $renewal->forceFill(['status' => RenewalStatus::DIBAYAR, 'settled_at' => now()])->save();

        Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
            ->call('payOnline')
            ->assertSet('checkoutError', 'Perpanjangan ini telah dibayar dan tidak perlu dibayar lagi.');

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
    }

    /**
     * The gate closes between this screen's render and the click (or the
     * grave/quote state changes underneath it) — `authorizeRenewal()`'s own
     * guard re-evaluation denies, and the component shows the fixed
     * fail-closed copy rather than trusting its own earlier read.
     */
    public function test_pay_online_is_refused_when_the_gate_closes_before_the_click(): void
    {
        $this->configurePaymentProvider();
        Http::fake();

        $this->bindPaymentGate(open: true);
        $renewal = $this->createRenewalWithQuote();

        $component = Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
            ->assertSee('Bayar Sekarang');

        $this->bindPaymentGate(open: false);

        $component->call('payOnline')
            ->assertSet('checkoutError', 'Pembayaran online belum dapat dibuka saat ini. Silakan hubungi petugas kami untuk koordinasi manual.');

        $this->assertSame(0, PaymentSession::query()->count());
        Http::assertNothingSent();
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

    /**
     * With no query parameter at all the screen used to fall straight through
     * to the success branch and render a complete manual-coordination card,
     * plus a "continue to confirmation" link carrying an empty renewal id.
     */
    public function test_a_missing_renewal_parameter_reports_not_found_rather_than_a_payable_card(): void
    {
        Livewire::test(RenewalPayment::class)
            ->assertOk()
            ->assertSee('tidak ditemukan')
            ->assertDontSee('koordinasi manual')
            ->assertDontSee('Lanjutkan ke Konfirmasi');
    }

    /**
     * The `not_found` state is reachable with nothing at all left to act on,
     * including AFTER a successful acceptance: `terimaDanLanjutkan()` calls
     * `RenewalGraveSelection::forget()` and then sets `$perpanjangan`, a
     * `#[Url(history: true)]` property — so a browser Back lands on this
     * screen with the session selection already forgotten and the parameter
     * gone, and `resolveState()` falls through to here. A support link on
     * its own would be a dead end for a visitor who simply wants to search
     * again, so the state must offer a real route back into the flow.
     *
     * The Back-button interaction itself is NOT what this asserts (no
     * browser toolchain is available on this host, and Livewire's
     * history-restoration mechanics are not exercised by
     * `Livewire::test()`); this pins the cheaper, unconditional property —
     * the state is recoverable however a visitor reached it.
     */
    public function test_the_not_found_state_offers_a_way_back_into_the_search_flow(): void
    {
        Livewire::test(RenewalPayment::class)
            ->assertOk()
            ->assertSee('tidak ditemukan')
            ->assertSee('Cari makam lain')
            ->assertSeeHtml('href="/perpanjangan"')
            // The existing support escape hatch is not traded away for it.
            ->assertSee('/bantuan');
    }

    /**
     * The same recoverability, on the other route into `not_found` — a
     * `?perpanjangan=` that names no real renewal (stale bookmark, purged
     * row, tampered id).
     */
    public function test_an_unknown_renewal_id_also_offers_a_way_back_into_the_search_flow(): void
    {
        Livewire::test(RenewalPayment::class, ['perpanjangan' => '00000000-0000-0000-0000-000000000000'])
            ->assertOk()
            ->assertSee('Cari makam lain')
            ->assertSeeHtml('href="/perpanjangan"');
    }

    /**
     * The guard's `denialReason()` names the specific condition that failed.
     * On an anonymous page that is an oracle: it distinguishes "no such
     * renewal" from "restricted grave" from "stale quote" for anyone
     * iterating UUIDs. The refusal copy must be one fixed message.
     */
    public function test_a_denial_never_prints_the_guards_specific_reason(): void
    {
        $this->openThePaymentGate();

        $renewal = $this->createRenewalWithQuote();
        $renewal->quotes()->update(['accepted_at' => null]);

        Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
            ->assertOk()
            ->assertSee('tidak dapat diproses')
            ->assertDontSee('quote')
            ->assertDontSee('Grave record')
            ->assertDontSee('unexpired')
            ->assertDontSee('does not match');
    }

    /**
     * A renewal carrying no quote at all must refuse with the same fixed copy
     * — not a different message that would tell the caller which case it hit.
     */
    public function test_a_renewal_with_no_quote_refuses_with_the_same_fixed_copy(): void
    {
        $this->openThePaymentGate();

        $grave = GraveRecord::factory()->create(['cemetery_id' => $this->cemeteryWithPrice()->id]);
        $renewal = Renewal::factory()->create(['grave_record_id' => $grave->id]);

        Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
            ->assertOk()
            ->assertSee('tidak dapat diproses')
            ->assertDontSee('devis')
            ->assertDontSee('quirote');
    }

    /**
     * Migrated from RenewalFeeTest — the fee section still always shows
     * tariff source and last-update, now reached via a session-remembered
     * selection instead of a `?makam=` constructor param.
     */
    public function test_the_fee_screen_always_shows_the_tariff_source_and_last_update(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);
        RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)
            ->assertOk()
            ->assertSee('Sumber tarif')
            ->assertSee('Terakhir diperbarui');
    }

    /**
     * Migrated from RenewalFeeTest.
     */
    public function test_no_late_fine_figure_is_rendered_when_there_is_no_written_basis(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $cemetery->id,
            'due_date' => now()->subYears(3)->format('Y-m-d'),
        ]);
        RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)
            ->assertOk()
            ->assertDontSee('Denda');
    }

    /**
     * Migrated from RenewalFeeTest.
     */
    public function test_the_fee_screen_shows_the_renewal_amount(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);
        RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)
            ->assertOk()
            ->assertSee('Rp');
    }

    /**
     * Migrated from RenewalFeeTest.
     */
    public function test_a_grave_without_a_tariff_source_renders_a_useful_error(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();

        DB::table('cemeteries')
            ->where('id', $cemetery->id)
            ->update(['price_min' => null, 'price_source' => null, 'price_effective_at' => null]);

        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);
        RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)
            ->assertOk()
            ->assertSee('tarif');
    }

    /**
     * Migrated from RenewalFeeTest — the fee section and the payment
     * section are now the SAME journey step (`RenewalJourneyStep::
     * FEE_AND_PAYMENT`, screen 2 of 3), so this asserts step 2 rather than
     * the pre-consolidation step 4.
     */
    public function test_the_stepper_shows_step_2_as_current(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);
        RenewalGraveSelection::remember($grave->id);

        $component = Livewire::test(RenewalPayment::class);

        $component->assertSee('Langkah 2 dari 3');

        foreach (['Cari Makam', 'Biaya & Bayar', 'Konfirmasi'] as $label) {
            $component->assertSee($label);
        }
    }

    /**
     * Migrated from RenewalFeeTest — renamed to avoid colliding with this
     * class's own pre-existing `test_support_escape_hatch_is_present`
     * (the payment section's escape hatch).
     */
    public function test_support_escape_hatch_is_present_on_the_fee_section(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);
        RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)
            ->assertOk()
            ->assertSee('/bantuan');
    }

    /**
     * Migrated from RenewalFeeTest — the defect this guards against:
     * `mount()` used to call an Action that persisted a `Renewal` and a
     * `RenewalQuote`. Every anonymous GET of this URL — a refresh, a
     * crawler, a link preview — created rows and claimed the AC11 unique
     * business key `(grave_record_id, target_due_period)` for a grave the
     * visitor has no relationship to. The second GET then hit the
     * constraint, and the squatted key also blocked the admin AC10 marking
     * path for that grave and period.
     */
    public function test_rendering_the_fee_screen_writes_nothing(): void
    {
        $this->openTheDataGate();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $this->cemeteryWithPrice()->id]);
        RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)->assertOk();
        RenewalGraveSelection::remember($grave->id);
        Livewire::test(RenewalPayment::class)->assertOk();
        RenewalGraveSelection::remember($grave->id);
        Livewire::test(RenewalPayment::class)->assertOk();

        $this->assertDatabaseCount('renewals', 0);
        $this->assertDatabaseCount('renewal_quotes', 0);
    }

    /**
     * Migrated from RenewalFeeTest — acceptance still creates exactly one
     * renewal; the merged component no longer redirects (Implementation
     * Decision 4) so this asserts `$perpanjangan` is now set in place of
     * the old `assertRedirect()`.
     */
    public function test_accepting_the_quote_creates_exactly_one_renewal_and_reveals_payment_in_place(): void
    {
        $this->openTheDataGate();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $this->cemeteryWithPrice()->id]);
        RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)
            ->call('terimaDanLanjutkan')
            ->assertSet('perpanjangan', fn (string $id) => $id !== '')
            ->assertSee('Pembayaran');

        $this->assertDatabaseCount('renewals', 1);
        $this->assertDatabaseCount('renewal_quotes', 1);

        RenewalGraveSelection::remember($grave->id);
        Livewire::test(RenewalPayment::class)
            ->call('terimaDanLanjutkan')
            ->assertSet('actionMessage', fn (string $m) => str_contains($m, 'sudah tercatat'));

        $this->assertDatabaseCount('renewals', 1);
    }

    /**
     * Migrated from RenewalFeeTest. AC14 — a `closed` record is
     * acknowledged as existing — never silently dropped, per
     * `GraveRecordAccessMode`'s own doc block — but discloses no fields,
     * and cannot be renewed online.
     */
    public function test_a_closed_record_shows_the_privacy_limited_state_and_no_grave_fields(): void
    {
        $this->openTheDataGate();
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $this->cemeteryWithPrice()->id,
            'deceased_name' => 'Budi Santoso Rahasia',
            'block' => 'Z-99',
            'access_mode' => GraveRecordAccessMode::CLOSED,
        ]);
        RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)
            ->assertOk()
            ->assertSee('dibatasi')
            ->assertDontSee('Budi Santoso Rahasia')
            ->assertDontSee('Z-99')
            ->assertDontSee('Sumber tarif');
    }

    /**
     * Migrated from RenewalFeeTest — `limited` withholds the deceased's
     * identity and dates while still naming the location, so it too must
     * not render a fee.
     */
    public function test_a_limited_record_shows_the_privacy_limited_state_and_no_identity(): void
    {
        $this->openTheDataGate();
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $this->cemeteryWithPrice()->id,
            'deceased_name' => 'Siti Aminah Rahasia',
            'access_mode' => GraveRecordAccessMode::LIMITED,
        ]);
        RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)
            ->assertOk()
            ->assertSee('dibatasi')
            ->assertDontSee('Siti Aminah Rahasia')
            ->assertDontSee('Sumber tarif');
    }

    /**
     * Migrated from RenewalFeeTest.
     */
    public function test_a_restricted_record_cannot_be_renewed_by_calling_the_action_directly(): void
    {
        $this->openTheDataGate();
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $this->cemeteryWithPrice()->id,
            'access_mode' => GraveRecordAccessMode::CLOSED,
        ]);
        RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)
            ->call('terimaDanLanjutkan')
            ->assertSet('perpanjangan', '');

        $this->assertDatabaseCount('renewals', 0);
    }

    /**
     * Migrated from RenewalFeeTest, re-expressed: the merged component
     * never accepts a grave id from the client at all (no `?makam=`
     * equivalent), so an unknown grave is expressed as an unknown id
     * remembered in the session instead of an unknown URL parameter.
     */
    public function test_an_unknown_grave_reports_not_found_rather_than_rendering_a_broken_card(): void
    {
        $this->openTheDataGate();
        RenewalGraveSelection::remember('0198f000-0000-7000-8000-000000000000');

        Livewire::test(RenewalPayment::class)
            ->assertOk()
            ->assertSee('tidak ditemukan')
            ->assertDontSee('Sumber tarif');
    }

    /**
     * Migrated from RenewalFeeTest, re-expressed: `grave_records.id` is a
     * `uuid` column. `$makam` used to be a public, `#[Url]`-bound,
     * attacker-controlled string with no format validation of its own —
     * passing a non-UUID value straight to `find()` previously threw an
     * uncaught PDOException. The merged component never accepts a grave id
     * from the client at all, so this is re-expressed against a malformed
     * value remembered in the session (still reachable in principle if the
     * session value were ever corrupted) rather than a URL parameter.
     */
    public function test_a_malformed_makam_parameter_reports_not_found_rather_than_crashing(): void
    {
        $this->openTheDataGate();
        RenewalGraveSelection::remember('not-a-uuid');

        Livewire::test(RenewalPayment::class)
            ->assertOk()
            ->assertSee('tidak ditemukan')
            ->assertDontSee('Sumber tarif');

        RenewalGraveSelection::remember('not-a-uuid');
        Livewire::test(RenewalPayment::class)
            ->call('terimaDanLanjutkan')
            ->assertSet('perpanjangan', '');

        $this->assertDatabaseCount('renewals', 0);
    }

    /**
     * Migrated from RenewalFeeTest. `grave_records.due_date` is nullable,
     * so a published grave with no due date reaches this screen. There is
     * no period to renew and no quote to accept — the screen must show the
     * quote-unavailable state and acceptance must write nothing.
     */
    public function test_a_grave_without_a_due_date_shows_quote_unavailable_and_acceptance_writes_nothing(): void
    {
        $this->openTheDataGate();
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $this->cemeteryWithPrice()->id,
            'due_date' => null,
        ]);
        RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)
            ->assertOk()
            ->assertSee('Tarif tidak tersedia')
            ->assertDontSee('Lanjutkan ke Pembayaran');

        RenewalGraveSelection::remember($grave->id);
        Livewire::test(RenewalPayment::class)
            ->call('terimaDanLanjutkan')
            ->assertSet('perpanjangan', '');

        $this->assertDatabaseCount('renewals', 0);
        $this->assertDatabaseCount('renewal_quotes', 0);
    }

    /**
     * The whole point of Task 3's `RenewalGraveSelection` — proves a
     * complete search-then-fee-then-accept flow never puts the grave's id
     * anywhere the client can read it: not in the fee screen's rendered
     * HTML, not in any `#[Url]`-bound property, not in the URL Livewire
     * reflects for `$perpanjangan` (which only ever holds the RENEWAL's id,
     * created only after explicit acceptance).
     */
    public function test_a_search_then_fee_flow_never_exposes_the_grave_id_anywhere_client_visible(): void
    {
        $this->openTheDataGate();
        FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open']);
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $cemetery->id,
            'deceased_name' => 'Contoh Tanpa Id Uji',
        ]);

        $search = Livewire::test(RenewalStart::class, [
            'cemeteryId' => $cemetery->id,
            'name' => 'Contoh Tanpa Id Uji',
        ])->call('search');

        $this->assertStringNotContainsString($grave->id, $search->html());

        $search->call('selectGraveForRenewal', 0)->assertRedirect(route('perpanjangan.pembayaran'));

        $fee = Livewire::test(RenewalPayment::class);
        $this->assertStringNotContainsString($grave->id, $fee->html());
        $fee->assertSet('perpanjangan', '');

        $fee->call('terimaDanLanjutkan');

        $renewalId = $fee->get('perpanjangan');
        $this->assertNotSame('', $renewalId);
        $this->assertNotSame($grave->id, $renewalId);
        $this->assertDatabaseHas('renewals', ['id' => $renewalId, 'grave_record_id' => $grave->id]);
    }

    /**
     * OpenRenewal must fire only from the explicit "Terima Tarif" click —
     * never from mount, never from a bare render. Merely rendering the fee
     * section with a pending selection must write nothing.
     */
    public function test_merely_rendering_the_fee_section_writes_nothing(): void
    {
        $this->openTheDataGate();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $this->cemeteryWithPrice()->id]);
        RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)->assertOk();
        RenewalGraveSelection::remember($grave->id);
        Livewire::test(RenewalPayment::class)->assertOk();

        $this->assertDatabaseCount('renewals', 0);
    }

    /**
     * The payment section re-evaluates GuardRenewalPaymentOpening fresh on
     * every render, even immediately after acceptance within the same
     * component instance — never trusting a stale accepted-state from the
     * fee half. Mirrors `test_pay_online_is_refused_when_the_gate_closes_
     * before_the_click` but exercises the NEW in-place fee-to-payment
     * transition rather than a bookmark arrival.
     */
    public function test_the_payment_section_re_evaluates_the_guard_immediately_after_in_place_acceptance(): void
    {
        $this->openTheDataGate();
        $this->closeThePaymentGate();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $this->cemeteryWithPrice()->id]);
        RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)
            ->call('terimaDanLanjutkan')
            ->assertSee('koordinasi manual')
            ->assertDontSee('Bayar Sekarang');
    }
}
