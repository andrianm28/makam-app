<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\FuneralCase\FuneralCaseStatus;
use App\Domain\FuneralCase\Models\FuneralCase;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Actions\SubmitBookingDraft;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderParty;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PreNeed\Models\PreNeedInterest;
use App\Domain\PreNeed\PreNeedInterestStatus;
use App\Models\User;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\Modes\PreNeedMode;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 3 of `docs/superpowers/plans/2026-08-12-platform-order-orchestration.md`
 * — AC4 (explicit product type and workflow routing) and AC5 (At-Need opens a
 * funeral case; Pre-Need registers interest only while `G-LEGAL-01` is closed).
 *
 * See `App\Domain\OrderWorkflow\Actions\SubmitBookingDraft`'s own doc block for
 * the two design decisions this suite pins: the initial-`MASUK` event mechanism
 * and the database-enforced idempotency key.
 */
final class SubmitBookingDraftTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every table in this repository that can represent money owed, moved, or
     * held. Grep-verified 12 Aug 2026: there is no `invoices` table — the
     * invoice concept is not modelled anywhere in this codebase yet — so this
     * list IS "every payment/invoice table that exists".
     *
     * @var list<string>
     */
    private const array FINANCIAL_TABLES = [
        'payment_intents',
        'payment_sessions',
        'payment_verifications',
        'payment_reversals',
        'journal_batches',
        'journal_entries',
        'vendor_payables',
        'payouts',
    ];

    /**
     * AC5, At-Need arm. The case is a SEPARATE aggregate with its own state
     * machine; the order carries only a reference to it.
     */
    public function test_an_at_need_submission_opens_a_funeral_case_and_links_it_to_the_order(): void
    {
        $draft = $this->draft(BookingServiceType::NEW_GRAVE);

        $order = app(SubmitBookingDraft::class)($draft, 'idem-at-need-1');

        self::assertSame(ProductType::AT_NEED_SERVICE_ORDER->value, $order->product_type);
        self::assertNotNull($order->funeral_case_id);
        self::assertNull($order->pre_need_case_id);

        $case = FuneralCase::query()->findOrFail($order->funeral_case_id);
        self::assertSame(FuneralCaseStatus::NEW->value, $case->status);
        self::assertSame(LaunchCityCode::JAKARTA, $case->service_area);
        self::assertSame($draft->getKey(), $case->booking_draft_id);

        // The case's own catalogued event (`event-catalog.md`:
        // `funeral_case.created.v1`, producer FuneralCase). References only.
        self::assertDatabaseHas('outbox_events', [
            'event_name' => 'funeral_case.created.v1',
            'event_version' => 1,
            'aggregate_type' => 'funeral_case',
            'aggregate_id' => $case->getKey(),
            'classification' => OutboxClassification::Internal->value,
        ]);

        // No Pre-Need interest was created on the At-Need arm.
        self::assertSame(0, PreNeedInterest::query()->count());

        // The ordering party is recorded against the order.
        self::assertSame(1, OrderParty::query()->where('order_id', $order->getKey())->count());
    }

    /**
     * The join between `StartBookingDraft` (sets `booking_drafts.user_id`
     * from the authenticated actor) and this Action (reads it back onto
     * `order_parties.user_id`, the column `Order::forUser()` filters on for
     * `/akun/pesanan`) had no direct test anywhere in this 3-PR series until
     * now — see the final-review fix wave that added this test.
     */
    public function test_an_authenticated_customers_draft_attributes_its_order_party_to_them(): void
    {
        $user = User::factory()->create();
        $draft = $this->draft(BookingServiceType::NEW_GRAVE);
        $draft->forceFill(['user_id' => $user->getKey()])->save();

        $order = app(SubmitBookingDraft::class)($draft, 'idem-user-attribution-1');

        self::assertSame(
            $user->getKey(),
            OrderParty::query()->where('order_id', $order->getKey())->firstOrFail()->user_id,
        );
    }

    /**
     * The customer contact details collected at Step 6
     * (`SaveBookingDraftStep`, `booking_drafts.customer_*`) are the only
     * reference notification recipient resolution has for a guest
     * customer — see `Platform\Notification\Support\
     * OrderPartySubjectSupport`. Before this test, `SubmitBookingDraft`
     * collected these fields on the draft and then wrote every one of them
     * as null onto `order_parties`, so a validated email address a customer
     * had just typed in was discarded at the exact moment it needed to be
     * durable.
     */
    public function test_the_ordering_partys_step_6_contact_details_are_carried_onto_the_order(): void
    {
        $draft = $this->draft(BookingServiceType::NEW_GRAVE);
        $draft->forceFill([
            'customer_full_name' => 'Siti Aminah',
            'customer_mobile' => '081234567890',
            'customer_email' => 'siti.aminah@example.test',
            'customer_address' => 'Jl. Melati No. 12, Jakarta Selatan',
            'customer_relationship' => 'anak',
            'customer_contact_channel' => 'whatsapp',
        ])->save();

        $order = app(SubmitBookingDraft::class)($draft, 'idem-contact-carry-1');

        $party = OrderParty::query()->where('order_id', $order->getKey())->firstOrFail();

        self::assertSame('Siti Aminah', $party->full_name);
        self::assertSame('081234567890', $party->contact_phone);
        self::assertSame('siti.aminah@example.test', $party->contact_email);
        self::assertSame('Jl. Melati No. 12, Jakarta Selatan', $party->address);
        self::assertSame('anak', $party->relationship_to_deceased);
        self::assertSame('whatsapp', $party->preferred_contact_channel);
    }

    /**
     * A draft that never reached Step 6 (a fixture, or a service-catalogue-
     * only submission) has these fields null on the draft already — this
     * asserts the carry-through stays a plain copy, never a fabricated
     * default.
     */
    public function test_a_draft_with_no_step_6_contact_details_yields_a_party_with_none_either(): void
    {
        $draft = $this->draft(BookingServiceType::NEW_GRAVE);

        $order = app(SubmitBookingDraft::class)($draft, 'idem-contact-carry-2');

        $party = OrderParty::query()->where('order_id', $order->getKey())->firstOrFail();

        self::assertNull($party->full_name);
        self::assertNull($party->contact_phone);
        self::assertNull($party->contact_email);
        self::assertNull($party->address);
        self::assertNull($party->relationship_to_deceased);
        self::assertNull($party->preferred_contact_channel);
    }

    /**
     * AC5, Pre-Need arm, and the `G-LEGAL-01` restriction. Absence of a
     * financial obligation is asserted POSITIVELY against every financial
     * table, not inferred from "the code does not call it".
     */
    public function test_a_pre_need_submission_registers_interest_and_creates_no_financial_obligation(): void
    {
        $draft = $this->draft(BookingServiceType::PRE_NEED);

        $order = app(SubmitBookingDraft::class)($draft, 'idem-pre-need-1');

        self::assertSame(ProductType::PRE_NEED_PLOT_PURCHASE->value, $order->product_type);
        self::assertNotNull($order->pre_need_case_id);
        self::assertNull($order->funeral_case_id);

        $interest = PreNeedInterest::query()->findOrFail($order->pre_need_case_id);
        self::assertSame(PreNeedInterestStatus::INTEREST_REGISTERED->value, $interest->status);

        // The gate was read SERVER-SIDE and its resolved mode recorded on the
        // row — see the gate-open counterpart test for the proof that this is
        // a real read rather than a hardcoded constant.
        self::assertSame(PreNeedMode::InterestOnly->value, $interest->gate_mode);

        // No funeral case on the Pre-Need arm.
        self::assertSame(0, FuneralCase::query()->count());

        $this->assertNoFinancialObligationExists();
    }

    /**
     * `G-LEGAL-01` must be enforced in CODE and read server-side, not merely
     * documented. Two things are proven here that the closed-gate test cannot:
     *
     *   1. The mode is genuinely resolved from the gate registry — flipping
     *      `G-LEGAL-01` to open changes the recorded value, so a hardcoded
     *      `PreNeedMode::InterestOnly` would fail this.
     *   2. Even with the gate OPEN, this lane creates no payment object. The
     *      paid Pre-Need path is not built here (`FIN-DEC-08` is `GATED`), and
     *      an opened gate must not cause a guessed financial implementation to
     *      appear (`financial-model.md` §4).
     */
    public function test_the_pre_need_gate_is_read_server_side_and_still_creates_no_financial_obligation_when_open(): void
    {
        $this->bindGateRegistryWith('G-LEGAL-01', open: true);

        $draft = $this->draft(BookingServiceType::PRE_NEED);

        $order = app(SubmitBookingDraft::class)($draft, 'idem-pre-need-gate-open');

        $interest = PreNeedInterest::query()->findOrFail($order->pre_need_case_id);

        self::assertSame(PreNeedMode::PaymentEnabled->value, $interest->gate_mode);
        self::assertSame(PreNeedInterestStatus::INTEREST_REGISTERED->value, $interest->status);

        $this->assertNoFinancialObligationExists();
    }

    /**
     * Design-system §6.6: a duplicate submission renders the SAME
     * confirmation, never a second order.
     *
     * The read-then-write fast path in the Action is a convenience, not the
     * guarantee — `orders.idempotency_key` carries a real UNIQUE index and the
     * losing insert is translated back into the incumbent order. Both halves
     * are asserted: the same id comes back, and exactly one order exists.
     */
    public function test_resubmitting_with_the_same_idempotency_key_returns_the_same_order(): void
    {
        $draft = $this->draft(BookingServiceType::NEW_GRAVE);

        $first = app(SubmitBookingDraft::class)($draft, 'idem-duplicate-1');
        $second = app(SubmitBookingDraft::class)($draft, 'idem-duplicate-1');

        self::assertSame($first->getKey(), $second->getKey());
        self::assertSame(1, Order::query()->count());

        // The routing side effects must not be duplicated either — a second
        // funeral case for one order is the same defect wearing a hat.
        self::assertSame(1, FuneralCase::query()->count());
        self::assertSame(1, OrderParty::query()->count());
        self::assertSame(1, OrderStatusEvent::query()->count());

        // A DIFFERENT key on the same draft is a different order, so the
        // dedupe is on the key and not on "any second call returns the first".
        $third = app(SubmitBookingDraft::class)($draft, 'idem-duplicate-2');
        self::assertNotSame($first->getKey(), $third->getKey());
    }

    /**
     * The order starts at `MASUK` — and the corresponding `order_status_events`
     * row exists, with `from_status` null. Task 2's whole point is that
     * `orders.status` and the event history never diverge; an order with a
     * status and no event row is a defect, so the column value alone is not
     * what is asserted here.
     */
    public function test_a_submitted_order_starts_at_masuk_with_its_initial_status_event(): void
    {
        $draft = $this->draft(BookingServiceType::NEW_GRAVE);

        $order = app(SubmitBookingDraft::class)($draft, 'idem-masuk-1');

        self::assertSame(OrderStatus::MASUK, $order->status());
        self::assertSame(OrderStatus::MASUK->value, $order->fresh()->status);

        $event = OrderStatusEvent::query()->where('order_id', $order->getKey())->sole();
        self::assertNull($event->from_status);
        self::assertSame(OrderStatus::MASUK->value, $event->to_status);

        // The same audit + outbox pairing every other transition gets. Without
        // these the initial event could be written by a quiet second door that
        // skips both.
        self::assertDatabaseHas('audit_events', [
            'action' => 'ORDER_STATUS_CHANGED',
            'subject_type' => 'order',
            'subject_id' => $order->getKey(),
            'outcome' => 'allowed',
        ]);
        self::assertDatabaseHas('outbox_events', [
            'event_name' => 'order.status_changed.v1',
            'aggregate_type' => 'order',
            'aggregate_id' => $order->getKey(),
            'idempotency_key' => "order_status_event:{$event->getKey()}",
        ]);
    }

    /**
     * `funeral-case-model.md`: the case statuses "are operational statuses and
     * do not replace commercial order/payment statuses". Two vocabularies, two
     * columns, independently readable — moving one must not move or corrupt
     * the other in either direction.
     */
    public function test_case_status_and_order_status_are_independently_readable(): void
    {
        $draft = $this->draft(BookingServiceType::URGENT_TODAY);

        $order = app(SubmitBookingDraft::class)($draft, 'idem-independence-1');
        $case = FuneralCase::query()->findOrFail($order->funeral_case_id);

        // Move the CASE forward. The order must not budge.
        $case->advanceTo(FuneralCaseStatus::TRIAGED);

        self::assertSame(FuneralCaseStatus::TRIAGED->value, $case->fresh()->status);
        self::assertSame(OrderStatus::MASUK->value, $order->fresh()->status);

        // Move the ORDER forward. The case must not budge.
        app(RecordOrderStatusChange::class)($order, OrderStatus::DIVERIFIKASI, 'actor:admin-1', 'admin');

        self::assertSame(OrderStatus::DIVERIFIKASI->value, $order->fresh()->status);
        self::assertSame(FuneralCaseStatus::TRIAGED->value, $case->fresh()->status);

        // The two vocabularies never overlap: no case status is an order
        // status and no order status is a case status, so neither column can
        // be read with the other's enum.
        $orderValues = array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::cases());
        $caseValues = array_map(static fn (FuneralCaseStatus $s): string => $s->value, FuneralCaseStatus::cases());
        self::assertSame([], array_intersect($orderValues, $caseValues));

        // The case's own status column is not a copy of the order's.
        self::assertNotSame($order->fresh()->status, $case->fresh()->status);
    }

    private function assertNoFinancialObligationExists(): void
    {
        foreach (self::FINANCIAL_TABLES as $table) {
            self::assertSame(
                0,
                DB::table($table)->count(),
                "G-LEGAL-01 is closed: no financial obligation may exist, but [{$table}] has rows.",
            );
        }
    }

    private function draft(string $serviceType): BookingDraft
    {
        return BookingDraft::query()->create([
            'city_code' => LaunchCityCode::JAKARTA,
            'service_type' => $serviceType,
        ]);
    }

    /**
     * Replaces the database-backed gate registry with an in-memory source, the
     * same stub shape `tests/Unit/Platform/FeatureGate/ModeResolverTest.php`
     * uses. `FeatureGateResolver` is container-scoped, so the binding must be
     * swapped before the Action resolves it.
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
