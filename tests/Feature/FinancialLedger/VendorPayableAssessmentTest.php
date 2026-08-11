<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\Audit\AuditSource;
use App\Platform\FinancialLedger\Actions\VendorPayable;
use App\Platform\FinancialLedger\Contracts\Journal as JournalContract;
use App\Platform\FinancialLedger\Exceptions\InvalidVendorPayableException;
use App\Platform\FinancialLedger\Exceptions\VendorPayableNotAuthorisedException;
use App\Platform\FinancialLedger\JournalReversalKind;
use App\Platform\FinancialLedger\Models\JournalBatch;
use App\Platform\FinancialLedger\Models\VendorPayable as VendorPayableModel;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\VendorPayableAssessmentTrigger;
use App\Platform\FinancialLedger\VendorPayableEligibility;
use App\Platform\FinancialLedger\VendorPayableState;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AC8: "THE SYSTEM SHALL determine vendor payable eligibility explicitly; a
 * paid state SHALL NOT imply payable, and a payable state SHALL NOT imply
 * paid out."
 *
 * The first test in this file is the one this whole task exists to protect.
 * `AGENTS.md` §Marketplace states it in four words — "Paid does not mean
 * completed" — and the defect it guards against is a condition somewhere that
 * reads "the customer paid, therefore we owe the vendor." Being paid is one of
 * three independent conditions, never a shortcut past the other two.
 *
 * Every assertion below re-reads the persisted row through the query builder
 * rather than trusting the model instance the Action handed back, so what is
 * asserted is what the database actually committed.
 */
final class VendorPayableAssessmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_paid_order_with_no_fulfilment_evidence_stays_held(): void
    {
        $payable = $this->assess(new VendorPayableEligibility(
            orderPaid: true,
            fulfilmentEvidenceAccepted: false,
            disputeWindowEndsAt: CarbonImmutable::now()->subDay(),
        ));

        $row = $this->persistedRow($payable);

        $this->assertSame(VendorPayableState::HELD, $row->state);
        $this->assertNull($row->eligible_at);
        $this->assertNull($row->paid_at);
    }

    public function test_a_paid_order_inside_its_dispute_window_stays_held_even_with_accepted_evidence(): void
    {
        $payable = $this->assess(new VendorPayableEligibility(
            orderPaid: true,
            fulfilmentEvidenceAccepted: true,
            disputeWindowEndsAt: CarbonImmutable::now()->addDay(),
        ));

        $this->assertSame(VendorPayableState::HELD, $this->persistedRow($payable)->state);
    }

    public function test_an_absent_dispute_window_is_treated_as_not_elapsed(): void
    {
        // Fail closed: "we have no record of a dispute window" must never read
        // as "the dispute window is over."
        $payable = $this->assess(new VendorPayableEligibility(
            orderPaid: true,
            fulfilmentEvidenceAccepted: true,
            disputeWindowEndsAt: null,
        ));

        $this->assertSame(VendorPayableState::HELD, $this->persistedRow($payable)->state);
    }

    public function test_unpaid_work_with_accepted_evidence_and_an_elapsed_window_stays_held(): void
    {
        $payable = $this->assess(new VendorPayableEligibility(
            orderPaid: false,
            fulfilmentEvidenceAccepted: true,
            disputeWindowEndsAt: CarbonImmutable::now()->subDay(),
        ));

        $this->assertSame(VendorPayableState::HELD, $this->persistedRow($payable)->state);
    }

    public function test_evidence_accepted_plus_elapsed_dispute_window_plus_paid_becomes_payable(): void
    {
        $payable = $this->assess($this->eligible());

        $row = $this->persistedRow($payable);

        $this->assertSame(VendorPayableState::PAYABLE, $row->state);
        $this->assertNotNull($row->eligible_at);
        $this->assertNull($row->paid_at);
        $this->assertSame(250_000, (int) $row->amount_minor);
        $this->assertSame('badan-usaha-1', $row->entity_ref);
        $this->assertSame('vendor-1', $row->vendor_id);
    }

    public function test_eligible_assessment_accrues_dr_cost_and_cr_vendor_liability_once(): void
    {
        $payable = $this->assess($this->eligible());

        $batch = JournalBatch::query()
            ->where('business_key', 'vendor_payable:vendor-1:marketplace_order:order-1')
            ->sole();
        $entries = $batch->entries()->orderBy('account_code')->get();

        $this->assertSame(['2100', '5000'], $entries->pluck('account_code')->all());
        $this->assertSame(['CR', 'DR'], $entries->pluck('direction')->all());
        $this->assertSame([250_000, 250_000], $entries->pluck('amount_minor')->all());

        $this->assess($this->eligible());

        $this->assertSame(
            1,
            JournalBatch::query()
                ->where('business_key', 'vendor_payable:vendor-1:marketplace_order:order-1')
                ->count(),
        );
        $this->assertSame($payable->id, VendorPayableModel::query()->sole()->id);
    }

    public function test_accrual_failure_rolls_back_the_payable_and_audit_together(): void
    {
        $action = new VendorPayable(
            actorContext: ActorContext::guest(),
            journal: new ThrowingJournal,
        );

        try {
            $action->assess(
                vendorId: 'vendor-1',
                entityRef: 'badan-usaha-1',
                sourceType: 'marketplace_order',
                sourceId: 'order-rollback',
                amount: new Money(250_000),
                eligibility: $this->eligible(),
                trigger: VendorPayableAssessmentTrigger::UnattendedAssessment,
            );
            $this->fail('Expected the accrual journal failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('journal fixture failure', $exception->getMessage());
        }

        $this->assertSame(0, VendorPayableModel::query()->count());
        $this->assertSame(0, JournalBatch::query()->count());
        $this->assertSame(
            0,
            DB::table('audit_events')->where('action', VendorPayable::AUDIT_ACTION_ASSESSED)->count(),
        );
    }

    public function test_vendor_liability_account_is_seeded_additively(): void
    {
        $account = DB::table('coa_accounts')->where('code', '2100')->sole();

        $this->assertSame('Liabilitas — Utang Vendor', $account->name);
        $this->assertSame('CR', $account->normal_balance);
    }

    public function test_a_payable_row_is_not_paid_out(): void
    {
        $payable = $this->assess($this->eligible());

        // AC8's second half. "Payable" is what we owe; "paid out" is what we
        // sent. A payable row on its own is proof of the first and evidence of
        // nothing about the second.
        $this->assertSame(VendorPayableState::PAYABLE, $this->persistedRow($payable)->state);
        $this->assertSame(0, DB::table('payouts')->count());
        $this->assertFalse(VendorPayableModel::query()->findOrFail($payable->id)->isPaidOut());
        $this->assertSame(
            0,
            VendorPayableModel::query()->where('state', VendorPayableState::PAID)->count(),
        );
    }

    public function test_re_assessment_updates_the_existing_row_rather_than_creating_a_second_one(): void
    {
        $held = $this->assess(new VendorPayableEligibility(
            orderPaid: true,
            fulfilmentEvidenceAccepted: false,
            disputeWindowEndsAt: CarbonImmutable::now()->subDay(),
        ));

        $reassessed = $this->assess($this->eligible());

        $this->assertSame($held->id, $reassessed->id);
        $this->assertSame(1, VendorPayableModel::query()->count());
        $this->assertSame(VendorPayableState::PAYABLE, $this->persistedRow($reassessed)->state);
    }

    /**
     * ITEM 6 — the held -> payable accrual, which is THE PRODUCTION PATH and
     * had zero coverage.
     *
     * Every existing accrual assertion in this tree goes through `open()`, i.e.
     * a payable that was eligible on its very first assessment. In production
     * the normal shape is the opposite: a payable is opened `held` while the
     * dispute window runs, and a later re-assessment moves it to `payable`.
     * That branch is `reassess()`, and nothing asserted its `accrue()` call.
     *
     * MUTATION RESISTANCE, reasoned and then verified: delete
     * `$this->accrue(...)` from `reassess()`'s eligible branch and the whole
     * repository suite stays green today. After this test it goes red on the
     * `sole()` below — there is no accrual batch to find. The consequence in
     * production is that `2100 Utang Vendor` is never credited for the majority
     * of real obligations: `vendor_payables` says we owe the money, the ledger
     * does not, and `ManualPayout` later posts `DR 2100 / CR 7000` against a
     * liability that was never accrued, driving 2100 permanently negative.
     * Every batch stays individually balanced, so neither the balance trigger
     * nor reconciliation notices.
     */
    public function test_a_held_payable_that_becomes_eligible_accrues_the_vendor_liability(): void
    {
        $held = $this->assess(new VendorPayableEligibility(
            orderPaid: true,
            fulfilmentEvidenceAccepted: false,
            disputeWindowEndsAt: CarbonImmutable::now()->subDay(),
        ));

        // Held means recognised as an obligation but NOT yet a liability in the
        // ledger. Asserted, so the test cannot pass by the accrual having
        // happened at the wrong moment.
        $this->assertSame(VendorPayableState::HELD, $this->persistedRow($held)->state);
        $this->assertSame(0, JournalBatch::query()->count());

        $reassessed = $this->assess($this->eligible());

        $this->assertSame($held->id, $reassessed->id);
        $this->assertSame(VendorPayableState::PAYABLE, $this->persistedRow($reassessed)->state);

        $batch = JournalBatch::query()
            ->where('business_key', 'vendor_payable:vendor-1:marketplace_order:order-1')
            ->sole();
        $entries = $batch->entries()->orderBy('account_code')->get();

        $this->assertSame(['2100', '5000'], $entries->pluck('account_code')->all());
        $this->assertSame(['CR', 'DR'], $entries->pluck('direction')->all());
        $this->assertSame([250_000, 250_000], $entries->pluck('amount_minor')->all());
        $this->assertSame((string) $reassessed->id, $batch->source_id);

        // And the state transition is audited, with the previous state named —
        // the other half of `reassess()`'s eligible branch.
        $audit = DB::table('audit_events')
            ->where('action', VendorPayable::AUDIT_ACTION_ASSESSED)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('vendor_payable', $audit->subject_type);
        $this->assertSame((string) $reassessed->id, $audit->subject_id);
    }

    /**
     * Minor M2 (slice 3), folded in: the accrual and its audit row must carry
     * the CURRENT correlation id, not the one stored on the payable from the
     * first assessment — the run that decided NOT to recognise the debt.
     *
     * MUTATION RESISTANCE: restoring `$payable->correlation_id` at
     * `VendorPayable::reassess()` makes the batch's `correlation_id` come back
     * as `trace-held-run` and both assertions below fail.
     */
    public function test_the_accrual_traces_to_the_run_that_recognised_the_debt_not_the_one_that_deferred_it(): void
    {
        $this->action()->assess(
            vendorId: 'vendor-1',
            entityRef: 'badan-usaha-1',
            sourceType: 'marketplace_order',
            sourceId: 'order-1',
            amount: new Money(250_000),
            eligibility: new VendorPayableEligibility(
                orderPaid: true,
                fulfilmentEvidenceAccepted: false,
                disputeWindowEndsAt: CarbonImmutable::now()->subDay(),
            ),
            trigger: VendorPayableAssessmentTrigger::UnattendedAssessment,
            correlationId: 'trace-held-run',
        );

        $payable = $this->action()->assess(
            vendorId: 'vendor-1',
            entityRef: 'badan-usaha-1',
            sourceType: 'marketplace_order',
            sourceId: 'order-1',
            amount: new Money(250_000),
            eligibility: $this->eligible(),
            trigger: VendorPayableAssessmentTrigger::UnattendedAssessment,
            correlationId: 'trace-accrual-run',
        );

        $batch = JournalBatch::query()
            ->where('business_key', 'vendor_payable:vendor-1:marketplace_order:order-1')
            ->sole();

        $this->assertSame('trace-accrual-run', $batch->correlation_id);
        $this->assertSame(
            'trace-accrual-run',
            DB::table('audit_events')
                ->where('action', VendorPayable::AUDIT_ACTION_ASSESSED)
                ->where('subject_id', (string) $payable->id)
                ->orderByDesc('id')
                ->value('correlation_id'),
        );
    }

    /**
     * ITEM 5 — the human path fails closed, which is the correct state today.
     *
     * `ActorContext::$roles` is always `[]` (that class documents why), so a
     * real human is refused until the identity seam has an authoritative role
     * source. This is the same standing condition `ManualPayout`,
     * `ResolveException` and `BulkFinancialExport` are in, and it is in the
     * merge sign-off bundle.
     *
     * MUTATION RESISTANCE: this is the test that goes red if anyone "unblocks"
     * the seam by defaulting an unauthenticated caller to permitted — the whole
     * refusal, and the absence of the payable, the batch and the audit row, are
     * asserted together. A refusal that still wrote half a liability would be
     * worse than none.
     */
    public function test_a_human_triggered_assessment_is_refused_without_finance_authority(): void
    {
        $this->app->instance(ActorContext::class, new ActorContext(identityReference: '77'));

        try {
            $this->humanAssess();
            $this->fail('Expected the assessment to be refused.');
        } catch (VendorPayableNotAuthorisedException $exception) {
            $this->assertStringContainsString('vendor-1', $exception->getMessage());
        }

        $this->assertSame(0, VendorPayableModel::query()->count());
        $this->assertSame(0, JournalBatch::query()->count());
        $this->assertSame(
            0,
            DB::table('audit_events')->where('action', VendorPayable::AUDIT_ACTION_ASSESSED)->count(),
        );
    }

    /**
     * The role alone is not enough — a privileged grant on THIS vendor is also
     * required, exactly as the payout policy requires one.
     */
    public function test_a_finance_role_without_a_vendor_grant_is_still_refused(): void
    {
        $this->app->instance(
            ActorContext::class,
            new ActorContext(identityReference: '77', roles: ['finance']),
        );

        $this->expectException(VendorPayableNotAuthorisedException::class);

        $this->humanAssess();
    }

    public function test_a_revoked_vendor_grant_does_not_authorise_an_assessment(): void
    {
        $this->app->instance(
            ActorContext::class,
            new ActorContext(identityReference: '77', roles: ['finance']),
        );
        $this->grantVendorScope(revoked: true);

        $this->expectException(VendorPayableNotAuthorisedException::class);

        $this->humanAssess();
    }

    /**
     * The authorized human path, and the reason this whole seam exists: the
     * audit row must name the PERSON, not claim the system did it.
     *
     * MUTATION RESISTANCE: this is the test that goes red under the exact
     * defect that was here before Task 9b — hardcoding `actorRef: null,
     * actorRole: 'system'` in `audit()` fails all three assertions on the audit
     * row. It also goes red if `$actorRole` is taken from anywhere other than
     * the authorizer's verdict.
     */
    public function test_an_authorised_human_assessment_records_the_human_in_the_audit_trail(): void
    {
        $this->app->instance(
            ActorContext::class,
            new ActorContext(identityReference: '77', roles: ['finance']),
        );
        $this->grantVendorScope();

        $payable = $this->humanAssess();

        $this->assertSame(VendorPayableState::PAYABLE, $this->persistedRow($payable)->state);

        $audit = DB::table('audit_events')
            ->where('action', VendorPayable::AUDIT_ACTION_ASSESSED)
            ->sole();

        $this->assertSame('77', $audit->actor_ref);
        $this->assertSame('finance', $audit->actor_role);
        $this->assertSame(AuditSource::Panel->value, $audit->source);
    }

    /**
     * The anti-laundering property, and the thing that makes `actorRole:
     * 'system'` trustworthy at all: an authenticated actor may NOT take the
     * unattended path.
     *
     * Without this, the "explicit system-actor path" would be a bypass — any
     * caller could pass `UnattendedAssessment` and get an audit row asserting
     * the system acted, which is precisely the defect that was here before.
     *
     * MUTATION RESISTANCE: delete the `isAuthenticated()` guard from
     * `FinanceVendorPayableAuthorizer::authorizeUnattended()` and this test
     * goes red — the assessment succeeds instead of throwing.
     */
    public function test_an_authenticated_actor_may_not_launder_an_assessment_through_the_system_path(): void
    {
        $this->app->instance(
            ActorContext::class,
            new ActorContext(identityReference: '77', roles: ['finance']),
        );
        $this->grantVendorScope();

        try {
            $this->assess($this->eligible());
            $this->fail('Expected the unattended path to refuse an authenticated actor.');
        } catch (VendorPayableNotAuthorisedException $exception) {
            $this->assertStringContainsString('authenticated actor is present', $exception->getMessage());
        }

        $this->assertSame(0, VendorPayableModel::query()->count());
        $this->assertSame(0, JournalBatch::query()->count());
    }

    /**
     * And the genuine automated trigger still works, audited truthfully as the
     * system. If this went red the production path would be broken, which is
     * the failure mode the coordinator's ruling explicitly warned against.
     */
    public function test_an_unattended_assessment_is_audited_as_the_system(): void
    {
        $payable = $this->assess($this->eligible());

        $audit = DB::table('audit_events')
            ->where('action', VendorPayable::AUDIT_ACTION_ASSESSED)
            ->sole();

        $this->assertSame((string) $payable->id, $audit->subject_id);
        $this->assertNull($audit->actor_ref);
        $this->assertSame('system', $audit->actor_role);
        $this->assertSame(AuditSource::Job->value, $audit->source);
    }

    public function test_a_payable_never_falls_back_to_held_once_it_is_eligible(): void
    {
        $payable = $this->assess($this->eligible());

        // Evidence being withdrawn later is a dispute or a correction, not a
        // quiet downgrade of a debt we already recognised. Re-assessing with
        // failing inputs leaves the row where it is.
        $reassessed = $this->assess(new VendorPayableEligibility(
            orderPaid: true,
            fulfilmentEvidenceAccepted: false,
            disputeWindowEndsAt: null,
        ));

        $this->assertSame($payable->id, $reassessed->id);
        $this->assertSame(VendorPayableState::PAYABLE, $this->persistedRow($reassessed)->state);
    }

    public function test_assessment_rejects_a_non_integer_amount(): void
    {
        // AC11: money is integer minor units end to end. A weak caller passing
        // a decimal string must not be able to truncate its way into a payable.
        //
        // Invoked through the container rather than as a direct call, so this
        // exercises the real runtime boundary the way an untyped caller (a
        // queued job payload, a Filament form, a Livewire property) reaches it
        // — a direct call would be rejected by static analysis before it ever
        // ran, which proves nothing about runtime.
        $this->expectException(\TypeError::class);

        $this->app->call([$this->action(), 'assess'], [
            'vendorId' => 'vendor-1',
            'entityRef' => 'badan-usaha-1',
            'sourceType' => 'marketplace_order',
            'sourceId' => 'order-1',
            'amount' => '250000',
            'eligibility' => $this->eligible(),
            'trigger' => VendorPayableAssessmentTrigger::UnattendedAssessment,
        ]);
    }

    public function test_assessment_rejects_a_negative_amount(): void
    {
        $this->expectException(InvalidVendorPayableException::class);

        $this->action()->assess(
            vendorId: 'vendor-1',
            entityRef: 'badan-usaha-1',
            sourceType: 'marketplace_order',
            sourceId: 'order-1',
            amount: new Money(-1),
            eligibility: $this->eligible(),
            trigger: VendorPayableAssessmentTrigger::UnattendedAssessment,
        );
    }

    public function test_the_database_refuses_a_state_outside_the_closed_list(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'The vendor_payables_state_check CHECK constraint is PostgreSQL-only; run with DB_CONNECTION=pgsql.'
            );
        }

        $payable = $this->assess($this->eligible());

        $this->expectException(QueryException::class);

        DB::table('vendor_payables')->where('id', $payable->id)->update(['state' => 'settled']);
    }

    public function test_the_database_requires_a_strictly_positive_vendor_payable_amount(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The strict vendor payable amount CHECK is PostgreSQL-only.');
        }

        $this->expectException(QueryException::class);

        DB::table('vendor_payables')->insert([
            'id' => (string) Str::uuid(),
            'vendor_id' => 'vendor-1',
            'entity_ref' => 'badan-usaha-1',
            'source_type' => 'marketplace_order',
            'source_id' => 'zero-amount-order',
            'amount_minor' => 0,
            'state' => VendorPayableState::HELD,
            'eligible_at' => null,
            'paid_at' => null,
            'correlation_id' => null,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    private function eligible(): VendorPayableEligibility
    {
        return new VendorPayableEligibility(
            orderPaid: true,
            fulfilmentEvidenceAccepted: true,
            disputeWindowEndsAt: CarbonImmutable::now()->subDay(),
        );
    }

    /**
     * The eligibility tests in this file are about the RULE, not about who
     * asked. They run through the unattended path — a scheduled re-evaluation
     * of recorded facts, which is the real production trigger — with the
     * default guest actor context a test carries. The authorization tests below
     * exercise both paths deliberately.
     */
    private function assess(VendorPayableEligibility $eligibility): VendorPayableModel
    {
        return $this->action()->assess(
            vendorId: 'vendor-1',
            entityRef: 'badan-usaha-1',
            sourceType: 'marketplace_order',
            sourceId: 'order-1',
            amount: new Money(250_000),
            eligibility: $eligibility,
            trigger: VendorPayableAssessmentTrigger::UnattendedAssessment,
        );
    }

    /**
     * The same assessment as `assess()`, taken through the HUMAN path.
     */
    private function humanAssess(): VendorPayableModel
    {
        return $this->action()->assess(
            vendorId: 'vendor-1',
            entityRef: 'badan-usaha-1',
            sourceType: 'marketplace_order',
            sourceId: 'order-1',
            amount: new Money(250_000),
            eligibility: $this->eligible(),
            trigger: VendorPayableAssessmentTrigger::HumanDecision,
        );
    }

    private function grantVendorScope(bool $revoked = false): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => '77',
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => 'vendor-1',
            'grant_level' => ScopeGrantLevel::PRIVILEGED,
            'revoked_at' => $revoked ? CarbonImmutable::now() : null,
        ]);
    }

    private function persistedRow(VendorPayableModel $payable): object
    {
        return DB::table('vendor_payables')->where('id', $payable->id)->sole();
    }

    private function action(): VendorPayable
    {
        return $this->app->make(VendorPayable::class);
    }
}

final class ThrowingJournal implements JournalContract
{
    public function post(
        string $businessKey,
        int|string $entityRef,
        string $sourceType,
        int|string $sourceId,
        array $entries,
        ?string $correlationId = null,
        ?string $occurredAt = null,
    ): JournalBatch {
        throw new \RuntimeException('journal fixture failure');
    }

    public function postReversal(
        string $originalBusinessKey,
        string $reason,
        JournalReversalKind $kind = JournalReversalKind::Reversal,
        ?string $correlationId = null,
        ?string $occurredAt = null,
    ): JournalBatch {
        throw new \RuntimeException('journal fixture failure');
    }
}
