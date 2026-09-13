<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\AgreementCertificate\Models\Agreement;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Actions\ApplyPaidEffects;
use App\Domain\OrderWorkflow\Actions\GrantOrderPaymentOpening;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Actions\SubmitBookingDraft;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\PaidTrigger;
use App\Domain\OrderWorkflow\PaidTriggerSource;
use App\Domain\PreNeed\Actions\AcceptPreNeedAgreement;
use App\Domain\PreNeed\Actions\ProposePreNeedPackage;
use App\Domain\PreNeed\Actions\QuotePreNeed;
use App\Domain\PreNeed\Actions\SchedulePreNeedPayments;
use App\Domain\PreNeed\Models\PreNeedCase;
use App\Domain\PreNeed\Models\PreNeedInterest;
use App\Domain\PreNeed\Models\PreNeedPaymentScheduleItem;
use App\Domain\PreNeed\PreNeedAuditActions;
use App\Domain\PreNeed\PreNeedCaseStatus;
use App\Domain\PreNeed\PreNeedInstallmentState;
use App\Domain\Quotation\Models\Quote;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Filament\Admin\Resources\PreNeedCases\Actions\PreNeedCaseActions;
use App\Filament\Admin\Resources\PreNeedCases\Pages\ViewPreNeedCase;
use App\Filament\Admin\Resources\PreNeedCases\PreNeedCaseResource;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\PaymentProviders;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Task 4 of `docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md`
 * — the `PreNeedCases` admin resource, driven the P1 way: the access matrix
 * through the shared master-data authorizer, the header money/contract
 * steps through the action factories (`isAuthorized()` + `->data()->call()`),
 * and the honest gate-closed denial / notification surface through the
 * Livewire view page.
 *
 * The brief's Step-1 list plus the two ledgered Task-4 requirements:
 *
 * - access matrix (guest / bare customer / vendor fail closed; the four
 *   back-office roles pass);
 * - the per-step role split (contract steps: admin + restricted_admin;
 *   money steps: admin + finance);
 * - proposal action -> proposal state (audited);
 * - the G-LEGAL-01 closed-gate denial surfaces as the honest 'Belum dapat
 *   diaktifkan' notification with NO state change and the gate-denial audit;
 * - the schedule action creates the installments;
 * - settlement via the verified-payment path (order walked to DIBAYAR);
 * - the ⚠️ ledgered call-site: `AcceptPreNeedAgreement` receives the CASE's
 *   OWN quote id, and the Lane-1 agreement row binds the same quote (AC2);
 * - the per-installment payment link is FAIL-CLOSED for a PARTIAL amount: a
 *   guarded opening request for an installment amount smaller than the quote
 *   total never creates a `payment_sessions` row (the approved guard's
 *   `amount == quote total` + no-partial-payment contract).
 *
 * (H-2, 13 Sep 2026) That bullet used to read as though the amount rule were
 * the only thing refusing this action, and the older fail-closed test's name
 * says the same. Measured while fixing H-2: with the amount made correct, the
 * opening is STILL denied — by `confirmation_valid_or_reservation_active`,
 * `quote_accepted_and_unexpired` and `authorized_opening`, and
 * `amount_matches_quote_total` is not among the reasons. Those three are
 * operator-owned, not closed: once the operator processes the order the way
 * `TransitionOrderAction` does, a session really does open. Both facts now
 * have their own test, because reading the old one as proof that this path
 * could never open a session is exactly the mistake H-2's first pass made.
 */
final class PreNeedCaseResourceTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guests_and_bare_users_are_denied(): void
    {
        $this->assertFalse(PreNeedCaseResource::canAccess());

        $this->actingAs(User::factory()->create());
        $this->assertFalse(PreNeedCaseResource::canAccess());
    }

    public function test_the_four_back_office_roles_can_access(): void
    {
        foreach ([
            ActorRole::ADMIN,
            ActorRole::RESTRICTED_ADMIN,
            ActorRole::OPERATOR,
            ActorRole::FINANCE,
        ] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(PreNeedCaseResource::canAccess(), "role {$role} should access the case resource");

            $this->forgetResolvedActorContext();
        }
    }

    public function test_vendor_role_cannot_access(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::VENDOR);
        $this->actingAs($user);

        $this->assertFalse(PreNeedCaseResource::canAccess());
    }

    public function test_operator_cannot_authorize_a_contract_step(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $case = $this->caseAtInterest();

        foreach ([
            PreNeedCaseActions::propose($case),
            PreNeedCaseActions::reserve($case),
            PreNeedCaseActions::quote($case),
            PreNeedCaseActions::acceptAgreement($case),
        ] as $action) {
            $this->assertFalse($action->isAuthorized(), "operator must not authorize contract step [{$action->getName()}]");
        }
    }

    public function test_admin_can_authorize_a_contract_step(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $this->assertTrue(PreNeedCaseActions::propose($this->caseAtInterest())->isAuthorized());
    }

    public function test_operator_cannot_authorize_a_money_step(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $case = $this->caseAtInterest();

        $this->assertFalse(PreNeedCaseActions::schedule($case)->isAuthorized());
        $this->assertFalse(PreNeedCaseActions::settle($case)->isAuthorized());
    }

    public function test_finance_can_authorize_money_steps(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);

        $case = $this->caseAtInterest();

        $this->assertTrue(PreNeedCaseActions::schedule($case)->isAuthorized());
        $this->assertTrue(PreNeedCaseActions::settle($case)->isAuthorized());
    }

    public function test_gate_open_propose_via_the_page_transitions_the_case_to_proposal(): void
    {
        $this->bindGateRegistryWith(['G-LEGAL-01' => true]);

        $admin = User::factory()->create();
        $this->grantRoleTo($admin, ActorRole::ADMIN);
        $this->actingAs($admin);

        $case = $this->caseAtInterest();
        $cemetery = $this->cemetery();

        Livewire::test(ViewPreNeedCase::class, ['record' => $case->getKey()])
            ->callAction('propose', data: [
                'cemetery_id' => $cemetery->getKey(),
                'cemetery_package_id' => '',
            ])
            ->assertNotified('Proposal tersimpan.');

        $fresh = $case->fresh();
        $this->assertSame(PreNeedCaseStatus::PROPOSAL->value, $fresh->status);
        $this->assertSame($cemetery->getKey(), $fresh->cemetery_id);
        $this->assertNull($fresh->cemetery_package_id);
        $this->assertDatabaseHas('audit_events', [
            'action' => PreNeedAuditActions::PRENEED_PROPOSED,
            'subject_type' => 'pre_need_case',
            'subject_id' => $case->getKey(),
            'outcome' => 'allowed',
        ]);
    }

    public function test_gate_closed_propose_surfaces_the_honest_notification_and_changes_no_state(): void
    {
        $this->bindGateRegistryWith(['G-LEGAL-01' => false]);

        $admin = User::factory()->create();
        $this->grantRoleTo($admin, ActorRole::ADMIN);
        $this->actingAs($admin);

        $case = $this->caseAtInterest();
        $cemetery = $this->cemetery();

        Livewire::test(ViewPreNeedCase::class, ['record' => $case->getKey()])
            ->callAction('propose', data: [
                'cemetery_id' => $cemetery->getKey(),
                'cemetery_package_id' => '',
            ])
            ->assertNotified('Belum dapat diaktifkan');

        $fresh = $case->fresh();
        $this->assertSame(PreNeedCaseStatus::INTEREST->value, $fresh->status);
        $this->assertNull($fresh->cemetery_id);
        $this->assertNull($fresh->cemetery_package_id);
        $this->assertSame(0, AuditEvent::query()->where('action', PreNeedAuditActions::PRENEED_PROPOSED)->count());
        $this->assertDatabaseHas('audit_events', [
            'action' => PreNeedAuditActions::PRENEED_GATE_DENIED,
            'subject_type' => 'pre_need_gate',
            'subject_id' => 'G-LEGAL-01',
            'outcome' => 'denied',
        ]);
    }

    public function test_accept_agreement_binds_the_cases_own_quote_and_the_same_lane_1_agreement(): void
    {
        $this->bindGateRegistryWith(['G-LEGAL-01' => true]);

        $admin = User::factory()->create();
        $this->grantRoleTo($admin, ActorRole::ADMIN);
        $this->actingAs($admin);

        $case = $this->proposalCase();
        $this->driveQuote($case);

        $quote = Quote::query()->findOrFail($case->fresh()->quote_id);
        $this->assertSame($quote->getKey(), $case->fresh()->quote_id);

        $fields = [
            'price_guarantee' => 'Harga final saat penetapan makam.',
            'cancellation_refund' => 'Pengembalian sesuai syarat kontrak.',
            'transferability' => 'Dapat dialihkan sekali.',
            'term' => '5 tahun sejak aktivasi.',
            'included_services' => 'Penggalian, dokumen, pemakaman.',
            'responsible_entity' => 'Pengelola TPU.',
        ];

        Livewire::test(ViewPreNeedCase::class, ['record' => $case->getKey()])
            ->callAction('accept_agreement', data: $fields)
            ->assertNotified('Kesepakatan diikat.');

        $fresh = $case->fresh();
        $this->assertSame(PreNeedCaseStatus::AGREED->value, $fresh->status);
        $this->assertNotNull($fresh->agreement_id);
        $this->assertSame($quote->getKey(), $fresh->accepted_quote_id);

        $agreement = Agreement::query()
            ->where('reference', $fresh->agreement_id)
            ->firstOrFail();
        $this->assertSame($quote->getKey(), $agreement->accepted_quote_id);

        $this->assertDatabaseHas('audit_events', [
            'action' => PreNeedAuditActions::PRENEED_AGREEMENT_ACCEPTED,
            'subject_type' => 'pre_need_case',
            'subject_id' => $case->getKey(),
            'outcome' => 'allowed',
        ]);
    }

    public function test_gate_closed_accept_agreement_leaves_zero_agreements_zero_events_and_one_denial(): void
    {
        // The production DB-backed source, so the gate can flip mid-request:
        // setup needs it OPEN (propose + quote are gated actions), the
        // attempt needs it CLOSED. The in-memory stub cannot change after
        // the request-scoped resolver first resolves (MemorialQrTest's
        // gate-flip pattern).
        FeatureGate::query()->where('gate_id', 'G-LEGAL-01')->update(['state' => 'open']);
        app(FeatureGateResolver::class)->forget();

        $admin = User::factory()->create();
        $this->grantRoleTo($admin, ActorRole::ADMIN);
        $this->actingAs($admin);

        $case = $this->proposalCase();
        $this->driveQuote($case);

        FeatureGate::query()->where('gate_id', 'G-LEGAL-01')->update(['state' => 'closed']);
        app(FeatureGateResolver::class)->forget();

        $fields = [
            'price_guarantee' => 'Harga final saat penetapan makam.',
            'cancellation_refund' => 'Pengembalian sesuai syarat kontrak.',
            'transferability' => 'Dapat dialihkan sekali.',
            'term' => '5 tahun sejak aktivasi.',
            'included_services' => 'Penggalian, dokumen, pemakaman.',
            'responsible_entity' => 'Pengelola TPU.',
        ];

        Livewire::test(ViewPreNeedCase::class, ['record' => $case->getKey()])
            ->callAction('accept_agreement', data: $fields)
            ->assertNotified('Belum dapat diaktifkan');

        // The gate-first composition: no Lane-1 write, no case write, no
        // outbox event — a closed gate cannot create OR accept a legal
        // binding (whole-branch review finding).
        $fresh = $case->fresh();
        $this->assertSame(PreNeedCaseStatus::QUOTED->value, $fresh->status);
        $this->assertNull($fresh->agreement_id);
        $this->assertNull($fresh->accepted_by_ref);
        $this->assertNull($fresh->accepted_quote_id);

        $this->assertSame(0, Agreement::query()->count());
        $this->assertSame(0, OutboxEvent::query()->where('event_name', 'agreement.accepted.v1')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', PreNeedAuditActions::PRENEED_GATE_DENIED)->count());
    }

    public function test_schedule_action_creates_the_installments(): void
    {
        $this->bindGateRegistryWith(['G-LEGAL-01' => true]);

        $admin = User::factory()->create();
        $this->grantRoleTo($admin, ActorRole::ADMIN);
        $this->actingAs($admin);
        $this->seedActorSession($admin, CarbonImmutable::now());

        $case = $this->agreedCase();

        // The P1 factory shape (same as `RenewalOrderResourceTest`): the
        // action's `->action()` closure runs the Domain Action, which does
        // the real write — the page `callAction` is the render seam and is
        // covered by the contract-step tests above.
        PreNeedCaseActions::schedule($case->fresh())->data([
            'installments' => [
                ['amount_minor' => 30_000_000, 'due_date' => '2026-09-01'],
                ['amount_minor' => 30_000_000, 'due_date' => '2026-12-01'],
                ['amount_minor' => 30_000_000, 'due_date' => '2027-03-01'],
            ],
        ])->call();

        $this->assertSame(PreNeedCaseStatus::SCHEDULED->value, $case->fresh()->status);

        $rows = PreNeedPaymentScheduleItem::query()
            ->where('pre_need_case_id', $case->getKey())
            ->orderBy('installment_number')
            ->get();

        $this->assertSame(3, $rows->count());
        $this->assertSame([1, 2, 3], $rows->pluck('installment_number')->all());
        $this->assertSame([30_000_000, 30_000_000, 30_000_000], $rows->pluck('amount_minor')->all());
        $this->assertSame(
            [PreNeedInstallmentState::PENDING->value, PreNeedInstallmentState::PENDING->value, PreNeedInstallmentState::PENDING->value],
            $rows->pluck('state')->all(),
        );
        $this->assertNull($rows[0]->payment_session_id);
        $this->assertDatabaseHas('audit_events', [
            'action' => PreNeedAuditActions::PRENEED_SCHEDULED,
            'subject_type' => 'pre_need_case',
            'subject_id' => $case->getKey(),
            'outcome' => 'allowed',
        ]);
    }

    public function test_settlement_via_the_verified_payment_path(): void
    {
        $this->bindGateRegistryWith(['G-LEGAL-01' => true]);

        $admin = User::factory()->create();
        $this->grantRoleTo($admin, ActorRole::ADMIN);
        $this->actingAs($admin);
        $this->seedActorSession($admin, CarbonImmutable::now());

        $case = $this->scheduledCase();
        $order = $case->order();
        $quote = Quote::query()->findOrFail($case->quote_id);

        $this->walkOrderTo($order, OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN);
        $quote->accept(CarbonImmutable::now(), 'actor:admin-1');

        $paid = app(ApplyPaidEffects::class)($order->fresh(), new PaidTrigger(
            source: PaidTriggerSource::ManualVerification,
            sourceId: 'pv_resource_settle_1',
            businessKey: "manual_paid:{$order->reference}",
            amount: $quote->totalMinor(),
            currency: $quote->currency,
            occurredAt: CarbonImmutable::now(),
            actorRef: (string) $admin->id,
            actorRole: ActorRole::ADMIN,
        ));

        $this->assertSame(OrderStatus::DIBAYAR->value, $paid->status);

        PreNeedCaseActions::settle($case->fresh())
            ->data(['paid_source_ref' => 'pv_resource_settle_1'])
            ->call();

        $settled = $case->fresh();
        $this->assertSame(PreNeedCaseStatus::SETTLED->value, $settled->status);
        $this->assertSame('pv_resource_settle_1', $settled->settled_paid_source_ref);
        $this->assertDatabaseHas('audit_events', [
            'action' => PreNeedAuditActions::PRENEED_SETTLED,
            'subject_type' => 'pre_need_case',
            'subject_id' => $case->getKey(),
            'outcome' => 'allowed',
        ]);
    }

    public function test_a_per_installment_payment_link_never_opens_a_session_for_an_installment_amount(): void
    {
        $this->bindGateRegistryWith(['G-LEGAL-01' => true, 'G-PAY-01' => true]);

        $admin = User::factory()->create();
        $this->grantRoleTo($admin, ActorRole::ADMIN);
        $this->actingAs($admin);
        $this->seedActorSession($admin, CarbonImmutable::now());

        $this->configurePaymentMerchant();
        $this->fakeProviderSuccess();

        $case = $this->scheduledCase();
        $installment = PreNeedPaymentScheduleItem::query()
            ->where('pre_need_case_id', $case->getKey())
            ->firstOrFail();

        Livewire::test(ViewPreNeedCase::class, ['record' => $case->getKey()])
            ->callAction('payment_link_'.$installment->getKey())
            ->assertNotified('Gagal membuat tautan pembayaran');

        $this->assertSame(0, PaymentSession::query()->count());
        $this->assertNull($installment->fresh()->payment_session_id);
        $this->assertSame(1, PaymentIntent::query()->count());
    }

    /**
     * H-2 review, CRITICAL-1 — the end-to-end proof that a pre-need payment
     * link links its session to the ORDER, not only to the instalment.
     *
     * This is the second surface that opens a real booking session against a
     * real `orders` row. Without the link, the hourly
     * `orders:expire-stale-quotes` sweep cannot see the open checkout, and an
     * instalment plan runs for months while a quote lasts 30 days — so the
     * sweep expires the order and `ReleasePlotReservation` returns the grave
     * to `AVAILABLE` while the customer is on the payment page.
     *
     * Every step here uses machinery that already exists in this repository,
     * and each one satisfies a named guard condition:
     *
     *   - `walkOrderTo(DISETUJUI_PEMESAN)` — the same helper the settlement
     *     test uses.
     *   - `$quote->accept(...)` — condition 3, exactly as
     *     `test_settlement_via_the_verified_payment_path` does it.
     *   - `GrantOrderPaymentOpening` for the LAST step rather than
     *     `RecordOrderStatusChange` — this is what production does
     *     (`TransitionOrderAction`), and it is what writes condition 4's
     *     order-scoped grant while moving the order to
     *     `MENUNGGU_PEMBAYARAN`, satisfying condition 2 as well.
     *   - a single instalment equal to the quote total — condition 5, which
     *     is the one condition a genuine multi-instalment amount fails (see
     *     the fail-closed test above).
     *
     * Nothing here is speculative scaffolding: the pre-need lane REQUIRES
     * this journey, because `SettlePreNeed::apply()` refuses unless the order
     * reached `DIBAYAR`, which is only reachable through these statuses.
     */
    public function test_a_per_installment_payment_link_links_its_session_to_the_order(): void
    {
        $this->bindGateRegistryWith(['G-LEGAL-01' => true, 'G-PAY-01' => true]);

        $admin = User::factory()->create();
        $this->grantRoleTo($admin, ActorRole::ADMIN);
        $this->actingAs($admin);
        $this->seedActorSession($admin, CarbonImmutable::now());

        $this->configurePaymentMerchant();
        $this->fakeProviderSuccess();

        $case = $this->agreedCase();
        $quote = Quote::query()->findOrFail($case->fresh()->quote_id);

        // Condition 5: one instalment for the whole quote total.
        app(SchedulePreNeedPayments::class)(
            $case->fresh(),
            [['amount_minor' => $quote->totalMinor()->toMinorInt(), 'due_date' => '2026-09-01']],
            'actor:admin-1',
            'admin',
        );

        $case = $case->fresh();
        $order = $case->order();

        // Condition 3.
        $quote->accept(CarbonImmutable::now(), 'actor:admin-1');

        // Conditions 2 and 4: the operator processes the order, taking the
        // final step through the grant-writing Action production uses.
        $this->walkOrderTo($order, OrderStatus::DISETUJUI_PEMESAN);
        app(GrantOrderPaymentOpening::class)(
            $order->fresh(),
            $admin->id,
            'actor:admin-1',
            'admin',
        );

        $installment = PreNeedPaymentScheduleItem::query()
            ->where('pre_need_case_id', $case->getKey())
            ->firstOrFail();

        Livewire::test(ViewPreNeedCase::class, ['record' => $case->getKey()])
            ->callAction('payment_link_'.$installment->getKey());

        $session = PaymentSession::query()->sole();

        // Pre-existing behaviour, unchanged.
        $this->assertSame($session->getKey(), $installment->fresh()->payment_session_id);

        // The H-2 fix: the ORDER knows too, so the sweep can protect it.
        $this->assertSame(
            $session->getKey(),
            $order->fresh()->payment_session_id,
            'the order must carry the session link or the expiry sweep cannot protect it',
        );
    }

    /**
     * The fail-closed companion to the test above: an order the operator has
     * NOT processed is refused, which is the guard working correctly.
     *
     * This deliberately removes condition 5 from the picture — the instalment
     * is the full quote total — so the denial can only come from the
     * operator-owned conditions, and the assertion names all three. It is
     * here because the older fail-closed test above attributes its refusal to
     * the amount rule, and that attribution is wrong: with the amount made
     * correct, the opening is still denied, and `amount_matches_quote_total`
     * is not among the reasons.
     *
     * What this does NOT show — and an earlier revision of this file wrongly
     * claimed it did — is that the path is unreachable. It is reachable; the
     * test above reaches it. This pins the BEFORE state of the operator
     * journey, not the absence of one.
     */
    public function test_a_per_installment_payment_link_is_refused_before_the_operator_processes_the_order(): void
    {
        $this->bindGateRegistryWith(['G-LEGAL-01' => true, 'G-PAY-01' => true]);

        $admin = User::factory()->create();
        $this->grantRoleTo($admin, ActorRole::ADMIN);
        $this->actingAs($admin);
        $this->seedActorSession($admin, CarbonImmutable::now());

        $this->configurePaymentMerchant();
        $this->fakeProviderSuccess();

        $case = $this->agreedCase();
        $quote = Quote::query()->findOrFail($case->fresh()->quote_id);

        // The full quote total, so the amount condition CANNOT be the cause.
        app(SchedulePreNeedPayments::class)(
            $case->fresh(),
            [['amount_minor' => $quote->totalMinor()->toMinorInt(), 'due_date' => '2026-09-01']],
            'actor:admin-1',
            'admin',
        );

        $case = $case->fresh();
        $installment = PreNeedPaymentScheduleItem::query()
            ->where('pre_need_case_id', $case->getKey())
            ->firstOrFail();

        Livewire::test(ViewPreNeedCase::class, ['record' => $case->getKey()])
            ->callAction('payment_link_'.$installment->getKey())
            ->assertNotified('Gagal membuat tautan pembayaran');

        $this->assertSame(0, PaymentSession::query()->count());
        $this->assertNull($case->order()?->fresh()->payment_session_id);

        $intent = PaymentIntent::query()->latest('evaluated_at')->firstOrFail();

        $this->assertSame('denied', $intent->decision);
        $this->assertSame(
            [
                'confirmation_valid_or_reservation_active',
                'quote_accepted_and_unexpired',
                'authorized_opening',
            ],
            $intent->denied_conditions,
            'the amount condition must NOT be among these — if it is, the fixture stopped testing what it claims',
        );
    }

    // ---------------------------------------------------------------------
    // Fixtures.
    // ---------------------------------------------------------------------

    private function caseAtInterest(): PreNeedCase
    {
        $draft = $this->draft(BookingServiceType::PRE_NEED, [
            ['code' => ServiceCode::DOCUMENT_PROCESSING, 'quantity' => 1],
            ['code' => ServiceCode::GRAVE_DIGGING, 'quantity' => 1],
        ]);

        $order = app(SubmitBookingDraft::class)($draft, 'idem-resource-'.Str::random(6));
        $interest = PreNeedInterest::query()->findOrFail($order->pre_need_case_id);

        return PreNeedCase::query()->create([
            'pre_need_interest_id' => $interest->getKey(),
            'status' => PreNeedCaseStatus::INTEREST->value,
        ]);
    }

    private function proposalCase(): PreNeedCase
    {
        $case = $this->caseAtInterest();

        app(ProposePreNeedPackage::class)($case, $this->cemetery(), null, 'actor:admin-1', 'admin');

        return $case->fresh();
    }

    private function driveQuote(PreNeedCase $case): void
    {
        app(QuotePreNeed::class)($case->fresh(), CarbonImmutable::now()->addDays(7), 'actor:admin-1', 'admin');
    }

    private function agreedCase(): PreNeedCase
    {
        $case = $this->proposalCase();
        $this->driveQuote($case);
        $quote = Quote::query()->findOrFail($case->fresh()->quote_id);

        app(AcceptPreNeedAgreement::class)(
            $case->fresh(),
            'agmt-resource-1',
            'actor:admin-1',
            'admin',
            quoteId: $quote->getKey(),
        );

        return $case->fresh();
    }

    private function scheduledCase(): PreNeedCase
    {
        $case = $this->agreedCase();

        app(SchedulePreNeedPayments::class)(
            $case->fresh(),
            [['amount_minor' => 30_000_000, 'due_date' => '2026-09-01']],
            'actor:admin-1',
            'admin',
        );

        return $case->fresh();
    }

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

    private function configurePaymentMerchant(): void
    {
        config([
            'payment.merchant_ref' => 'mk-merchant-dev',
            'payment.badan_usaha_ref' => 'badan-usaha-dev',
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.api_key' => 'test-key',
        ]);
    }

    private function fakeProviderSuccess(): void
    {
        Http::fake([
            'api-pay-sandbox.sumopod.com/api/v1/payments' => Http::response([
                'payment_id' => 'uuid-1',
                'order_id' => 'MK-ORD-1',
                'amount' => 1_500_000,
                'fee' => 38_800,
                'net_amount' => 1_461_200,
                'payment_link_url' => 'https://checkout.sumopod.com/x',
                'status' => 'pending',
            ], 201),
        ]);
    }

    private function walkOrderTo(Order $order, OrderStatus $target): void
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
    }

    private function seedActorSession(User $user, CarbonImmutable $lastAuthenticatedAt): ActorSession
    {
        return ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => $lastAuthenticatedAt,
        ]);
    }

    /**
     * @param  array<string, bool>  $states  gate id => open
     */
    private function bindGateRegistryWith(array $states): void
    {
        $snapshot = [];

        foreach ($states as $gateId => $open) {
            $snapshot[$gateId] = GateState::fromRecord($gateId, open: $open);
        }

        $this->app->instance(GateRegistrySource::class, new class($snapshot) implements GateRegistrySource
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
