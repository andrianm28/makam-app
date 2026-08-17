<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PreNeed;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\FuneralCase\FuneralCaseStatus;
use App\Domain\FuneralCase\Models\FuneralCase;
use App\Domain\OrderWorkflow\Actions\ApplyPaidEffects;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Actions\SubmitBookingDraft;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\PaidTrigger;
use App\Domain\OrderWorkflow\PaidTriggerSource;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PreNeed\Actions\AcceptPreNeedAgreement;
use App\Domain\PreNeed\Actions\ActivatePreNeed;
use App\Domain\PreNeed\Actions\ProposePreNeedPackage;
use App\Domain\PreNeed\Actions\QuotePreNeed;
use App\Domain\PreNeed\Actions\ReservePreNeedPlot;
use App\Domain\PreNeed\Actions\SchedulePreNeedPayments;
use App\Domain\PreNeed\Actions\SettlePreNeed;
use App\Domain\PreNeed\Exceptions\IllegalPreNeedCaseTransitionException;
use App\Domain\PreNeed\Models\PreNeedCase;
use App\Domain\PreNeed\Models\PreNeedInterest;
use App\Domain\PreNeed\Models\PreNeedPaymentScheduleItem;
use App\Domain\PreNeed\PreNeedAuditActions;
use App\Domain\PreNeed\PreNeedCaseStatus;
use App\Domain\PreNeed\PreNeedInstallmentState;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\Modes\PreNeedMode;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\OutboxClassification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 3 of `docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md`
 * — the paid Pre-Need flow's happy path, with G-LEGAL-01 test-opened
 * through the same in-memory `GateRegistrySource` stub `ModeResolverTest`
 * uses.
 *
 * The flow driven here is the plan's full chain: interest (submit-time
 * order) -> proposal -> optional reservation -> quote (lines from the
 * draft's selected services, via the P0 seam) -> agreement acceptance
 * (AC2 binding: the exact quote/agreement versions) -> payment schedule
 * (idempotent on re-run) -> settlement (only after the order is DIBAYAR
 * through the verified-payment fixture) -> activation (AC8: a NEW
 * At-Need FuneralCase, the original case's agreement/quote/reservation
 * links untouched).
 *
 * The reservation-optional branch (the ledgered Task-4 requirement) is
 * pinned in `test_the_reservation_optional_branch_quotes_a_proposal_without_a_reservation`:
 * proposal -> quote directly, the reservation step skipped entirely.
 */
final class PreNeedPaidFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_full_paid_flow_from_interest_to_activation(): void
    {
        $this->bindGateRegistryWith('G-LEGAL-01', open: true);

        // -----------------------------------------------------------------
        // 1. Interest + the submit-time PRE_NEED order.
        // -----------------------------------------------------------------
        $draft = $this->draft(BookingServiceType::PRE_NEED, services: [
            ['code' => ServiceCode::DOCUMENT_PROCESSING, 'quantity' => 1],
            ['code' => ServiceCode::GRAVE_DIGGING, 'quantity' => 1],
        ]);

        $order = app(SubmitBookingDraft::class)($draft, 'idem-paid-flow-1');

        self::assertNotNull($order->pre_need_case_id);
        $interest = PreNeedInterest::query()->findOrFail($order->pre_need_case_id);
        self::assertSame(PreNeedMode::PaymentEnabled->value, $interest->gate_mode);

        // The case is created by the admin surface (Task 4); the domain
        // action receives it linked to the interest.
        $case = PreNeedCase::query()->create([
            'pre_need_interest_id' => $interest->getKey(),
            'status' => PreNeedCaseStatus::INTEREST->value,
        ]);

        // -----------------------------------------------------------------
        // 2. Proposal (cemetery + optional package refs).
        // -----------------------------------------------------------------
        $cemetery = $this->cemetery();
        $package = CemeteryPackage::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'name' => 'Paket Pre-Need Uji',
            'availability_status' => CemeteryPackageAvailabilityStatus::AVAILABLE,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $proposed = app(ProposePreNeedPackage::class)($case, $cemetery, $package, 'actor:admin-1', 'admin');

        self::assertSame(PreNeedCaseStatus::PROPOSAL->value, $proposed->status);
        self::assertSame($cemetery->getKey(), $proposed->cemetery_id);
        self::assertSame($package->getKey(), $proposed->cemetery_package_id);
        self::assertDatabaseHas('audit_events', [
            'action' => PreNeedAuditActions::PRENEED_PROPOSED,
            'subject_type' => 'pre_need_case',
            'subject_id' => $case->getKey(),
            'outcome' => 'allowed',
        ]);

        // -----------------------------------------------------------------
        // 3. Optional reservation — `ReservePlot` on the pre-need order.
        // -----------------------------------------------------------------
        $plot = $this->plot($cemetery);

        $reserved = app(ReservePreNeedPlot::class)($proposed, $plot, 'actor:admin-1', 'admin');

        self::assertSame(PreNeedCaseStatus::RESERVED->value, $reserved->status);
        self::assertNotNull($reserved->plot_reservation_id);
        self::assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
        self::assertDatabaseHas('audit_events', [
            'action' => PreNeedAuditActions::PRENEED_RESERVED,
            'outcome' => 'allowed',
        ]);

        // -----------------------------------------------------------------
        // 4. Quote — the P0 seam composes the lines from the draft's
        //    selected services; `IssueQuote` prices them on the pre-need
        //    order.
        // -----------------------------------------------------------------
        $quoted = app(QuotePreNeed::class)($reserved, Carbon::now()->addDays(7), 'actor:admin-1', 'admin');

        self::assertSame(PreNeedCaseStatus::QUOTED->value, $quoted->status);
        self::assertNotNull($quoted->quote_id);

        $quote = Quote::query()->findOrFail($quoted->quote_id);
        self::assertSame($order->getKey(), $quote->order_id);
        self::assertSame(QuoteStatus::ISSUED->value, $quote->status);
        self::assertSame(1, $quote->version_number);
        // 350000.00 (DOCUMENT_PROCESSING) + 550000.00 (GRAVE_DIGGING).
        self::assertSame(90_000_000, $quote->totalMinor()->toMinorInt());
        self::assertSame(2, $quote->lines()->count());
        self::assertDatabaseHas('audit_events', [
            'action' => PreNeedAuditActions::PRENEED_QUOTED,
            'outcome' => 'allowed',
        ]);

        // -----------------------------------------------------------------
        // 5. Agreement acceptance — the case-level AC2 binding: agreement
        //    id + the exact quote version + the actor are recorded. The
        //    `agreement.accepted.v1` event is NOT emitted here: its single
        //    producer is Lane 1's `AcceptAgreement` on the `agreements`
        //    row (the panel composition runs that producer first;
        //    whole-branch review finding) — a direct-domain acceptance
        //    without a Lane-1 row leaves the outbox silent.
        // -----------------------------------------------------------------
        $accepted = app(AcceptPreNeedAgreement::class)(
            $quoted,
            'agmt-preneed-1',
            'actor:admin-1',
            'admin',
            quoteId: $quote->getKey(),
        );

        self::assertSame(PreNeedCaseStatus::AGREED->value, $accepted->status);
        self::assertSame('agmt-preneed-1', $accepted->agreement_id);
        self::assertSame('actor:admin-1', $accepted->accepted_by_ref);
        self::assertSame($quote->getKey(), $accepted->accepted_quote_id);
        self::assertDatabaseHas('audit_events', [
            'action' => PreNeedAuditActions::PRENEED_AGREEMENT_ACCEPTED,
            'outcome' => 'allowed',
        ]);

        self::assertSame(0, OutboxEvent::query()->where('event_name', 'agreement.accepted.v1')->count());

        // -----------------------------------------------------------------
        // 6. Payment schedule — installments denominated in the bound
        //    quote's currency; re-run is idempotent (incumbent, no writes).
        // -----------------------------------------------------------------
        $installments = [
            ['amount_minor' => 30_000_000, 'due_date' => '2026-09-01'],
            ['amount_minor' => 30_000_000, 'due_date' => '2026-12-01'],
            ['amount_minor' => 30_000_000, 'due_date' => '2027-03-01'],
        ];

        $scheduled = app(SchedulePreNeedPayments::class)($accepted, $installments, 'actor:admin-1', 'admin');

        self::assertSame(PreNeedCaseStatus::SCHEDULED->value, $scheduled->status);
        self::assertSame(3, PreNeedPaymentScheduleItem::query()->where('pre_need_case_id', $case->getKey())->count());

        $rows = PreNeedPaymentScheduleItem::query()
            ->where('pre_need_case_id', $case->getKey())
            ->orderBy('installment_number')
            ->get();

        self::assertSame(1, $rows[0]->installment_number);
        self::assertSame(30_000_000, $rows[0]->amount_minor);
        self::assertSame('IDR', $rows[0]->currency);
        self::assertSame('2026-09-01', $rows[0]->due_date->format('Y-m-d'));
        self::assertSame(PreNeedInstallmentState::PENDING->value, $rows[0]->state);
        self::assertNull($rows[0]->payment_session_id);
        self::assertSame(2, $rows[1]->installment_number);
        self::assertSame(3, $rows[2]->installment_number);

        // The idempotent re-run: the same case comes back, no new rows, no
        // second audit record.
        $again = app(SchedulePreNeedPayments::class)($scheduled->fresh(), $installments, 'actor:admin-1', 'admin');

        self::assertSame($case->getKey(), $again->getKey());
        self::assertSame(3, PreNeedPaymentScheduleItem::query()->count());
        self::assertSame(1, AuditEvent::query()->where('action', PreNeedAuditActions::PRENEED_SCHEDULED)->count());

        // -----------------------------------------------------------------
        // 7. Settlement — refused until the pre-need order is actually
        //    DIBAYAR (the manual-fallback discipline: settlement only after
        //    payment evidence is verified), then settled.
        // -----------------------------------------------------------------
        try {
            app(SettlePreNeed::class)($scheduled->fresh(), 'pv_paid_flow_1', 'actor:admin-1', 'admin');
            self::fail('Settlement must be refused while the pre-need order is not DIBAYAR.');
        } catch (IllegalPreNeedCaseTransitionException) {
            // expected — honest refusal
        }

        self::assertSame(PreNeedCaseStatus::SCHEDULED->value, $scheduled->fresh()->status);
        self::assertNull($scheduled->fresh()->settled_paid_source_ref);

        // The verified-payment fixture: walk the order through the real
        // transition graph, accept the issued quote, then apply the paid
        // effects with a manual-verification trigger — the same discipline
        // `MarkOrderPaid` exercises (`ApplyPaidEffects` refuses anything but
        // an accepted, unexpired quote at exactly the quoted amount).
        $this->walkOrderTo($order, OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN);
        $quote->accept(CarbonImmutable::now(), 'actor:admin-1');

        $paid = app(ApplyPaidEffects::class)($order->fresh(), new PaidTrigger(
            source: PaidTriggerSource::ManualVerification,
            sourceId: 'pv_paid_flow_1',
            businessKey: "manual_paid:{$order->reference}",
            amount: $quote->totalMinor(),
            currency: $quote->currency,
            occurredAt: CarbonImmutable::now(),
            actorRef: 'actor:finance-1',
            actorRole: 'finance',
        ));

        self::assertSame(OrderStatus::DIBAYAR->value, $paid->status);

        $settled = app(SettlePreNeed::class)($scheduled->fresh(), 'pv_paid_flow_1', 'actor:admin-1', 'admin');

        self::assertSame(PreNeedCaseStatus::SETTLED->value, $settled->status);
        self::assertSame('pv_paid_flow_1', $settled->settled_paid_source_ref);
        self::assertDatabaseHas('audit_events', [
            'action' => PreNeedAuditActions::PRENEED_SETTLED,
            'outcome' => 'allowed',
        ]);

        // -----------------------------------------------------------------
        // 8. Activation — AC8: a NEW At-Need FuneralCase is opened from the
        //    activation draft, the case moves to `activated`, and the
        //    original contract history (agreement/quote/reservation) is
        //    untouched.
        // -----------------------------------------------------------------
        $activationDraft = $this->draft(BookingServiceType::NEW_GRAVE);

        $activated = app(ActivatePreNeed::class)($settled->fresh(), $activationDraft, 'actor:admin-1', 'admin');

        self::assertSame(PreNeedCaseStatus::ACTIVATED->value, $activated->status);
        self::assertNotNull($activated->activated_funeral_case_id);

        $funeralCase = FuneralCase::query()->findOrFail($activated->activated_funeral_case_id);
        self::assertSame(FuneralCaseStatus::NEW->value, $funeralCase->status);
        self::assertSame($activationDraft->getKey(), $funeralCase->booking_draft_id);

        // AC8: the original case's history is intact.
        $freshCase = $activated->fresh();
        self::assertSame('agmt-preneed-1', $freshCase->agreement_id);
        self::assertSame($quote->getKey(), $freshCase->quote_id);
        self::assertNotNull($freshCase->plot_reservation_id);
        self::assertSame($interest->getKey(), $freshCase->pre_need_interest_id);
        self::assertSame('pv_paid_flow_1', $freshCase->settled_paid_source_ref);

        // The catalogued activation event, references only.
        self::assertDatabaseHas('outbox_events', [
            'event_name' => 'pre_need_case.activated.v1',
            'event_version' => 1,
            'aggregate_type' => 'pre_need_case',
            'aggregate_id' => $case->getKey(),
            'classification' => OutboxClassification::Internal->value,
        ]);
        self::assertDatabaseHas('audit_events', [
            'action' => PreNeedAuditActions::PRENEED_ACTIVATED,
            'outcome' => 'allowed',
        ]);
    }

    public function test_the_reservation_optional_branch_quotes_a_proposal_without_a_reservation(): void
    {
        $this->bindGateRegistryWith('G-LEGAL-01', open: true);

        $draft = $this->draft(BookingServiceType::PRE_NEED, services: [
            ['code' => ServiceCode::DOCUMENT_PROCESSING, 'quantity' => 1],
        ]);

        $order = app(SubmitBookingDraft::class)($draft, 'idem-reservation-optional');
        $interest = PreNeedInterest::query()->findOrFail($order->pre_need_case_id);

        $case = PreNeedCase::query()->create([
            'pre_need_interest_id' => $interest->getKey(),
            'status' => PreNeedCaseStatus::INTEREST->value,
        ]);

        $proposed = app(ProposePreNeedPackage::class)($case, $this->cemetery(), null, 'actor:admin-1', 'admin');

        self::assertSame(PreNeedCaseStatus::PROPOSAL->value, $proposed->status);

        // The reservation-optional branch (the ledgered Task-4 requirement):
        // proposal -> quote directly, the reservation step skipped entirely.
        $quoted = app(QuotePreNeed::class)($proposed, Carbon::now()->addDays(7), 'actor:admin-1', 'admin');

        self::assertSame(PreNeedCaseStatus::QUOTED->value, $quoted->status);
        self::assertNotNull($quoted->quote_id);
        self::assertNull($quoted->plot_reservation_id);
        self::assertDatabaseHas('audit_events', [
            'action' => PreNeedAuditActions::PRENEED_QUOTED,
            'outcome' => 'allowed',
        ]);
    }

    public function test_scheduling_requires_a_bound_quote_to_denominate_the_installments(): void
    {
        $this->bindGateRegistryWith('G-LEGAL-01', open: true);

        $draft = $this->draft(BookingServiceType::PRE_NEED);
        $order = app(SubmitBookingDraft::class)($draft, 'idem-paid-flow-no-quote');
        $interest = PreNeedInterest::query()->findOrFail($order->pre_need_case_id);

        $case = PreNeedCase::query()->create([
            'pre_need_interest_id' => $interest->getKey(),
            'status' => PreNeedCaseStatus::AGREED->value,
        ]);

        try {
            app(SchedulePreNeedPayments::class)(
                $case,
                [['amount_minor' => 30_000_000, 'due_date' => '2026-09-01']],
                'actor:admin-1',
                'admin',
            );
            self::fail('Scheduling without a bound quote must be refused.');
        } catch (IllegalPreNeedCaseTransitionException) {
            // expected — honest refusal
        }

        self::assertSame(0, PreNeedPaymentScheduleItem::query()->count());
        self::assertSame(PreNeedCaseStatus::AGREED->value, $case->fresh()->status);
    }

    public function test_an_illegal_transition_is_refused_without_state_change(): void
    {
        $this->bindGateRegistryWith('G-LEGAL-01', open: true);

        $draft = $this->draft(BookingServiceType::PRE_NEED);
        $order = app(SubmitBookingDraft::class)($draft, 'idem-paid-flow-illegal');
        $interest = PreNeedInterest::query()->findOrFail($order->pre_need_case_id);

        $case = PreNeedCase::query()->create([
            'pre_need_interest_id' => $interest->getKey(),
            'status' => PreNeedCaseStatus::INTEREST->value,
        ]);

        // Settling a case that never went through the chain is an illegal
        // transition — refused before anything is written.
        try {
            app(SettlePreNeed::class)($case, 'pv_illegal_1', 'actor:admin-1', 'admin');
            self::fail('Settlement from `interest` must be refused as an illegal transition.');
        } catch (IllegalPreNeedCaseTransitionException) {
            // expected
        }

        self::assertSame(PreNeedCaseStatus::INTEREST->value, $case->fresh()->status);
        self::assertNull($case->fresh()->settled_paid_source_ref);
        self::assertSame(0, AuditEvent::query()->where('action', PreNeedAuditActions::PRENEED_SETTLED)->count());
    }

    // -----------------------------------------------------------------
    // Fixtures.
    // -----------------------------------------------------------------

    private function draft(string $serviceType, array $services = []): BookingDraft
    {
        return BookingDraft::query()->create([
            'city_code' => LaunchCityCode::JAKARTA,
            'service_type' => $serviceType,
            'selected_services' => $services,
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
     * Walk a real `OrderTransition` path to the status named — the same
     * fixture shape `ApplyPaidEffectsTest` uses, so the paid state is
     * reached through the graph, never by a direct column write.
     */
    private function walkOrderTo(Order $order, OrderStatus $target): Order
    {
        $chain = [
            OrderStatus::DIVERIFIKASI,
            OrderStatus::MENUNGGU_KETERSEDIAAN,
            OrderStatus::PENAWARAN_TERKIRIM,
            OrderStatus::DISETUJUI_PEMESAN,
            OrderStatus::MENUNGGU_PEMBAYARAN,
            OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN,
        ];

        foreach ($chain as $status) {
            app(RecordOrderStatusChange::class)($order, $status, 'actor:admin-1', 'admin');

            if ($status === $target) {
                break;
            }
        }

        return $order;
    }

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
