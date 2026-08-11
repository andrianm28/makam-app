<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Audit\SensitiveActions;
use App\Platform\FinancialLedger\Actions\ResolveException;
use App\Platform\FinancialLedger\Actions\RunReconciliation;
use App\Platform\FinancialLedger\Exceptions\InvalidReconciliationException;
use App\Platform\FinancialLedger\Exceptions\ReconciliationExceptionAlreadyResolvedException;
use App\Platform\FinancialLedger\Exceptions\ReconciliationNotAuthorisedException;
use App\Platform\FinancialLedger\Exceptions\UnknownJournalBatchException;
use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\Models\JournalBatch;
use App\Platform\FinancialLedger\Models\ReconciliationException as ReconciliationExceptionModel;
use App\Platform\FinancialLedger\ProviderStatement;
use App\Platform\FinancialLedger\ReconciliationCorrection;
use App\Platform\FinancialLedger\ReconciliationDecision;
use App\Platform\FinancialLedger\ReconciliationExceptionStatus;
use App\Platform\FinancialLedger\ReconciliationStatus;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * AC10 + AC12 for `Actions\ResolveException`.
 *
 * Three properties carry this file:
 *
 *  1. **Nothing else resolves an exception.** Not a period closure, not a
 *     re-run, not a model method. Asserted behaviourally AND structurally over
 *     the whole `app/Platform/` tree, because a behavioural test only proves
 *     the paths it happened to call.
 *  2. **A refusal writes nothing.** Every refusal case asserts the exception is
 *     still `open`, no audit row exists, and no journal batch was posted — a
 *     refusal that throws but leaves half a decision behind is worse than no
 *     refusal.
 *  3. **`post_correction` posts a NEW batch and never edits one.**
 *
 * Every assertion re-reads persisted rows rather than trusting a model instance
 * already in hand.
 */
final class ResolveReconciliationExceptionTest extends TestCase
{
    use RefreshDatabase;

    private const string ENTITY = 'badan-usaha-1';

    private const string PERIOD = '2026-07';

    private const string DECIDER = '77';

    private const string DECIDER_ROLE = 'finance';

    private const int AMOUNT = 250_000;

    private const string REASON = 'Provider settled a 1.000 rounding difference in our favour; accepted.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            ActorContext::class,
            new ActorContext(
                identityReference: self::DECIDER,
                roles: [self::DECIDER_ROLE],
            ),
        );
    }

    public function test_reconciliation_exception_resolved_is_on_the_sensitive_action_list(): void
    {
        // The Wave 1c ruling this task implements. If the entry is ever removed
        // from `SensitiveActions::ACTIONS`, the mandatory-reason guard silently
        // becomes optional — this fails first rather than letting that happen
        // quietly.
        $this->assertTrue(SensitiveActions::requiresReason(ResolveException::AUDIT_ACTION));
        $this->assertTrue(ResolveException::requiresAuditReason());
    }

    public function test_an_authorised_finance_decision_resolves_the_exception_and_writes_the_audit_event(): void
    {
        $exception = $this->openException();
        $this->grantReconciliationAuthority();

        $resolved = $this->resolve($exception, ReconciliationDecision::ACCEPT_VARIANCE);

        $row = DB::table('reconciliation_exceptions')->where('id', $exception->id)->sole();

        $this->assertSame(ReconciliationExceptionStatus::RESOLVED, $row->status);
        $this->assertSame(ReconciliationDecision::ACCEPT_VARIANCE, $row->decision);
        $this->assertSame(self::DECIDER, $row->decided_by);
        $this->assertNotNull($row->decided_at);
        $this->assertTrue(ReconciliationExceptionModel::query()->findOrFail($exception->id)->isResolved());
        $this->assertSame($exception->id, $resolved->id);
        $this->assertSame(
            ReconciliationStatus::MATCHED,
            DB::table('reconciliations')->where('id', $exception->reconciliation_id)->value('status'),
        );

        $event = AuditEvent::query()
            ->where('action', ResolveException::AUDIT_ACTION)
            ->where('subject_id', $exception->id)
            ->sole();

        $this->assertSame(AuditOutcome::Allowed->value, $event->outcome);
        $this->assertSame('reconciliation_exception', $event->subject_type);
        $this->assertSame(self::DECIDER, $event->actor_ref);
        $this->assertSame(self::DECIDER_ROLE, $event->actor_role);
        $this->assertSame(AuditSource::Panel->value, $event->source);
        $this->assertSame(self::REASON, $event->reason);
        $this->assertSame(ReconciliationExceptionStatus::OPEN, $event->metadata['previous_state']);
        $this->assertSame(ReconciliationExceptionStatus::RESOLVED, $event->metadata['new_state']);
        $this->assertSame(ReconciliationDecision::ACCEPT_VARIANCE, $event->metadata['note']);
    }

    public function test_resolving_without_authorisation_persists_nothing(): void
    {
        $exception = $this->openException();

        // No scope assignment at all — fail closed.
        $this->assertRefused(
            fn (): ReconciliationExceptionModel => $this->resolve($exception, ReconciliationDecision::ESCALATE),
            ReconciliationNotAuthorisedException::class,
            $exception,
        );
    }

    public function test_unknown_and_unauthorised_exception_ids_have_the_same_opaque_failure(): void
    {
        $exception = $this->openException();

        try {
            $this->resolve($exception, ReconciliationDecision::ESCALATE);
            $this->fail('Expected an unauthorised exception resolution to be refused.');
        } catch (ReconciliationNotAuthorisedException $unauthorised) {
            $knownFailure = $unauthorised;
        }

        $unknownException = new ReconciliationExceptionModel;
        $unknownException->id = (string) Str::uuid();

        try {
            $this->resolve($unknownException, ReconciliationDecision::ESCALATE);
            $this->fail('Expected an unknown exception resolution to be refused.');
        } catch (ReconciliationNotAuthorisedException $unknown) {
            $this->assertSame($knownFailure->getMessage(), $unknown->getMessage());
        }

        $malformedException = new ReconciliationExceptionModel;
        $malformedException->id = 'not-an-exception-id';

        try {
            $this->resolve($malformedException, ReconciliationDecision::ESCALATE);
            $this->fail('Expected a malformed exception resolution to be refused.');
        } catch (ReconciliationNotAuthorisedException $malformed) {
            $this->assertSame($knownFailure->getMessage(), $malformed->getMessage());
        }
    }

    public function test_an_empty_role_list_is_not_permission(): void
    {
        $this->app->instance(
            ActorContext::class,
            new ActorContext(identityReference: self::DECIDER, roles: []),
        );

        $exception = $this->openException();
        $this->grantReconciliationAuthority();

        $this->assertRefused(
            fn (): ReconciliationExceptionModel => $this->resolve($exception, ReconciliationDecision::ESCALATE),
            ReconciliationNotAuthorisedException::class,
            $exception,
        );
    }

    public function test_a_vendor_payout_grant_is_not_reconciliation_decision_authority(): void
    {
        $exception = $this->openException();

        // The exact grant that authorises a vendor payout. Payout authority and
        // reconciliation-decision authority are DIFFERENT permissions over
        // different entities; sharing one authorizer would have silently made
        // this pass.
        ScopeAssignment::query()->create([
            'actor_identifier' => self::DECIDER,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => self::ENTITY,
            'grant_level' => ScopeGrantLevel::PRIVILEGED,
            'revoked_at' => null,
        ]);

        $this->assertRefused(
            fn (): ReconciliationExceptionModel => $this->resolve($exception, ReconciliationDecision::ESCALATE),
            ReconciliationNotAuthorisedException::class,
            $exception,
        );
    }

    public function test_a_revoked_or_lesser_grant_is_not_reconciliation_authority(): void
    {
        $exception = $this->openException();

        $this->grantReconciliationAuthority(grantLevel: ScopeGrantLevel::ASSIGNED);
        $this->grantReconciliationAuthority(revoked: true);

        $this->assertRefused(
            fn (): ReconciliationExceptionModel => $this->resolve($exception, ReconciliationDecision::ESCALATE),
            ReconciliationNotAuthorisedException::class,
            $exception,
        );
    }

    public function test_resolving_without_a_reason_persists_nothing(): void
    {
        $exception = $this->openException();
        $this->grantReconciliationAuthority();

        $this->assertRefused(
            fn (): ReconciliationExceptionModel => $this->resolve(
                $exception,
                ReconciliationDecision::ACCEPT_VARIANCE,
                reason: '   ',
            ),
            AuditReasonRequiredException::class,
            $exception,
        );
    }

    public function test_an_unknown_decision_is_refused(): void
    {
        $exception = $this->openException();
        $this->grantReconciliationAuthority();

        $this->assertRefused(
            fn (): ReconciliationExceptionModel => $this->resolve($exception, 'auto_absorb'),
            \InvalidArgumentException::class,
            $exception,
        );
    }

    public function test_a_second_resolve_does_not_overwrite_the_first_decision(): void
    {
        $exception = $this->openException();
        $this->grantReconciliationAuthority();

        $this->resolve($exception, ReconciliationDecision::ACCEPT_VARIANCE);
        $first = DB::table('reconciliation_exceptions')->where('id', $exception->id)->sole();

        try {
            $this->resolve(
                $exception,
                ReconciliationDecision::ESCALATE,
                reason: 'Second opinion: this should have gone upstairs.',
            );
            $this->fail('Expected the second resolve to be refused.');
        } catch (ReconciliationExceptionAlreadyResolvedException $thrown) {
            $this->assertStringContainsString(ReconciliationDecision::ACCEPT_VARIANCE, $thrown->getMessage());
        }

        $second = DB::table('reconciliation_exceptions')->where('id', $exception->id)->sole();

        // The decision, the decider AND the moment all survive untouched.
        $this->assertSame($first->decision, $second->decision);
        $this->assertSame($first->decided_by, $second->decided_by);
        $this->assertSame($first->decided_at, $second->decided_at);
        $this->assertSame(ReconciliationDecision::ACCEPT_VARIANCE, $second->decision);

        // And the second attempt left no second audit row behind either.
        $this->assertSame(
            1,
            AuditEvent::query()->where('action', ResolveException::AUDIT_ACTION)->count(),
        );
    }

    public function test_parent_status_remains_open_until_the_last_exception_is_resolved(): void
    {
        $this->reconcile(self::PERIOD, [
            'payment:evt-1' => self::AMOUNT,
            'payment:evt-2' => self::AMOUNT + 1_000,
        ]);
        $exceptions = ReconciliationExceptionModel::query()->orderBy('subject_ref')->get();
        $this->grantReconciliationAuthority();

        $this->resolve($exceptions[0], ReconciliationDecision::ACCEPT_VARIANCE);

        $this->assertSame(
            ReconciliationStatus::EXCEPTIONS_OPEN,
            DB::table('reconciliations')->where('id', $exceptions[0]->reconciliation_id)->value('status'),
        );

        $this->resolve($exceptions[1], ReconciliationDecision::ACCEPT_VARIANCE);

        $this->assertSame(
            ReconciliationStatus::MATCHED,
            DB::table('reconciliations')->where('id', $exceptions[0]->reconciliation_id)->value('status'),
        );
    }

    public function test_resolving_an_old_exception_does_not_hide_a_missing_latest_statement(): void
    {
        $this->postBatch('payment:evt-1', self::AMOUNT);
        $exception = $this->openException(journalLines: ['payment:evt-1' => self::AMOUNT]);

        $this->app->make(RunReconciliation::class)->run(
            period: self::PERIOD,
            entityRef: self::ENTITY,
            statement: null,
        );
        $this->grantReconciliationAuthority();

        $this->resolve($exception, ReconciliationDecision::ACCEPT_VARIANCE);

        $this->assertSame(
            ReconciliationStatus::STATEMENT_MISSING,
            DB::table('reconciliations')->where('id', $exception->reconciliation_id)->value('status'),
        );
    }

    public function test_a_post_correction_posts_a_new_reversing_batch_and_never_edits_the_original(): void
    {
        $original = $this->postBatch('payment:evt-1', self::AMOUNT);
        $exception = $this->openException(journalLines: ['payment:evt-1' => self::AMOUNT]);
        $this->grantReconciliationAuthority();

        $batchBefore = (array) DB::table('journal_batches')->where('id', $original->id)->sole();
        $entriesBefore = DB::table('journal_entries')
            ->where('batch_id', $original->id)->orderBy('id')->get()
            ->map(fn (object $row): array => (array) $row)->all();

        $this->resolve(
            $exception,
            ReconciliationDecision::POST_CORRECTION,
            correction: ReconciliationCorrection::reversalOf('payment:evt-1'),
        );

        $batchAfter = (array) DB::table('journal_batches')->where('id', $original->id)->sole();
        $entriesAfter = DB::table('journal_entries')
            ->where('batch_id', $original->id)->orderBy('id')->get()
            ->map(fn (object $row): array => (array) $row)->all();

        // AC14: the correction is a NEW batch. The original is byte-identical.
        $this->assertSame($batchBefore, $batchAfter);
        $this->assertSame($entriesBefore, $entriesAfter);

        $reversal = JournalBatch::query()->where('reverses_batch_id', $original->id)->sole();
        $this->assertSame('reversal:payment:evt-1', $reversal->business_key);
        $this->assertTrue($reversal->isBalanced());

        $this->assertSame(
            ReconciliationExceptionStatus::RESOLVED,
            DB::table('reconciliation_exceptions')->where('id', $exception->id)->value('status'),
        );
    }

    public function test_a_post_correction_can_post_a_new_adjusting_batch(): void
    {
        $exception = $this->openException();
        $this->grantReconciliationAuthority();

        $this->resolve(
            $exception,
            ReconciliationDecision::POST_CORRECTION,
            correction: ReconciliationCorrection::adjustment(
                businessKey: 'manual_verification:recon-adjustment-1',
                entityRef: self::ENTITY,
                subjectRef: 'payment:evt-1',
                sourceType: 'manual_verification',
                sourceId: 'recon-adjustment-1',
                entries: [
                    ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 1_000],
                    ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 1_000],
                ],
            ),
        );

        $batch = JournalBatch::query()->where('business_key', 'manual_verification:recon-adjustment-1')->sole();

        $this->assertSame('manual_verification', $batch->source_type);
        $this->assertTrue($batch->isBalanced());
        $this->assertNull($batch->reverses_batch_id);
        $this->assertSame(
            ReconciliationExceptionStatus::RESOLVED,
            DB::table('reconciliation_exceptions')->where('id', $exception->id)->value('status'),
        );
    }

    public function test_a_reversal_correction_must_match_the_exception_subject(): void
    {
        $this->postBatch('payment:evt-1', self::AMOUNT);
        $exception = $this->openException(journalLines: ['payment:evt-1' => self::AMOUNT]);
        $this->grantReconciliationAuthority();

        $this->assertRefused(
            fn (): ReconciliationExceptionModel => $this->resolve(
                $exception,
                ReconciliationDecision::POST_CORRECTION,
                correction: ReconciliationCorrection::reversalOf('payment:other'),
            ),
            InvalidReconciliationException::class,
            $exception,
            expectedBatches: 1,
        );
    }

    public function test_an_adjustment_correction_must_match_the_exception_entity_and_subject(): void
    {
        $exception = $this->openException();
        $this->grantReconciliationAuthority();

        $this->assertRefused(
            fn (): ReconciliationExceptionModel => $this->resolve(
                $exception,
                ReconciliationDecision::POST_CORRECTION,
                correction: ReconciliationCorrection::adjustment(
                    businessKey: 'manual_verification:wrong-entity',
                    entityRef: 'badan-usaha-2',
                    subjectRef: 'payment:other',
                    sourceType: 'manual_verification',
                    sourceId: 'wrong-entity',
                    entries: [
                        ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 1_000],
                        ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 1_000],
                    ],
                ),
            ),
            InvalidReconciliationException::class,
            $exception,
        );
    }

    public function test_an_adjustment_correction_must_match_the_exception_subject_when_entity_matches(): void
    {
        $exception = $this->openException();
        $this->grantReconciliationAuthority();

        $this->assertRefused(
            fn (): ReconciliationExceptionModel => $this->resolve(
                $exception,
                ReconciliationDecision::POST_CORRECTION,
                correction: ReconciliationCorrection::adjustment(
                    businessKey: 'manual_verification:wrong-subject',
                    entityRef: self::ENTITY,
                    subjectRef: 'payment:other',
                    sourceType: 'manual_verification',
                    sourceId: 'wrong-subject',
                    entries: [
                        ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 1_000],
                        ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 1_000],
                    ],
                ),
            ),
            InvalidReconciliationException::class,
            $exception,
        );
    }

    public function test_changed_statement_after_resolution_creates_a_new_exception_version(): void
    {
        $this->postBatch('payment:evt-1', self::AMOUNT);
        $exception = $this->openException(journalLines: ['payment:evt-1' => self::AMOUNT]);
        $this->grantReconciliationAuthority();
        $this->resolve($exception, ReconciliationDecision::ACCEPT_VARIANCE);

        $this->reconcile(
            self::PERIOD,
            ['payment:evt-1' => self::AMOUNT + 2_000],
            reference: 'statement-ref-new',
        );

        $versions = ReconciliationExceptionModel::query()
            ->where('entity_ref', self::ENTITY)
            ->where('period', self::PERIOD)
            ->where('type', 'amount_mismatch')
            ->where('subject_ref', 'payment:evt-1')
            ->orderBy('version')
            ->get();

        $this->assertCount(2, $versions);
        $this->assertSame(ReconciliationExceptionStatus::RESOLVED, $versions[0]->status);
        $this->assertSame(ReconciliationDecision::ACCEPT_VARIANCE, $versions[0]->decision);
        $this->assertSame(1, $versions[0]->version);
        $this->assertSame(ReconciliationExceptionStatus::OPEN, $versions[1]->status);
        $this->assertSame(2, $versions[1]->version);
        $this->assertSame($versions[0]->id, $versions[1]->supersedes_id);
        $this->assertSame('statement-ref-new', $versions[1]->statement_reference);
        $this->assertSame(self::AMOUNT + 2_000, $versions[1]->statement_amount_minor);
        $this->assertSame(ReconciliationStatus::EXCEPTIONS_OPEN, DB::table('reconciliations')
            ->where('entity_ref', self::ENTITY)
            ->where('period', self::PERIOD)
            ->value('status'));
    }

    public function test_a_failing_correction_rolls_back_the_resolution_and_the_audit_row(): void
    {
        $exception = $this->openException();
        $this->grantReconciliationAuthority();

        // The correction and the resolution commit or roll back as one, so a
        // resolved exception can never end up with no correction behind it.
        $this->assertRefused(
            fn (): ReconciliationExceptionModel => $this->resolve(
                $exception,
                ReconciliationDecision::POST_CORRECTION,
                correction: ReconciliationCorrection::reversalOf('payment:evt-1'),
            ),
            UnknownJournalBatchException::class,
            $exception,
        );
    }

    public function test_a_correction_is_refused_with_any_decision_other_than_post_correction(): void
    {
        $this->postBatch('payment:evt-1', self::AMOUNT);
        $exception = $this->openException(journalLines: ['payment:evt-1' => self::AMOUNT]);
        $this->grantReconciliationAuthority();

        $this->assertRefused(
            fn (): ReconciliationExceptionModel => $this->resolve(
                $exception,
                ReconciliationDecision::ACCEPT_VARIANCE,
                correction: ReconciliationCorrection::reversalOf('payment:evt-1'),
            ),
            InvalidReconciliationException::class,
            $exception,
            expectedBatches: 1,
        );
    }

    public function test_post_correction_without_a_correction_is_refused(): void
    {
        $exception = $this->openException();
        $this->grantReconciliationAuthority();

        $this->assertRefused(
            fn (): ReconciliationExceptionModel => $this->resolve(
                $exception,
                ReconciliationDecision::POST_CORRECTION,
            ),
            InvalidReconciliationException::class,
            $exception,
        );
    }

    public function test_an_exception_is_not_resolved_by_closing_or_rolling_over_the_period(): void
    {
        $exception = $this->openException();

        // "Closing" the period is expressed here the only way this codebase can
        // express it: reconciling the period again — now with a statement that
        // agrees, i.e. the difference is gone — and then rolling on to the next
        // period. Neither may flip an open exception to resolved.
        $this->reconcile(self::PERIOD, ['payment:evt-1' => self::AMOUNT]);
        $this->reconcile('2026-08', []);

        $row = DB::table('reconciliation_exceptions')->where('id', $exception->id)->sole();

        $this->assertSame(ReconciliationExceptionStatus::OPEN, $row->status);
        $this->assertNull($row->decision);
        $this->assertNull($row->decided_by);
        $this->assertNull($row->decided_at);
        $this->assertSame(0, AuditEvent::query()->where('action', ResolveException::AUDIT_ACTION)->count());
    }

    public function test_a_rerun_does_not_reopen_or_rewrite_a_resolved_exception(): void
    {
        $exception = $this->openException();
        $this->grantReconciliationAuthority();
        $this->resolve($exception, ReconciliationDecision::ACCEPT_VARIANCE);

        $before = (array) DB::table('reconciliation_exceptions')->where('id', $exception->id)->sole();

        // The same scheduled run that found it in the first place, redelivered.
        $this->reconcile(self::PERIOD, []);

        $after = (array) DB::table('reconciliation_exceptions')->where('id', $exception->id)->sole();

        $this->assertSame($before, $after);
        $this->assertSame(1, ReconciliationExceptionModel::query()->count());
    }

    public function test_resolve_exception_is_the_only_writer_that_marks_an_exception_resolved(): void
    {
        $writers = [];

        foreach (Finder::create()->files()->in(base_path('app/Platform'))->name('*.php') as $file) {
            $contents = (string) $file->getContents();

            // Both spellings a writer could use: the constant, and the literal
            // it evaluates to. A doc-block mention is not a write, so the match
            // requires the assignment shape an update/insert array uses.
            $matchesConstant = preg_match(
                "/'status'\s*=>\s*ReconciliationExceptionStatus::RESOLVED/",
                $contents,
            ) === 1;
            $matchesLiteral = preg_match("/'status'\s*=>\s*'resolved'/", $contents) === 1;

            if ($matchesConstant || $matchesLiteral) {
                $writers[] = $file->getRelativePathname();
            }
        }

        sort($writers);

        // AC10: "No exception resolves by period closure." Enforced by there
        // being exactly one place in the platform tree that can write the
        // value at all — a future `closePeriod()` helper cannot quietly
        // acquire the power without failing here.
        $this->assertSame(['FinancialLedger/Actions/ResolveException.php'], $writers);
    }

    /**
     * @param  \Closure(): ReconciliationExceptionModel  $attempt
     * @param  class-string<\Throwable>  $expected
     */
    /**
     * Slice-3 Minor M5, closed at this seam: a corrective batch may not declare
     * one kind of event in its `source_type` and another in its business-key
     * prefix.
     *
     * `payout` is the case that matters and is why this is the fixture. It is a
     * legal member of `journal_batches_source_type_check`, and both arguments
     * arrive caller-supplied from a human resolving an exception — so before
     * this guard, resolving an exception could post a batch the ledger and the
     * reconciliation both read as a payout, with no `payouts` row, no proof, no
     * approver, no re-authentication and no `VENDOR_PAYOUT` audit behind it.
     *
     * MUTATION RESISTANCE: remove `assertPrefixDeclaresSourceType()` from
     * `adjustment()` and this goes red — but as with the blank-`entityRef`
     * test, the assertion that carries it is the SECOND one, not the throw. A
     * guard "fixed" by rewriting the prefix instead of refusing would still
     * throw nothing and still post the batch; asserting that no journal batch
     * exists is what pins the refusal to a refusal. Verified by executing the
     * removal.
     */
    public function test_a_correction_may_not_declare_one_source_type_and_key_itself_as_another(): void
    {
        $exception = $this->openException();
        $this->grantReconciliationAuthority();

        $this->assertRefused(
            fn (): ReconciliationExceptionModel => $this->resolve(
                $exception,
                ReconciliationDecision::POST_CORRECTION,
                correction: ReconciliationCorrection::adjustment(
                    // A CORRECTABLE source type, keyed as something else
                    // entirely. It must use an allowed source type, or
                    // `assertCorrectableSourceType()` fires first and this test
                    // stops exercising the prefix rule it exists for — the
                    // exact way this pair of guards could hide each other.
                    businessKey: 'payment:recon-mislabelled-1',
                    entityRef: self::ENTITY,
                    subjectRef: 'payment:evt-1',
                    sourceType: 'manual_verification',
                    sourceId: 'recon-mislabelled-1',
                    entries: [
                        ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 1_000],
                        ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 1_000],
                    ],
                ),
            ),
            InvalidReconciliationException::class,
            $exception,
        );

        $this->assertSame(
            0,
            JournalBatch::query()->where('business_key', 'payment:recon-mislabelled-1')->count(),
            'A mislabelled corrective batch must not reach the ledger at all.',
        );
    }

    /**
     * **M5's own scenario, verbatim — the test that was missing.**
     *
     * The slice-3 verdict described it as `sourceType: 'payout'` with key
     * `payout:v-9:x`. The first fix required prefix == sourceType, which closes
     * a MISMATCH but sails straight past this: prefix `payout` equals source
     * type `payout`. I confirmed by execution that `adjustment()` accepted this
     * exact input and posted a batch the ledger reads as a payout — so the
     * addendum's "M5 FIXED" claim was wrong, and this lane's documented failure
     * mode (overstated closure) had claimed another one.
     *
     * `CORRECTABLE_SOURCE_TYPES` is what actually closes it.
     *
     * MUTATION RESISTANCE: remove `assertCorrectableSourceType()` from
     * `adjustment()` and this goes red while the earlier mismatch test stays
     * green — which is precisely the asymmetry that let the gap through the
     * first time. Verified by executing that removal.
     *
     * The batch-count assertion is again the load-bearing half: a guard
     * "fixed" by silently rewriting `payout` to `manual_verification` would
     * throw nothing and still post a batch.
     */
    public function test_a_correction_may_not_declare_itself_a_payout_even_with_a_matching_prefix(): void
    {
        $exception = $this->openException();
        $this->grantReconciliationAuthority();

        $this->assertRefused(
            fn (): ReconciliationExceptionModel => $this->resolve(
                $exception,
                ReconciliationDecision::POST_CORRECTION,
                correction: ReconciliationCorrection::adjustment(
                    businessKey: 'payout:v-9:x',
                    entityRef: self::ENTITY,
                    subjectRef: 'payment:evt-1',
                    sourceType: 'payout',
                    sourceId: 'v-9-x',
                    entries: [
                        ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 1_000],
                        ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 1_000],
                    ],
                ),
            ),
            InvalidReconciliationException::class,
            $exception,
        );

        $this->assertSame(
            0,
            JournalBatch::query()->where('source_type', 'payout')->count(),
            'A reconciliation correction must never conjure a payout-labelled batch: there would be '
            .'no payouts row, no proof, no approver, no re-authentication and no VENDOR_PAYOUT audit '
            .'behind it, yet the report and the reconciliation would both read it as a payout.',
        );
    }

    /**
     * The rest of the excluded list, so the guard is pinned as a CLOSED LIST
     * rather than as a special case for `payout`. `reversal` and `refund` are
     * in here for a specific reason beyond ownership: `adjustment()` posts via
     * `Journal::post()`, which never sets `reverses_batch_id`, so a reversal
     * posted this way would carry no link to what it reverses and would evade
     * the one-reversal-ever UNIQUE index — merge sign-off bundle item 1.
     */
    public function test_no_other_actions_event_type_may_be_conjured_by_a_correction(): void
    {
        foreach (['payout', 'vendor_payable', 'reversal', 'refund', 'chargeback', 'payment', 'renewal'] as $sourceType) {
            try {
                ReconciliationCorrection::adjustment(
                    businessKey: "{$sourceType}:probe-{$sourceType}",
                    entityRef: self::ENTITY,
                    subjectRef: 'payment:evt-1',
                    sourceType: $sourceType,
                    sourceId: "probe-{$sourceType}",
                    entries: [
                        ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 1_000],
                        ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 1_000],
                    ],
                );

                $this->fail("A correction was allowed to declare source type [{$sourceType}].");
            } catch (InvalidReconciliationException $exception) {
                $this->assertStringContainsString($sourceType, $exception->getMessage());
            }
        }

        // And the list is genuinely a list, not an accident of this loop.
        $this->assertSame(['manual_verification'], ReconciliationCorrection::CORRECTABLE_SOURCE_TYPES);
    }

    /**
     * An unprefixed business key is refused by the same guard. `Journal`'s own
     * check would also catch this, but only AFTER the resolution transaction
     * has opened; refusing at construction keeps the two entry points agreeing.
     */
    public function test_a_correction_with_an_unprefixed_business_key_is_refused(): void
    {
        $this->expectException(InvalidReconciliationException::class);

        ReconciliationCorrection::adjustment(
            businessKey: 'no-prefix-here',
            entityRef: self::ENTITY,
            subjectRef: 'payment:evt-1',
            sourceType: 'manual_verification',
            sourceId: 'recon-unprefixed-1',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 1_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 1_000],
            ],
        );
    }

    /**
     * The counterpart every "X is refused" test needs: the conforming shape
     * still works. Without this, a guard that refused EVERYTHING would pass
     * both tests above.
     *
     * `test_a_post_correction_can_post_a_new_adjusting_batch` is the fuller
     * version; this one exists so the refusal tests have an explicit positive
     * control sitting next to them.
     */
    public function test_a_correction_whose_prefix_matches_its_source_type_is_accepted(): void
    {
        $correction = ReconciliationCorrection::adjustment(
            businessKey: 'manual_verification:recon-conforming-1',
            entityRef: self::ENTITY,
            subjectRef: 'payment:evt-1',
            sourceType: 'manual_verification',
            sourceId: 'recon-conforming-1',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 1_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 1_000],
            ],
        );

        $this->assertFalse($correction->isReversal());
    }

    /**
     * The enumeration-oracle property, pinned.
     *
     * ---------------------------------------------------------------------
     * IMPORTANT — this test documents that the code was ALREADY CORRECT
     * ---------------------------------------------------------------------
     * Task 9b's report raised an observation that `ResolveException` carried
     * the same asymmetry that Minor M6 closed in `ManualPayout`, on the
     * grounds that `actorReference()` sits outside the `try` that normalises
     * the authorizer's refusal. **That observation was WRONG**, and this test
     * is the execution that proves it:
     * `ResolveException::actorReference()` already throws
     * `forUnavailableException()` — the SAME opaque refusal as an unknown id —
     * whereas `ManualPayout::actorReference()` throws
     * `forActorContext('unknown')`, which is what made the M6 fix necessary
     * THERE and not here. The two Actions were never the same shape.
     *
     * No production code was changed for this.
     *
     * SECOND CORRECTION, to my own first one: the property was NOT "entirely
     * unpinned" either, as this doc block originally claimed.
     * `test_unknown_and_unauthorised_exception_ids_have_the_same_opaque_failure`
     * already covered the unknown, unauthorised and malformed-id shapes. What
     * it does NOT cover — and the only thing this test adds — is the
     * UNAUTHENTICATED caller, which is exactly the shape my wrong observation
     * was about. Kept for that one case, and for asserting the exception TYPE
     * alongside the message; if it is ever consolidated into the older test,
     * the guest case is the part that must survive.
     *
     * MUTATION RESISTANCE, verified by execution: change `actorReference()` to
     * throw `forActorContext()` instead and the third comparison fails.
     */
    public function test_an_unknown_exception_and_an_unauthorised_one_are_indistinguishable(): void
    {
        $exception = $this->openException();

        // (a) A well-formed id that does not exist.
        $unknown = $this->refusalFor((string) Str::uuid());

        // (b) A real exception, real actor, but no reconciliation grant.
        $unauthorised = $this->refusalFor((string) $exception->id);

        $this->assertSame(
            $unknown,
            $unauthorised,
            'A caller must not be able to tell a fictitious exception id from one they may not see.',
        );

        // (c) A real exception, but no identity on the context at all.
        $this->app->instance(ActorContext::class, ActorContext::guest());

        $this->assertSame(
            $unknown,
            $this->refusalFor((string) $exception->id),
            'An unauthenticated caller must not be able to distinguish them either.',
        );
    }

    /**
     * @return array{class-string, string}
     */
    private function refusalFor(string $exceptionId): array
    {
        try {
            new ResolveException(
                actorContext: $this->app->make(ActorContext::class),
            )->resolve(
                exceptionId: $exceptionId,
                decision: ReconciliationDecision::ACCEPT_VARIANCE,
                reason: self::REASON,
            );

            $this->fail("Expected [{$exceptionId}] to be refused.");
        } catch (\Throwable $thrown) {
            return [$thrown::class, $thrown->getMessage()];
        }
    }

    private function assertRefused(
        \Closure $attempt,
        string $expected,
        ReconciliationExceptionModel $exception,
        int $expectedBatches = 0,
    ): void {
        $auditBefore = AuditEvent::query()->count();

        try {
            $attempt();
            $this->fail("Expected {$expected} to be thrown.");
        } catch (\Throwable $thrown) {
            $this->assertInstanceOf($expected, $thrown);
        }

        $row = DB::table('reconciliation_exceptions')->where('id', $exception->id)->sole();

        $this->assertSame(ReconciliationExceptionStatus::OPEN, $row->status);
        $this->assertNull($row->decision);
        $this->assertNull($row->decided_by);
        $this->assertNull($row->decided_at);

        $this->assertSame(0, AuditEvent::query()->where('action', ResolveException::AUDIT_ACTION)->count());
        $this->assertSame($auditBefore, AuditEvent::query()->count());
        $this->assertSame($expectedBatches, JournalBatch::query()->count());
    }

    private function resolve(
        ReconciliationExceptionModel $exception,
        string $decision,
        string $reason = self::REASON,
        ?ReconciliationCorrection $correction = null,
    ): ReconciliationExceptionModel {
        return new ResolveException(
            actorContext: $this->app->make(ActorContext::class),
        )->resolve(
            exceptionId: (string) $exception->id,
            decision: $decision,
            reason: $reason,
            correction: $correction,
        );
    }

    /**
     * Produce a real open exception the way production does — by running a real
     * reconciliation whose statement disagrees with the journal — rather than
     * by hand-inserting a row that might not match what the Action writes.
     *
     * @param  array<string, int>  $journalLines
     */
    private function openException(array $journalLines = []): ReconciliationExceptionModel
    {
        if ($journalLines === []) {
            $this->reconcile(self::PERIOD, ['payment:evt-1' => self::AMOUNT]);

            return ReconciliationExceptionModel::query()->sole();
        }

        $lines = [];

        foreach ($journalLines as $businessKey => $amountMinor) {
            $lines[$businessKey] = $amountMinor + 1_000;
        }

        $this->reconcile(self::PERIOD, $lines);

        return ReconciliationExceptionModel::query()->sole();
    }

    /**
     * @param  array<string, int>  $lines
     */
    private function reconcile(string $period, array $lines, string $reference = ''): void
    {
        $reference = $reference !== '' ? $reference : 'statement-ref-'.$period;

        $this->app->make(RunReconciliation::class)->run(
            period: $period,
            entityRef: self::ENTITY,
            statement: new ProviderStatement(
                reference: $reference,
                period: $period,
                entityRef: self::ENTITY,
                lines: $lines,
            ),
        );
    }

    private function postBatch(string $businessKey, int $amountMinor): JournalBatch
    {
        return $this->app->make(Journal::class)->post(
            businessKey: $businessKey,
            entityRef: self::ENTITY,
            sourceType: 'payment',
            sourceId: $businessKey,
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => $amountMinor],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => $amountMinor],
            ],
            occurredAt: '2026-07-15 10:00:00',
        );
    }

    private function grantReconciliationAuthority(
        string $grantLevel = ScopeGrantLevel::PRIVILEGED,
        bool $revoked = false,
    ): void {
        ScopeAssignment::query()->create([
            'actor_identifier' => self::DECIDER,
            'entity_type' => ScopeEntityType::BUSINESS_ENTITY,
            'entity_id' => self::ENTITY,
            'grant_level' => $grantLevel,
            'revoked_at' => $revoked ? CarbonImmutable::now() : null,
        ]);
    }
}
