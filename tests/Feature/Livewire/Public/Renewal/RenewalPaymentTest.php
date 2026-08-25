<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Renewal;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalQuote;
use App\Domain\Renewal\RenewalStatus;
use App\Livewire\Public\Renewal\RenewalPayment;
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
            ->assertSee('Langkah 5 dari 6');

        foreach (['Kota', 'TPU/TPS', 'Cari Makam', 'Biaya', 'Pembayaran', 'Konfirmasi'] as $label) {
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
}
