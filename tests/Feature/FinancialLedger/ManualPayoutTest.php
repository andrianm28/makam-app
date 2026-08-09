<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Audit\SensitiveActions;
use App\Platform\FinancialLedger\Actions\ManualPayout;
use App\Platform\FinancialLedger\Actions\VendorPayable;
use App\Platform\FinancialLedger\Contracts\Journal as JournalContract;
use App\Platform\FinancialLedger\Contracts\PayoutProofVerifier;
use App\Platform\FinancialLedger\Exceptions\InvalidPayoutException;
use App\Platform\FinancialLedger\Exceptions\PayoutActorMismatchException;
use App\Platform\FinancialLedger\Exceptions\PayoutNotAuthorisedException;
use App\Platform\FinancialLedger\Exceptions\PayoutProofNotAcceptedException;
use App\Platform\FinancialLedger\Exceptions\PayoutReauthenticationRequiredException;
use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\JournalReversalKind;
use App\Platform\FinancialLedger\Models\JournalBatch;
use App\Platform\FinancialLedger\Models\Payout;
use App\Platform\FinancialLedger\Models\VendorPayable as VendorPayableModel;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\PayoutMethod;
use App\Platform\FinancialLedger\PayoutProof;
use App\Platform\FinancialLedger\PayoutState;
use App\Platform\FinancialLedger\VendorPayableEligibility;
use App\Platform\FinancialLedger\VendorPayableState;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
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
 * AC9 ("manual payout — recording amount, proof, approver, and reference")
 * and the second half of AC8 ("a payable state SHALL NOT imply paid out").
 *
 * Every assertion re-reads persisted rows rather than trusting a model
 * instance already in hand, and every refusal test asserts the ABSENCE of a
 * payout row, of a payable state change, and of a journal batch — a refusal
 * that throws but leaves half a payout behind is worse than no refusal.
 */
final class ManualPayoutTest extends TestCase
{
    use RefreshDatabase;

    private const string VENDOR = 'vendor-1';

    private const string ENTITY = 'badan-usaha-1';

    private const string APPROVER = '77';

    private const string APPROVER_ROLE = 'finance';

    private const int AMOUNT = 250_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            ActorContext::class,
            new ActorContext(
                identityReference: self::APPROVER,
                roles: [self::APPROVER_ROLE],
            ),
        );
    }

    public function test_vendor_payout_is_already_on_the_sensitive_action_list(): void
    {
        // This module never edits `SensitiveActions::ACTIONS` — a cross-lane
        // coordination on that file is pending. This asserts the wiring the
        // mandatory-reason guard depends on is genuinely there, so removing
        // `VENDOR_PAYOUT` from that list fails here rather than silently
        // making a payout reason optional.
        $this->assertTrue(SensitiveActions::requiresReason(ManualPayout::AUDIT_ACTION));
        $this->assertTrue(ManualPayout::requiresAuditReason());
    }

    public function test_a_payout_records_amount_proof_approver_and_reference(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();

        $payout = $this->pay($payable);

        $row = DB::table('payouts')->where('id', $payout->id)->sole();

        $this->assertSame($payable->id, $row->payable_id);
        $this->assertSame(self::VENDOR, $row->vendor_id);
        $this->assertSame(self::ENTITY, $row->entity_ref);
        $this->assertSame(self::AMOUNT, (int) $row->amount_minor);
        $this->assertSame(PayoutMethod::MANUAL_BANK_TRANSFER, $row->method);
        $this->assertSame(PayoutState::RECORDED, $row->state);
        $this->assertSame('PAYMENT_PROOF', $row->proof_document_kind);
        $this->assertSame('document-vault-ref-1', $row->proof_document_ref);
        $this->assertSame(self::APPROVER, $row->approver_ref);
        $this->assertSame(self::APPROVER_ROLE, $row->approver_role);
        $this->assertSame(
            'payout:'.self::VENDOR.":{$payable->id}",
            $row->journal_business_key,
        );
    }

    public function test_accepted_document_vault_proof_is_verified_against_the_payable_record(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();
        $verifier = new AcceptingPayoutProofVerifier;

        $this->pay($payable, verifier: $verifier);

        $this->assertSame([
            ['PAYMENT_PROOF', 'document-vault-ref-1', 'vendor_payable', (string) $payable->id],
        ], $verifier->calls);
    }

    public function test_a_non_accepted_document_vault_proof_fails_closed_before_persistence(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();

        $this->assertRefused(
            fn (): Payout => $this->pay($payable, verifier: new RejectingPayoutProofVerifier),
            PayoutProofNotAcceptedException::class,
            $payable,
        );
    }

    public function test_a_payout_posts_a_balanced_payout_batch_through_the_journal_write_api(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();

        $payout = $this->pay($payable);

        $batch = JournalBatch::query()
            ->where('business_key', 'payout:'.self::VENDOR.":{$payable->id}")
            ->sole();

        $this->assertSame('payout', $batch->source_type);
        $this->assertSame($payout->id, $batch->source_id);
        $this->assertSame(self::ENTITY, $batch->entity_ref);
        $this->assertSame('posted', $batch->status);
        $this->assertNull($batch->reverses_batch_id);
        $this->assertTrue($batch->isBalanced());
        $this->assertSame(self::AMOUNT, $batch->total());

        $entries = $batch->entries()->orderBy('account_code')->get();

        $this->assertSame(['2100', '7000'], $entries->pluck('account_code')->all());
        $this->assertSame(['DR', 'CR'], $entries->pluck('direction')->all());
        $this->assertSame([self::AMOUNT, self::AMOUNT], $entries->pluck('amount_minor')->all());
    }

    public function test_a_payout_writes_the_sensitive_audit_event_with_its_mandatory_reason(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();

        $payout = $this->pay($payable);

        $event = AuditEvent::query()
            ->where('action', ManualPayout::AUDIT_ACTION)
            ->where('subject_id', $payout->id)
            ->sole();

        $this->assertSame(AuditOutcome::Allowed->value, $event->outcome);
        $this->assertSame('payout', $event->subject_type);
        $this->assertSame(self::APPROVER, $event->actor_ref);
        $this->assertSame(self::APPROVER_ROLE, $event->actor_role);
        $this->assertSame(AuditSource::Panel->value, $event->source);
        $this->assertSame('Approved manual bank transfer for completed vendor work.', $event->reason);
        $this->assertSame(VendorPayableState::PAYABLE, $event->metadata['previous_state']);
        $this->assertSame(VendorPayableState::PAID, $event->metadata['new_state']);
    }

    public function test_the_payable_is_only_paid_out_once_a_payout_record_backs_it(): void
    {
        $payable = $this->eligiblePayable();

        // Before: payable, and therefore NOT paid out. AC8's second half.
        $this->assertFalse(VendorPayableModel::query()->findOrFail($payable->id)->isPaidOut());

        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();
        $this->pay($payable);

        $reread = VendorPayableModel::query()->findOrFail($payable->id);

        $this->assertSame(VendorPayableState::PAID, $reread->state);
        $this->assertNotNull($reread->paid_at);
        $this->assertTrue($reread->isPaidOut());
    }

    public function test_a_payout_is_blocked_when_the_approver_holds_no_payout_authorisation(): void
    {
        $payable = $this->eligiblePayable();
        $this->satisfyReauthentication();

        // No scope assignment at all — fail closed.
        $this->assertRefused(
            fn (): Payout => $this->pay($payable),
            PayoutNotAuthorisedException::class,
            $payable,
        );
    }

    public function test_a_caller_supplied_approver_cannot_select_another_actor(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();

        $this->assertRefused(
            fn (): Payout => $this->pay($payable, approverRef: 'another-actor'),
            PayoutActorMismatchException::class,
            $payable,
        );
    }

    public function test_payout_authorisation_fails_closed_when_the_identity_adapter_has_no_real_role(): void
    {
        $this->app->instance(
            ActorContext::class,
            new ActorContext(identityReference: self::APPROVER, roles: []),
        );
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();

        $this->assertRefused(
            fn (): Payout => $this->pay($payable),
            PayoutNotAuthorisedException::class,
            $payable,
        );
    }

    public function test_a_revoked_or_lesser_grant_is_not_payout_authorisation(): void
    {
        $payable = $this->eligiblePayable();
        $this->satisfyReauthentication();

        // An `assigned` grant lets an actor SEE the vendor's records. It is
        // not authority to move money — rbac-matrix.md's "Payout/refund" row.
        $this->grantPayoutAuthorisation(grantLevel: ScopeGrantLevel::ASSIGNED);
        // A revoked privileged grant is not a grant either.
        $this->grantPayoutAuthorisation(revoked: true);

        $this->assertRefused(
            fn (): Payout => $this->pay($payable),
            PayoutNotAuthorisedException::class,
            $payable,
        );
    }

    public function test_a_payout_is_blocked_without_a_reason(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();

        $this->assertRefused(
            fn (): Payout => $this->pay($payable, reason: '   '),
            AuditReasonRequiredException::class,
            $payable,
        );
    }

    public function test_a_payout_is_blocked_without_recent_reauthentication(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();

        // No satisfied re-authentication event at all.
        $this->assertRefused(
            fn (): Payout => $this->pay($payable),
            PayoutReauthenticationRequiredException::class,
            $payable,
        );

        // The refusal is recorded through the service that owns
        // re-authentication, not swallowed into an exception nobody sees.
        $this->assertSame(
            1,
            ReauthenticationEvent::query()
                ->where('actor_ref', self::APPROVER)
                ->where('outcome', ReauthenticationOutcome::CHALLENGED)
                ->where('reason', ManualPayout::REAUTHENTICATION_REASON)
                ->count(),
        );
    }

    public function test_a_stale_reauthentication_is_not_recent_reauthentication(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();

        $freshnessSeconds = (int) config('reauthentication.freshness_seconds');
        $this->satisfyReauthentication(
            at: CarbonImmutable::now()->subSeconds($freshnessSeconds + 60),
        );

        $this->assertRefused(
            fn (): Payout => $this->pay($payable),
            PayoutReauthenticationRequiredException::class,
            $payable,
        );
    }

    public function test_reauthentication_for_a_different_sensitive_action_does_not_authorise_a_payout(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();

        // Re-proving identity to revoke a certificate must not silently carry
        // over into approving a payout.
        $this->satisfyReauthentication(reason: 'certificate_revoke');

        $this->assertRefused(
            fn (): Payout => $this->pay($payable),
            PayoutReauthenticationRequiredException::class,
            $payable,
        );
    }

    public function test_a_held_payable_cannot_be_paid_out(): void
    {
        $payable = $this->heldPayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();

        $this->assertRefused(
            fn (): Payout => $this->pay($payable),
            InvalidPayoutException::class,
            $payable,
            expectedState: VendorPayableState::HELD,
        );
    }

    public function test_a_payout_that_does_not_discharge_the_payable_in_full_is_refused(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();

        $this->assertRefused(
            fn (): Payout => $this->pay($payable, amountMinor: self::AMOUNT - 1),
            InvalidPayoutException::class,
            $payable,
        );
    }

    public function test_a_payout_requires_a_proof_reference(): void
    {
        $this->expectException(InvalidPayoutException::class);

        new PayoutProof(documentKind: 'PAYMENT_PROOF', documentReference: '  ');
    }

    public function test_a_retried_payout_is_refused_and_writes_nothing_a_second_time(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();

        $this->pay($payable);

        // The ordinary retry: the payable is already `paid`, so the state
        // machine refuses before anything is written.
        try {
            $this->pay($payable);
            $this->fail('Expected the retried payout to be refused.');
        } catch (InvalidPayoutException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, Payout::query()->count());
        $this->assertSame(1, JournalBatch::query()->where('source_type', 'payout')->count());
    }

    public function test_a_replayed_payout_collides_on_the_business_key_and_posts_nothing_twice(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();

        $firstPayout = $this->pay($payable);
        $businessKey = 'payout:'.self::VENDOR.":{$payable->id}";

        // Reach past the state machine deliberately, to prove the ledger's own
        // idempotency is a real backstop rather than something the `paid`
        // check happens to hide. Both the payout row and the payable state are
        // rewound by hand — the situation a repaired row, a restored backup or
        // a hand-edited workflow table could produce — leaving the journal
        // batch as the only remaining guard.
        DB::table('payouts')->where('id', $firstPayout->id)->delete();
        DB::table('vendor_payables')->where('id', $payable->id)->update([
            'state' => VendorPayableState::PAYABLE,
            'paid_at' => null,
        ]);

        try {
            $this->pay($payable);
            $this->fail('Expected a business-key collision on the replayed payout.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // Exactly one batch, exactly its two entries, and the second attempt's
        // payout row rolled back with it (AC3, AC5).
        $this->assertSame(1, JournalBatch::query()->where('business_key', $businessKey)->count());
        $this->assertSame(
            2,
            DB::table('journal_entries')
                ->whereIn('batch_id', JournalBatch::query()->where('business_key', $businessKey)->pluck('id'))
                ->count(),
        );
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame(
            VendorPayableState::PAYABLE,
            DB::table('vendor_payables')->where('id', $payable->id)->value('state'),
        );
    }

    public function test_a_payout_journal_failure_rolls_back_the_payout_and_state_change(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();

        $this->assertRefused(
            fn (): Payout => $this->pay($payable, journal: new FailingPayoutJournal),
            \RuntimeException::class,
            $payable,
        );
        $this->assertSame(0, AuditEvent::query()->where('action', ManualPayout::AUDIT_ACTION)->count());
    }

    public function test_a_payout_never_touches_the_customers_original_journal_rows(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();

        $customerBatch = $this->app->make(Journal::class)->post(
            businessKey: 'payment:customer-event-1',
            entityRef: self::ENTITY,
            sourceType: 'payment',
            sourceId: 'customer-event-1',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => self::AMOUNT],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => self::AMOUNT],
            ],
        );

        $batchBefore = (array) DB::table('journal_batches')->where('id', $customerBatch->id)->sole();
        $entriesBefore = DB::table('journal_entries')
            ->where('batch_id', $customerBatch->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $this->pay($payable);

        $batchAfter = (array) DB::table('journal_batches')->where('id', $customerBatch->id)->sole();
        $entriesAfter = DB::table('journal_entries')
            ->where('batch_id', $customerBatch->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $this->assertSame($batchBefore, $batchAfter);
        $this->assertSame($entriesBefore, $entriesAfter);

        // And the payout is its own separate batch, not a reversal or an
        // amendment of the customer's.
        $this->assertSame(3, JournalBatch::query()->count());
        $this->assertSame(
            0,
            JournalBatch::query()->whereNotNull('reverses_batch_id')->count(),
        );
    }

    public function test_the_database_refuses_a_payout_with_a_blank_proof_reference(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'The payouts_proof_ref_present_check CHECK constraint is PostgreSQL-only; run with DB_CONNECTION=pgsql.'
            );
        }

        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();

        $payout = $this->pay($payable);

        $this->expectException(QueryException::class);

        // Bypassing the Action entirely still cannot produce a payout with no
        // proof: AC9's "recording proof" is enforced by the database too.
        DB::table('payouts')->where('id', $payout->id)->update(['proof_document_ref' => '   ']);
    }

    public function test_the_database_refuses_a_second_payout_against_the_same_payable(): void
    {
        $payable = $this->eligiblePayable();
        $this->grantPayoutAuthorisation();
        $this->satisfyReauthentication();

        $payout = $this->pay($payable);

        $this->expectException(QueryException::class);

        DB::table('payouts')->insert([
            'id' => '00000000-0000-4000-8000-000000000001',
            'payable_id' => $payable->id,
            'vendor_id' => self::VENDOR,
            'entity_ref' => self::ENTITY,
            'amount_minor' => self::AMOUNT,
            'method' => PayoutMethod::MANUAL_BANK_TRANSFER,
            'state' => PayoutState::RECORDED,
            'proof_document_kind' => 'PAYMENT_PROOF',
            'proof_document_ref' => 'document-vault-ref-2',
            'approver_ref' => self::APPROVER,
            'approver_role' => self::APPROVER_ROLE,
            'journal_business_key' => 'payout:'.self::VENDOR.':second-attempt',
            'correlation_id' => null,
            'occurred_at' => CarbonImmutable::now(),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);

        $this->assertNotNull($payout);
    }

    public function test_the_database_requires_a_strictly_positive_payout_amount(): void
    {
        $this->skipUnlessPostgres('The strict payout amount CHECK is PostgreSQL-only.');

        $payable = $this->eligiblePayable();

        $this->expectException(QueryException::class);

        DB::table('payouts')->insert($this->payoutAttributes($payable, [
            'amount_minor' => 0,
        ]));
    }

    public function test_the_database_rejects_payout_values_that_do_not_match_the_payable(): void
    {
        $this->skipUnlessPostgres('The payout/payable consistency trigger is PostgreSQL-only.');

        $payable = $this->eligiblePayable();

        $this->expectException(QueryException::class);

        DB::table('payouts')->insert($this->payoutAttributes($payable, [
            'vendor_id' => 'different-vendor',
        ]));
    }

    public function test_the_database_rejects_paid_payables_without_a_payout_row(): void
    {
        $this->skipUnlessPostgres('The payable/payout state trigger is PostgreSQL-only.');

        $payable = $this->eligiblePayable();

        $this->expectException(QueryException::class);

        DB::table('vendor_payables')->where('id', $payable->id)->update([
            'state' => VendorPayableState::PAID,
            'paid_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  \Closure(): Payout  $attempt
     * @param  class-string<\Throwable>  $expected
     */
    private function assertRefused(
        \Closure $attempt,
        string $expected,
        VendorPayableModel $payable,
        string $expectedState = VendorPayableState::PAYABLE,
    ): void {
        $payoutsBefore = Payout::query()->count();
        $batchesBefore = JournalBatch::query()->count();
        $entriesBefore = DB::table('journal_entries')->count();

        try {
            $attempt();
            $this->fail("Expected {$expected} to be thrown.");
        } catch (\Throwable $thrown) {
            $this->assertInstanceOf($expected, $thrown);
        }

        $this->assertSame($payoutsBefore, Payout::query()->count());
        $this->assertSame($batchesBefore, JournalBatch::query()->count());
        $this->assertSame($entriesBefore, DB::table('journal_entries')->count());

        $row = DB::table('vendor_payables')->where('id', $payable->id)->sole();
        $this->assertSame($expectedState, $row->state);
        $this->assertNull($row->paid_at);
    }

    private function pay(
        VendorPayableModel $payable,
        ?int $amountMinor = null,
        string $reason = 'Approved manual bank transfer for completed vendor work.',
        ?string $approverRef = null,
        ?string $approverRole = null,
        ?PayoutProofVerifier $verifier = null,
        ?JournalContract $journal = null,
    ): Payout {
        return new ManualPayout(
            actorContext: $this->app->make(ActorContext::class),
            proofVerifier: $verifier ?? new AcceptingPayoutProofVerifier,
            journal: $journal ?? new Journal,
        )->pay(
            payableId: (string) $payable->id,
            amount: new Money($amountMinor ?? self::AMOUNT),
            proof: new PayoutProof(
                documentKind: 'PAYMENT_PROOF',
                documentReference: 'document-vault-ref-1',
            ),
            approverRef: $approverRef,
            approverRole: $approverRole,
            reason: $reason,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payoutAttributes(VendorPayableModel $payable, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'payable_id' => $payable->id,
            'vendor_id' => self::VENDOR,
            'entity_ref' => self::ENTITY,
            'amount_minor' => self::AMOUNT,
            'method' => PayoutMethod::MANUAL_BANK_TRANSFER,
            'state' => PayoutState::RECORDED,
            'proof_document_kind' => 'PAYMENT_PROOF',
            'proof_document_ref' => 'document-vault-ref-direct',
            'approver_ref' => self::APPROVER,
            'approver_role' => self::APPROVER_ROLE,
            'journal_business_key' => 'payout:'.self::VENDOR.':direct',
            'correlation_id' => null,
            'occurred_at' => CarbonImmutable::now(),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ], $overrides);
    }

    private function skipUnlessPostgres(string $message): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped($message);
        }
    }

    private function eligiblePayable(): VendorPayableModel
    {
        return $this->assess(new VendorPayableEligibility(
            orderPaid: true,
            fulfilmentEvidenceAccepted: true,
            disputeWindowEndsAt: CarbonImmutable::now()->subDay(),
        ));
    }

    private function heldPayable(): VendorPayableModel
    {
        return $this->assess(new VendorPayableEligibility(
            orderPaid: true,
            fulfilmentEvidenceAccepted: false,
            disputeWindowEndsAt: CarbonImmutable::now()->subDay(),
        ));
    }

    private function assess(VendorPayableEligibility $eligibility): VendorPayableModel
    {
        return $this->app->make(VendorPayable::class)->assess(
            vendorId: self::VENDOR,
            entityRef: self::ENTITY,
            sourceType: 'marketplace_order',
            sourceId: 'order-1',
            amount: new Money(self::AMOUNT),
            eligibility: $eligibility,
        );
    }

    private function grantPayoutAuthorisation(
        string $grantLevel = ScopeGrantLevel::PRIVILEGED,
        bool $revoked = false,
    ): void {
        ScopeAssignment::query()->create([
            'actor_identifier' => self::APPROVER,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => self::VENDOR,
            'grant_level' => $grantLevel,
            'revoked_at' => $revoked ? CarbonImmutable::now() : null,
        ]);
    }

    private function satisfyReauthentication(
        string $reason = ManualPayout::REAUTHENTICATION_REASON,
        ?CarbonImmutable $at = null,
    ): void {
        $event = (new ReauthenticationService)->satisfy(
            actorRef: self::APPROVER,
            actorRole: self::APPROVER_ROLE,
            reason: $reason,
            source: AuditSource::Panel,
        );

        if ($at !== null) {
            DB::table('reauthentication_events')->where('id', $event->id)->update(['occurred_at' => $at]);
        }
    }
}

final class AcceptingPayoutProofVerifier implements PayoutProofVerifier
{
    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public array $calls = [];

    public function assertAccepted(PayoutProof $proof, string $recordType, string $recordId): void
    {
        $this->calls[] = [
            $proof->documentKind,
            $proof->documentReference,
            $recordType,
            $recordId,
        ];

        if ($proof->documentKind !== 'PAYMENT_PROOF') {
            throw new PayoutProofNotAcceptedException;
        }
    }
}

final class RejectingPayoutProofVerifier implements PayoutProofVerifier
{
    public function assertAccepted(PayoutProof $proof, string $recordType, string $recordId): void
    {
        throw new PayoutProofNotAcceptedException;
    }
}

final class FailingPayoutJournal implements JournalContract
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
        throw new \RuntimeException('payout journal fixture failure');
    }

    public function postReversal(
        string $originalBusinessKey,
        string $reason,
        JournalReversalKind $kind = JournalReversalKind::Reversal,
        ?string $correlationId = null,
        ?string $occurredAt = null,
    ): JournalBatch {
        throw new \RuntimeException('payout journal fixture failure');
    }
}
