<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Models\User;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FinancialLedger\Actions\BulkFinancialExport;
use App\Platform\FinancialLedger\Exceptions\BulkFinancialExportReauthenticationRequiredException;
use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;
use App\Platform\FinancialLedger\Journal;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * AC13: the bulk financial export — recent re-authentication required,
 * deterministic CSV, and an audit row per export. Three layers are exercised:
 * the Action (domain gate + bytes + audit), the malformed/stale refusal paths,
 * and the real route (middleware freshness → challenge redirect, then a fresh
 * session streaming the CSV). The export must also be reproducible: same
 * ledger + period + kind → identical bytes, which the byte-exact assertions
 * below pin.
 */
final class BulkFinancialExportTest extends TestCase
{
    use RefreshDatabase;

    private const string REASON = 'bulk_financial_export';

    public function test_export_records_an_audit_row_and_returns_deterministic_csv(): void
    {
        $this->seedLedger();

        $this->reauthenticateRecently(actorRef: 'admin-1');

        $result = app(BulkFinancialExport::class)->export(
            kind: 'summary',
            period: '2026-08',
            actorRef: 'admin-1',
            actorRole: 'authenticated_actor',
            correlationId: 'trace-export-1',
        );

        $this->assertSame('financial-ledger-summary-2026-08.csv', $result->filename);
        $this->assertSame('text/csv; charset=UTF-8', $result->mimeType);
        $this->assertSame(100_000, $result->debitTotal);
        $this->assertSame(100_000, $result->creditTotal);

        $this->assertSame(
            "account_code,debit_total,credit_total,net,currency\n".
            "4000,0,100000,-100000,IDR\n".
            "7000,100000,0,100000,IDR\n".
            "TOTAL,100000,100000,0,IDR\n",
            $result->contents,
        );

        $auditEvent = AuditEvent::query()
            ->where('action', BulkFinancialExport::AUDIT_ACTION)
            ->firstOrFail();

        $this->assertSame('admin-1', $auditEvent->actor_ref);
        $this->assertSame('financial_ledger_report', $auditEvent->subject_type);
        $this->assertSame('summary:2026-08:all', $auditEvent->subject_id);
        $this->assertSame(
            ['note' => 'summary:2026-08:all'],
            $auditEvent->metadata,
        );
        $this->assertSame('trace-export-1', $auditEvent->correlation_id);
    }

    public function test_an_entity_scoped_export_is_audited_with_the_entity_scope(): void
    {
        $this->seedLedger();
        $this->reauthenticateRecently(actorRef: 'admin-1');

        $result = app(BulkFinancialExport::class)->export(
            kind: 'summary',
            period: '2026-08',
            entityRef: 'badan-usaha-1',
            actorRef: 'admin-1',
            actorRole: 'authenticated_actor',
        );

        $auditEvent = AuditEvent::query()
            ->where('action', BulkFinancialExport::AUDIT_ACTION)
            ->firstOrFail();

        $this->assertSame('summary:2026-08:badan-usaha-1', $auditEvent->subject_id);
        $this->assertSame(['note' => 'summary:2026-08:badan-usaha-1'], $auditEvent->metadata);

        $this->assertSame(
            "account_code,debit_total,credit_total,net,currency\n".
            "4000,0,100000,-100000,IDR\n".
            "7000,100000,0,100000,IDR\n".
            "TOTAL,100000,100000,0,IDR\n",
            $result->contents,
        );
    }

    public function test_a_stale_actor_is_refused_and_the_refusal_is_recorded(): void
    {
        $this->seedLedger();
        // No satisfied event — the actor is stale by construction.

        try {
            app(BulkFinancialExport::class)->export(
                kind: 'summary',
                period: '2026-08',
                actorRef: 'admin-1',
                actorRole: 'authenticated_actor',
            );
            $this->fail('A stale export must be refused.');
        } catch (BulkFinancialExportReauthenticationRequiredException $exception) {
            $this->assertStringContainsString('admin-1', $exception->getMessage());
        }

        $challenged = ReauthenticationEvent::query()
            ->where('actor_ref', 'admin-1')
            ->where('outcome', ReauthenticationOutcome::CHALLENGED)
            ->where('reason', self::REASON)
            ->first();

        $this->assertNotNull($challenged, 'The refusal itself must be recorded as a challenged event.');
        $this->assertDatabaseHas('audit_events', [
            'action' => 'REAUTHENTICATION_CHALLENGED',
            'outcome' => 'denied',
            'actor_ref' => 'admin-1',
        ]);
    }

    public function test_a_satisfied_event_older_than_the_freshness_window_is_still_stale(): void
    {
        $this->seedLedger();

        ReauthenticationEvent::query()->create([
            'actor_ref' => 'admin-1',
            'actor_role' => 'authenticated_actor',
            'reason' => self::REASON,
            'outcome' => ReauthenticationOutcome::SATISFIED,
            'ip_address' => '0.0.0.0',
            'occurred_at' => CarbonImmutable::now()->subSeconds((int) config('reauthentication.freshness_seconds') + 1),
        ]);

        $this->expectException(BulkFinancialExportReauthenticationRequiredException::class);

        app(BulkFinancialExport::class)->export(
            kind: 'summary',
            period: '2026-08',
            actorRef: 'admin-1',
            actorRole: 'authenticated_actor',
        );
    }

    public function test_a_satisfied_event_for_another_reason_is_not_accepted(): void
    {
        $this->seedLedger();

        ReauthenticationEvent::query()->create([
            'actor_ref' => 'admin-1',
            'actor_role' => 'authenticated_actor',
            'reason' => 'payout_approval',
            'outcome' => ReauthenticationOutcome::SATISFIED,
            'ip_address' => '0.0.0.0',
            'occurred_at' => CarbonImmutable::now(),
        ]);

        $this->expectException(BulkFinancialExportReauthenticationRequiredException::class);

        app(BulkFinancialExport::class)->export(
            kind: 'summary',
            period: '2026-08',
            actorRef: 'admin-1',
            actorRole: 'authenticated_actor',
        );
    }

    public function test_an_unknown_kind_is_refused_before_any_gate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(BulkFinancialExport::class)->export(
            kind: 'balance-sheet',
            period: '2026-08',
            actorRef: 'admin-1',
            actorRole: 'authenticated_actor',
        );
    }

    public function test_a_malformed_period_is_refused_without_recording_a_challenge(): void
    {
        $before = ReauthenticationEvent::query()->count();

        try {
            app(BulkFinancialExport::class)->export(
                kind: 'summary',
                period: '2026-13',
                actorRef: 'admin-1',
                actorRole: 'authenticated_actor',
            );
            $this->fail('A malformed period must be refused.');
        } catch (InvalidLedgerReportException) {
            $this->assertTrue(true);
        }

        $this->assertSame($before, ReauthenticationEvent::query()->count());
    }

    public function test_the_route_redirects_a_stale_actor_to_the_challenge_page(): void
    {
        $user = User::factory()->create();

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

    public function test_the_route_streams_the_csv_for_a_fresh_actor(): void
    {
        $this->seedLedger();

        $user = User::factory()->create();
        ActorSession::query()->create([
            'user_id' => $user->getAuthIdentifier(),
            'session_id' => 'test-session-'.$user->getAuthIdentifier(),
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.finance.exports', ['period' => '2026-08']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename=financial-ledger-summary-2026-08.csv');

        $this->assertStringContainsString(
            "account_code,debit_total,credit_total,net,currency\n4000,0,100000,-100000,IDR\n7000,100000,0,100000,IDR\nTOTAL,100000,100000,0,IDR\n",
            $response->streamedContent(),
        );

        $this->assertDatabaseHas('audit_events', [
            'action' => BulkFinancialExport::AUDIT_ACTION,
            'actor_ref' => (string) $user->getAuthIdentifier(),
            'outcome' => 'allowed',
        ]);
    }

    /**
     * A fresh satisfied event for this module's reason, plus the audit row
     * that `satisfy()` itself writes through `Audit::wrap` — the exact pair a
     * real `MfaChallenge` completion produces.
     */
    private function reauthenticateRecently(int|string $actorRef): ReauthenticationEvent
    {
        return app(ReauthenticationService::class)->satisfy(
            actorRef: $actorRef,
            actorRole: 'authenticated_actor',
            reason: self::REASON,
            source: AuditSource::Panel,
        );
    }

    /**
     * A deterministic two-entry ledger in `2026-08` for `badan-usaha-1`.
     */
    private function seedLedger(): void
    {
        $this->journal()->post(
            businessKey: 'payment:provider-event-export-1',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'provider-event-export-1',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 100_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 100_000],
            ],
            correlationId: 'trace-export-seed',
            occurredAt: '2026-08-10T09:00:00+07:00',
        );
    }

    private function journal(): Journal
    {
        return $this->app->make(Journal::class);
    }
}
