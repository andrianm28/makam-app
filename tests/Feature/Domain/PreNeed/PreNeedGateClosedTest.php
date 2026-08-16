<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PreNeed;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\FuneralCase\Models\FuneralCase;
use App\Domain\OrderWorkflow\Actions\SubmitBookingDraft;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PreNeed\Actions\AcceptPreNeedAgreement;
use App\Domain\PreNeed\Actions\ActivatePreNeed;
use App\Domain\PreNeed\Actions\ProposePreNeedPackage;
use App\Domain\PreNeed\Actions\QuotePreNeed;
use App\Domain\PreNeed\Actions\ReservePreNeedPlot;
use App\Domain\PreNeed\Actions\SchedulePreNeedPayments;
use App\Domain\PreNeed\Actions\SettlePreNeed;
use App\Domain\PreNeed\Exceptions\PreNeedGateClosedException;
use App\Domain\PreNeed\Models\PreNeedCase;
use App\Domain\PreNeed\Models\PreNeedInterest;
use App\Domain\PreNeed\Models\PreNeedPaymentScheduleItem;
use App\Domain\PreNeed\PreNeedAuditActions;
use App\Domain\PreNeed\PreNeedCaseStatus;
use App\Domain\PreNeed\PreNeedInterestStatus;
use App\Domain\Quotation\Models\Quote;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\Modes\PreNeedMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 3 of `docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md`
 * — the G-LEGAL-01 fail-closed proof for the paid Pre-Need flow.
 *
 * With the gate closed (G-LEGAL-01 -> `PreNeedMode::InterestOnly`), EVERY
 * one of the seven paid actions must:
 *
 *   1. throw the uniform `PreNeedGateClosedException` — the same class,
 *      never an action-specific variant;
 *   2. leave the case and every dependent table untouched (no proposal,
 *      reservation, quote, agreement, schedule, settlement, or activation
 *      write);
 *   3. record exactly one `PRENEED_GATE_DENIED` audit row per attempt.
 *
 * The gate is closed through the SAME in-memory `GateRegistrySource` stub
 * `ModeResolverTest` uses — so the denial is proven against the server-side
 * `ModeResolver::preNeedMode()` read, not against the database seed.
 *
 * Interest registration is asserted to be UNAFFECTED while the gate is
 * closed (AC1: "register interest, request consultation, and receive
 * non-binding information only"). The consultation request does not exist
 * on this lane yet (Lane 3), so only the interest path is asserted here.
 */
final class PreNeedGateClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_paid_action_throws_the_uniform_exception_audits_the_denial_and_changes_no_state(): void
    {
        $this->bindGateRegistryWith('G-LEGAL-01', open: false);

        $context = $this->caseContext();

        $attempts = [
            'propose' => fn () => app(ProposePreNeedPackage::class)(
                $context['case'], $context['cemetery'], null, 'actor:admin-1', 'admin',
            ),
            'reserve' => fn () => app(ReservePreNeedPlot::class)(
                $context['case'], $context['plot'], 'actor:admin-1', 'admin',
            ),
            'quote' => fn () => app(QuotePreNeed::class)(
                $context['case'], Carbon::now()->addDays(7), 'actor:admin-1', 'admin',
            ),
            'accept' => fn () => app(AcceptPreNeedAgreement::class)(
                $context['case'], 'agmt-denied-1', 'actor:admin-1', 'admin',
            ),
            'schedule' => fn () => app(SchedulePreNeedPayments::class)(
                $context['case'], [['amount_minor' => 100_000_00, 'due_date' => '2026-09-01']], 'actor:admin-1', 'admin',
            ),
            'settle' => fn () => app(SettlePreNeed::class)(
                $context['case'], 'pv_denied_1', 'actor:admin-1', 'admin',
            ),
            'activate' => fn () => app(ActivatePreNeed::class)(
                $context['case'], $context['activationDraft'], 'actor:admin-1', 'admin',
            ),
        ];

        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                self::fail("Paid action [{$name}] must be denied while G-LEGAL-01 is closed.");
            } catch (PreNeedGateClosedException $exception) {
                // The UNIFORM exception: one class, every action, no
                // action-specific variant sneaking in beside it.
                self::assertStringContainsString('G-LEGAL-01', $exception->getMessage());
            }
        }

        // One denial audit per attempt, one action name, outcome denied.
        self::assertSame(
            7,
            AuditEvent::query()->where('action', PreNeedAuditActions::PRENEED_GATE_DENIED)->count(),
        );
        self::assertSame(
            7,
            AuditEvent::query()->where('action', PreNeedAuditActions::PRENEED_GATE_DENIED)
                ->where('outcome', 'denied')
                ->count(),
        );

        // No state change anywhere: the case is still `interest`, none of
        // the paid-flow links are set, and no dependent row exists.
        $fresh = $context['case']->fresh();
        self::assertSame(PreNeedCaseStatus::INTEREST->value, $fresh->status);
        self::assertNull($fresh->cemetery_id);
        self::assertNull($fresh->cemetery_package_id);
        self::assertNull($fresh->agreement_id);
        self::assertNull($fresh->quote_id);
        self::assertNull($fresh->plot_reservation_id);
        self::assertNull($fresh->activated_funeral_case_id);
        self::assertNull($fresh->accepted_by_ref);
        self::assertNull($fresh->accepted_quote_id);
        self::assertNull($fresh->settled_paid_source_ref);

        self::assertSame(0, PreNeedPaymentScheduleItem::query()->count());
        self::assertSame(0, Quote::query()->count());
        self::assertSame(0, PlotReservation::query()->count());
        self::assertSame(0, FuneralCase::query()->count());
        self::assertSame(PlotState::AVAILABLE, $context['plot']->fresh()->plot_state);
    }

    /**
     * AC1: the interest flow itself is never gated by G-LEGAL-01 — a closed
     * gate still registers interest, creates the PRE_NEED order, and creates
     * no financial obligation (that is Task 1's lane's proof; this pins the
     * Pre-Need arm of it from this suite's own door).
     */
    public function test_interest_registration_is_unaffected_while_the_gate_is_closed(): void
    {
        $this->bindGateRegistryWith('G-LEGAL-01', open: false);

        $draft = $this->draft(BookingServiceType::PRE_NEED);

        $order = app(SubmitBookingDraft::class)($draft, 'idem-gate-closed-interest');

        self::assertNotNull($order->pre_need_case_id);

        $interest = PreNeedInterest::query()->findOrFail($order->pre_need_case_id);
        self::assertSame(PreNeedInterestStatus::INTEREST_REGISTERED->value, $interest->status);
        self::assertSame(PreNeedMode::InterestOnly->value, $interest->gate_mode);

        // The interest row is the ONLY pre-need record: no case, no schedule.
        self::assertSame(0, PreNeedCase::query()->count());
        self::assertSame(0, PreNeedPaymentScheduleItem::query()->count());
    }

    /**
     * The interest + order + case the paid actions are denied on. The case
     * is created directly (Task 4's admin surface is the real creator; the
     * domain action receives it), at `interest` status, linked to the
     * submit-time interest — so an order EXISTS for the order-dependent
     * actions and the denial is still what fires, not a missing-order
     * refusal.
     *
     * @return array{case: PreNeedCase, cemetery: Cemetery, plot: GravePlot, activationDraft: BookingDraft}
     */
    private function caseContext(): array
    {
        $this->bindGateRegistryWith('G-LEGAL-01', open: false);

        $draft = $this->draft(BookingServiceType::PRE_NEED);
        $order = app(SubmitBookingDraft::class)($draft, 'idem-gate-closed-1');

        $interest = PreNeedInterest::query()->findOrFail($order->pre_need_case_id);

        $cemetery = $this->cemetery();
        $plot = $this->plot($cemetery);

        return [
            'case' => PreNeedCase::query()->create([
                'pre_need_interest_id' => $interest->getKey(),
                'status' => PreNeedCaseStatus::INTEREST->value,
            ]),
            'cemetery' => $cemetery,
            'plot' => $plot,
            'activationDraft' => $this->draft(BookingServiceType::NEW_GRAVE),
        ];
    }

    private function draft(string $serviceType): BookingDraft
    {
        return BookingDraft::query()->create([
            'city_code' => LaunchCityCode::JAKARTA,
            'service_type' => $serviceType,
        ]);
    }

    private function cemetery(): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba Pre-Need',
            'slug' => 'tpu-uji-preneed-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
    }

    private function plot(Cemetery $cemetery): GravePlot
    {
        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-PN',
            'name' => 'Blok PN',
            'capacity' => 1,
        ]);

        return GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '001',
            'plot_state' => PlotState::AVAILABLE,
        ]);
    }

    /**
     * Replaces the database-backed gate registry with an in-memory source,
     * the same stub shape `tests/Unit/Platform/FeatureGate/ModeResolverTest.php`
     * uses. `FeatureGateResolver` is container-scoped, so the binding must be
     * swapped before the action resolves it.
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
