<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Models\User;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FinancialLedger\Actions\BulkFinancialExport;
use App\Platform\FinancialLedger\BulkFinancialExportResult;
use App\Platform\FinancialLedger\Exceptions\BulkFinancialExportReauthenticationRequiredException;
use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;
use App\Platform\FinancialLedger\Exceptions\LedgerReadNotAuthorisedException;
use App\Platform\FinancialLedger\FinanceLedgerReadAuthorizer;
use App\Platform\FinancialLedger\Journal;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * AC13: the bulk financial export — authorized, re-authenticated,
 * deterministic CSV, and an audit row per export. Four layers are exercised:
 * the finance authorization policy and its query-level badan-usaha scope, the
 * domain re-authentication gate, the bytes, and the real route.
 *
 * The authorization tests are the load-bearing ones. This endpoint previously
 * had no policy at all: any authenticated account with a fresh session could
 * export every account total for every badan usaha, and this file's own
 * happy-path route test proved it while claiming to prove the feature. The
 * refusal cases below exist so that regression is caught by a red test rather
 * than by the next review.
 */
final class BulkFinancialExportTest extends TestCase
{
    use RefreshDatabase;

    private const string REASON = 'bulk_financial_export';

    private const string ACTOR = '91';

    private const string ROLE = FinanceLedgerReadAuthorizer::FINANCE_ROLE;

    private const string ENTITY = 'badan-usaha-1';

    private const string OTHER_ENTITY = 'badan-usaha-2';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsFinanceActor(self::ACTOR);
    }

    public function test_export_records_an_audit_row_and_returns_deterministic_csv(): void
    {
        $this->seedLedger();
        $this->grantLedgerReadAuthority();
        $this->reauthenticateRecently();

        $result = $this->export(correlationId: 'trace-export-1');

        $this->assertSame('financial-ledger-summary-2026-08.csv', $result->filename);
        $this->assertSame('text/csv; charset=UTF-8', $result->mimeType);
        $this->assertSame(100_000, $result->debitTotal);
        $this->assertSame(100_000, $result->creditTotal);

        // Byte-exact, and every amount column now names its unit. The columns
        // used to be `debit_total,credit_total,net` beside a bare `IDR`, which
        // read as rupiah while carrying sen — 100x the real value.
        $this->assertSame(
            "\"account_code\",\"debit_minor\",\"credit_minor\",\"net_minor\",\"currency\",\"minor_units\"\n".
            "\"4000\",\"0\",\"100000\",\"-100000\",\"IDR\",\"2\"\n".
            "\"7000\",\"100000\",\"0\",\"100000\",\"IDR\",\"2\"\n".
            "\"TOTAL\",\"100000\",\"100000\",\"0\",\"IDR\",\"2\"\n",
            $result->contents,
        );

        $auditEvent = AuditEvent::query()
            ->where('action', BulkFinancialExport::AUDIT_ACTION)
            ->firstOrFail();

        $this->assertSame(self::ACTOR, $auditEvent->actor_ref);
        // The role recorded is the one the policy approved, not the literal
        // `authenticated_actor` the HTTP controller used to hand in.
        $this->assertSame(self::ROLE, $auditEvent->actor_role);
        $this->assertSame('financial_ledger_report', $auditEvent->subject_type);
        $this->assertSame('summary:2026-08:'.self::ENTITY, $auditEvent->subject_id);
        $this->assertSame(['note' => 'summary:2026-08:'.self::ENTITY], $auditEvent->metadata);
        $this->assertSame('trace-export-1', $auditEvent->correlation_id);
    }

    public function test_an_actor_with_no_finance_role_is_refused(): void
    {
        $this->seedLedger();
        // A grant without the role is not permission, and neither is a fresh
        // re-authentication.
        $this->grantLedgerReadAuthority();
        $this->reauthenticateRecently();
        $this->app->instance(ActorContext::class, new ActorContext(
            identityReference: self::ACTOR,
            roles: [],
        ));

        $this->expectException(LedgerReadNotAuthorisedException::class);

        $this->export();
    }

    public function test_a_finance_actor_with_no_business_entity_grant_is_refused(): void
    {
        $this->seedLedger();
        $this->reauthenticateRecently();

        $this->expectException(LedgerReadNotAuthorisedException::class);

        $this->export();
    }

    public function test_a_revoked_grant_is_not_permission(): void
    {
        $this->seedLedger();
        $this->grantLedgerReadAuthority(revoked: true);
        $this->reauthenticateRecently();

        $this->expectException(LedgerReadNotAuthorisedException::class);

        $this->export();
    }

    public function test_a_non_privileged_grant_is_not_permission(): void
    {
        $this->seedLedger();
        $this->grantLedgerReadAuthority(grantLevel: ScopeGrantLevel::READ);
        $this->reauthenticateRecently();

        $this->expectException(LedgerReadNotAuthorisedException::class);

        $this->export();
    }

    /**
     * The defect this policy closes, stated as a test: asking for a badan
     * usaha the actor holds no grant for is refused outright, never silently
     * ignored (which would have widened the export back to everything).
     */
    public function test_requesting_an_ungranted_badan_usaha_is_refused_not_ignored(): void
    {
        $this->seedLedger();
        $this->grantLedgerReadAuthority();
        $this->reauthenticateRecently();

        $this->expectException(LedgerReadNotAuthorisedException::class);

        $this->export(entityRef: self::OTHER_ENTITY);
    }

    /**
     * Omitting `entityRef` no longer means "every badan usaha in the
     * deployment" — it means "every badan usaha this actor is granted". The
     * ledger below holds batches for two, and the actor is granted one.
     */
    public function test_an_unspecified_scope_covers_only_the_granted_badan_usaha(): void
    {
        $this->seedLedger();
        $this->seedLedger(entityRef: self::OTHER_ENTITY, suffix: '-other', amountMinor: 555_000);
        $this->grantLedgerReadAuthority();
        $this->reauthenticateRecently();

        $result = $this->export();

        $this->assertSame(100_000, $result->debitTotal);
        $this->assertSame(100_000, $result->creditTotal);
        $this->assertStringNotContainsString('555000', $result->contents);
        $this->assertSame(self::ENTITY, $result->report->scopeLabel());
    }

    public function test_multiple_grants_are_reported_together_and_labelled_as_the_set(): void
    {
        $this->seedLedger();
        $this->seedLedger(entityRef: self::OTHER_ENTITY, suffix: '-other', amountMinor: 55_000);
        $this->grantLedgerReadAuthority();
        $this->grantLedgerReadAuthority(entityRef: self::OTHER_ENTITY);
        $this->reauthenticateRecently();

        $result = $this->export();

        $this->assertSame(155_000, $result->debitTotal);
        $this->assertSame(
            self::ENTITY.'|'.self::OTHER_ENTITY,
            $result->report->scopeLabel(),
        );

        $auditEvent = AuditEvent::query()
            ->where('action', BulkFinancialExport::AUDIT_ACTION)
            ->firstOrFail();

        $this->assertSame(
            'summary:2026-08:'.self::ENTITY.'|'.self::OTHER_ENTITY,
            $auditEvent->subject_id,
        );
    }

    public function test_a_narrowing_entity_ref_within_the_grants_is_honoured(): void
    {
        $this->seedLedger();
        $this->seedLedger(entityRef: self::OTHER_ENTITY, suffix: '-other', amountMinor: 55_000);
        $this->grantLedgerReadAuthority();
        $this->grantLedgerReadAuthority(entityRef: self::OTHER_ENTITY);
        $this->reauthenticateRecently();

        $result = $this->export(entityRef: self::OTHER_ENTITY);

        $this->assertSame(55_000, $result->debitTotal);
        $this->assertSame(self::OTHER_ENTITY, $result->report->scopeLabel());
    }

    public function test_an_unauthorised_actor_is_refused_without_minting_a_reauthentication_challenge(): void
    {
        $this->seedLedger();

        $before = ReauthenticationEvent::query()->count();

        try {
            $this->export();
            $this->fail('An unauthorised export must be refused.');
        } catch (LedgerReadNotAuthorisedException) {
            // Authorization runs before the freshness gate on purpose: an
            // actor who could never have succeeded must not be able to fill
            // `reauthentication_events` with challenge rows.
            $this->assertSame($before, ReauthenticationEvent::query()->count());
        }
    }

    public function test_a_stale_actor_is_refused_and_the_refusal_is_recorded(): void
    {
        $this->seedLedger();
        $this->grantLedgerReadAuthority();
        // No satisfied event — the actor is stale by construction.

        try {
            $this->export();
            $this->fail('A stale export must be refused.');
        } catch (BulkFinancialExportReauthenticationRequiredException $exception) {
            $this->assertStringContainsString(self::ACTOR, $exception->getMessage());
        }

        $challenged = ReauthenticationEvent::query()
            ->where('actor_ref', self::ACTOR)
            ->where('outcome', ReauthenticationOutcome::CHALLENGED)
            ->where('reason', self::REASON)
            ->first();

        $this->assertNotNull($challenged, 'The refusal itself must be recorded as a challenged event.');
        $this->assertDatabaseHas('audit_events', [
            'action' => 'REAUTHENTICATION_CHALLENGED',
            'outcome' => 'denied',
            'actor_ref' => self::ACTOR,
        ]);
    }

    public function test_a_satisfied_event_older_than_the_freshness_window_is_still_stale(): void
    {
        $this->seedLedger();
        $this->grantLedgerReadAuthority();

        ReauthenticationEvent::query()->create([
            'actor_ref' => self::ACTOR,
            'actor_role' => self::ROLE,
            'reason' => self::REASON,
            'outcome' => ReauthenticationOutcome::SATISFIED,
            'ip_address' => '0.0.0.0',
            'occurred_at' => CarbonImmutable::now()->subSeconds((int) config('reauthentication.freshness_seconds') + 1),
        ]);

        $this->expectException(BulkFinancialExportReauthenticationRequiredException::class);

        $this->export();
    }

    public function test_a_satisfied_event_for_another_reason_is_not_accepted(): void
    {
        $this->seedLedger();
        $this->grantLedgerReadAuthority();

        ReauthenticationEvent::query()->create([
            'actor_ref' => self::ACTOR,
            'actor_role' => self::ROLE,
            'reason' => 'payout_approval',
            'outcome' => ReauthenticationOutcome::SATISFIED,
            'ip_address' => '0.0.0.0',
            'occurred_at' => CarbonImmutable::now(),
        ]);

        $this->expectException(BulkFinancialExportReauthenticationRequiredException::class);

        $this->export();
    }

    public function test_an_unknown_kind_is_refused_before_any_gate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->export(kind: 'balance-sheet');
    }

    public function test_a_malformed_period_is_refused_without_recording_a_challenge(): void
    {
        $this->grantLedgerReadAuthority();

        $before = ReauthenticationEvent::query()->count();

        try {
            $this->export(period: '2026-13');
            $this->fail('A malformed period must be refused.');
        } catch (InvalidLedgerReportException) {
            $this->assertTrue(true);
        }

        $this->assertSame($before, ReauthenticationEvent::query()->count());
    }

    public function test_a_padded_period_is_normalised_before_validation_and_audit(): void
    {
        $this->seedLedger();
        $this->grantLedgerReadAuthority();
        $this->reauthenticateRecently();

        $result = $this->export(period: '  2026-08  ');

        $this->assertSame('financial-ledger-summary-2026-08.csv', $result->filename);
        $this->assertSame('2026-08', $result->report->period);
        $this->assertDatabaseHas('audit_events', [
            'action' => BulkFinancialExport::AUDIT_ACTION,
            'subject_id' => 'summary:2026-08:'.self::ENTITY,
        ]);
    }

    // -----------------------------------------------------------------------
    // Route
    // -----------------------------------------------------------------------

    public function test_the_route_redirects_a_stale_actor_to_the_challenge_page(): void
    {
        $user = User::factory()->create();
        // Stale by construction: no `actor_sessions` row, so no
        // `lastAuthenticatedAt`.
        $this->app->instance(ActorContext::class, new ActorContext(
            identityReference: (string) $user->getAuthIdentifier(),
        ));

        $this->actingAs($user)->get(route('admin.finance.exports'))
            ->assertRedirect(route('filament.admin.pages.mfa-challenge'));

        $this->assertDatabaseHas('reauthentication_events', [
            'actor_ref' => (string) $user->getAuthIdentifier(),
            'reason' => self::REASON,
            'outcome' => ReauthenticationOutcome::CHALLENGED,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'REAUTHENTICATION_CHALLENGED',
            'outcome' => 'denied',
            'actor_ref' => (string) $user->getAuthIdentifier(),
        ]);
    }

    /**
     * C1, as a regression test. This is the exact request that used to return
     * 200 and the whole ledger: a bare user with a fresh session, no finance
     * role and no grant.
     */
    public function test_the_route_refuses_a_freshly_logged_in_actor_with_no_finance_authority(): void
    {
        $this->seedLedger();

        $user = $this->freshlyAuthenticatedUser();
        // A real bare account: fresh session, no roles, no grants — exactly
        // what `ActorContext` resolves for any `users` row today.
        $this->app->instance(ActorContext::class, new ActorContext(
            identityReference: (string) $user->getAuthIdentifier(),
            roles: [],
            lastAuthenticatedAt: CarbonImmutable::now(),
        ));

        $this->actingAs($user)
            ->get(route('admin.finance.exports', ['period' => '2026-08']))
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_events', [
            'action' => BulkFinancialExport::AUDIT_ACTION,
        ]);
    }

    /**
     * I1, as a regression test. An authorized actor whose SESSION is fresh but
     * who has never completed a re-authentication challenge for this reason is
     * sent to the challenge page, not handed the file. The controller used to
     * call `ReauthenticationService::satisfy()` unconditionally, which minted
     * the proof the Action then looked for — so this case could not exist.
     */
    public function test_the_route_does_not_fabricate_a_reauthentication_proof(): void
    {
        $this->seedLedger();

        $user = $this->freshlyAuthenticatedUser();
        $this->actAsFinanceActor((string) $user->getAuthIdentifier(), fresh: true);
        $this->grantLedgerReadAuthority(actorRef: (string) $user->getAuthIdentifier());

        $this->actingAs($user)
            ->get(route('admin.finance.exports', ['period' => '2026-08']))
            ->assertRedirect(route('filament.admin.pages.mfa-challenge'));

        $this->assertDatabaseMissing('reauthentication_events', [
            'actor_ref' => (string) $user->getAuthIdentifier(),
            'outcome' => ReauthenticationOutcome::SATISFIED,
        ]);

        $this->assertDatabaseMissing('audit_events', [
            'action' => BulkFinancialExport::AUDIT_ACTION,
        ]);
    }

    public function test_the_route_streams_the_csv_for_an_authorised_reauthenticated_actor(): void
    {
        $this->seedLedger();

        $user = $this->freshlyAuthenticatedUser();
        $actorRef = (string) $user->getAuthIdentifier();

        $this->actAsFinanceActor($actorRef, fresh: true);
        $this->grantLedgerReadAuthority(actorRef: $actorRef);
        $this->reauthenticateRecently($actorRef);

        $response = $this->actingAs($user)
            ->get(route('admin.finance.exports', ['period' => '2026-08']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename=financial-ledger-summary-2026-08.csv');

        $this->assertStringContainsString(
            "\"4000\",\"0\",\"100000\",\"-100000\",\"IDR\",\"2\"\n",
            $response->streamedContent(),
        );

        $this->assertDatabaseHas('audit_events', [
            'action' => BulkFinancialExport::AUDIT_ACTION,
            'actor_ref' => $actorRef,
            'actor_role' => self::ROLE,
            'outcome' => 'allowed',
        ]);
    }

    /**
     * An array-shaped query parameter must never reach the authorizer or the
     * audit trail as the literal string `Array`.
     */
    public function test_an_array_shaped_entity_parameter_is_treated_as_absent(): void
    {
        $this->seedLedger();

        $user = $this->freshlyAuthenticatedUser();
        $actorRef = (string) $user->getAuthIdentifier();

        $this->actAsFinanceActor($actorRef, fresh: true);
        $this->grantLedgerReadAuthority(actorRef: $actorRef);
        $this->reauthenticateRecently($actorRef);

        $this->actingAs($user)
            ->get(route('admin.finance.exports').'?period=2026-08&entity[]=x')
            ->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'action' => BulkFinancialExport::AUDIT_ACTION,
            'subject_id' => 'summary:2026-08:'.self::ENTITY,
        ]);
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    private function export(
        string $kind = 'summary',
        string $period = '2026-08',
        ?string $entityRef = null,
        ?string $correlationId = null,
    ): BulkFinancialExportResult {
        return app(BulkFinancialExport::class)->export(
            kind: $kind,
            period: $period,
            entityRef: $entityRef,
            correlationId: $correlationId,
        );
    }

    /**
     * Binds the server-side `ActorContext` the whole module reads. `$roles` is
     * always `[]` under the real local adapter, so every authorized test must
     * bind one explicitly — which is itself the honest statement that this
     * feature is fail-closed in production until the identity seam lands.
     */
    private function actAsFinanceActor(string $actorRef, bool $fresh = false): void
    {
        $this->app->instance(ActorContext::class, new ActorContext(
            identityReference: $actorRef,
            roles: [self::ROLE],
            lastAuthenticatedAt: $fresh ? CarbonImmutable::now() : null,
        ));
    }

    private function freshlyAuthenticatedUser(): User
    {
        $user = User::factory()->create();

        ActorSession::query()->create([
            'user_id' => $user->getAuthIdentifier(),
            'session_id' => 'test-session-'.$user->getAuthIdentifier(),
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now(),
        ]);

        return $user;
    }

    private function grantLedgerReadAuthority(
        ?string $actorRef = null,
        string $entityRef = self::ENTITY,
        string $grantLevel = ScopeGrantLevel::PRIVILEGED,
        bool $revoked = false,
    ): void {
        ScopeAssignment::query()->create([
            'actor_identifier' => $actorRef ?? self::ACTOR,
            'entity_type' => ScopeEntityType::BUSINESS_ENTITY,
            'entity_id' => $entityRef,
            'grant_level' => $grantLevel,
            'revoked_at' => $revoked ? CarbonImmutable::now() : null,
        ]);
    }

    /**
     * A fresh satisfied event for this module's reason, plus the audit row
     * `satisfy()` itself writes through `Audit::wrap` — the pair a real
     * completed re-authentication produces. Written by the test rather than by
     * the controller: the controller calling `satisfy()` on every request was
     * finding I1.
     */
    private function reauthenticateRecently(?string $actorRef = null): ReauthenticationEvent
    {
        return app(ReauthenticationService::class)->satisfy(
            actorRef: $actorRef ?? self::ACTOR,
            actorRole: self::ROLE,
            reason: self::REASON,
            source: AuditSource::Panel,
        );
    }

    /**
     * A deterministic two-entry ledger in `2026-08`.
     */
    private function seedLedger(
        string $entityRef = self::ENTITY,
        string $suffix = '',
        int $amountMinor = 100_000,
    ): void {
        $this->journal()->post(
            businessKey: 'payment:provider-event-export-1'.$suffix,
            entityRef: $entityRef,
            sourceType: 'payment',
            sourceId: 'provider-event-export-1'.$suffix,
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => $amountMinor],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => $amountMinor],
            ],
            correlationId: 'trace-export-seed'.$suffix,
            occurredAt: '2026-08-10T09:00:00+07:00',
        );
    }

    private function journal(): Journal
    {
        return $this->app->make(Journal::class);
    }
}
