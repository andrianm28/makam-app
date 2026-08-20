<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\Reconciliations\Actions\UploadProviderStatementAction;
use App\Filament\Admin\Resources\Reconciliations\Pages\ListReconciliations;
use App\Filament\Admin\Resources\Reconciliations\ReconciliationsResource;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FinancialLedger\FinanceReconciliationAuthorizer;
use App\Platform\FinancialLedger\Jobs\ReconcileStatementJob;
use App\Platform\FinancialLedger\ProviderStatement;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The admin `ReconciliationsResource` — the missing half of the
 * reconciliation pipeline this batch built: uploading a settlement CSV,
 * parsing it into a `ProviderStatement`, and dispatching
 * `Jobs\ReconcileStatementJob`. `RunReconciliation`/`ReconcileStatementJob`
 * themselves are untouched and already covered by
 * `tests/Feature/FinancialLedger/RunReconciliationTest.php`; this file
 * proves the surface that feeds them.
 */
final class ReconciliationAdminTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private const string ENTITY = 'badan-usaha-1';

    private const string PERIOD = '2026-08';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function csv(string $contents = "line_reference,amount\ntrx-00001,150000.00\ntrx-00002,275500\n"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('statement.csv', $contents);
    }

    private function authorisedFinanceUser(string $entityRef = self::ENTITY): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->grant($user, $entityRef);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        return $user;
    }

    private function grant(User $user, string $entityRef = self::ENTITY, bool $revoked = false): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->getAuthIdentifier(),
            'entity_type' => ScopeEntityType::BUSINESS_ENTITY,
            'entity_id' => $entityRef,
            'grant_level' => ScopeGrantLevel::PRIVILEGED,
            'revoked_at' => $revoked ? now() : null,
        ]);
    }

    // =========================================================================
    // Access gate
    // =========================================================================

    public function test_a_guest_cannot_access_the_resource(): void
    {
        $this->assertFalse(ReconciliationsResource::canAccess());
    }

    public function test_a_finance_actor_without_any_business_entity_grant_cannot_access_the_resource(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $this->assertFalse(ReconciliationsResource::canAccess());
    }

    public function test_a_finance_actor_with_a_grant_can_access_the_resource_and_see_the_upload_action(): void
    {
        $this->authorisedFinanceUser();

        $this->assertTrue(ReconciliationsResource::canAccess());

        Livewire::test(ListReconciliations::class)
            ->assertOk()
            ->assertActionVisible('unggah_pernyataan');
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function test_an_authorised_upload_dispatches_the_reconcile_job_and_writes_an_audit_row(): void
    {
        Queue::fake();
        $this->authorisedFinanceUser();

        Livewire::test(ListReconciliations::class)
            ->callAction('unggah_pernyataan', data: [
                'statement_reference' => 'stmt-2026-08-001',
                'period' => self::PERIOD,
                'entity_ref' => self::ENTITY,
                'statement_file' => $this->csv(),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Pernyataan diunggah.');

        Queue::assertPushedOn('reports', ReconcileStatementJob::class);
        Queue::assertPushed(function (ReconcileStatementJob $job): bool {
            return $job->period === self::PERIOD
                && $job->entityRef === self::ENTITY
                && $job->statement instanceof ProviderStatement
                && $job->statement->reference === 'stmt-2026-08-001'
                && $job->statement->lines() === [
                    'trx-00001' => 15_000_000,
                    'trx-00002' => 27_550_000,
                ];
        });

        $this->assertDatabaseHas('audit_events', [
            'action' => UploadProviderStatementAction::AUDIT_ACTION,
            'subject_type' => 'reconciliation_statement_upload',
            'subject_id' => self::ENTITY.':'.self::PERIOD,
            'actor_role' => FinanceReconciliationAuthorizer::FINANCE_ROLE,
            'outcome' => 'allowed',
        ]);

        $event = AuditEvent::query()->where('action', UploadProviderStatementAction::AUDIT_ACTION)->sole();
        $this->assertSame('stmt-2026-08-001', $event->metadata['reference_number']);
    }

    // =========================================================================
    // Refusal paths — every one honest, none a 500, none a queued job
    // =========================================================================

    public function test_an_actor_without_a_grant_on_the_named_entity_is_refused(): void
    {
        Queue::fake();
        // Granted for a DIFFERENT badan usaha than the one named in the form.
        $this->authorisedFinanceUser('other-badan-usaha');

        Livewire::test(ListReconciliations::class)
            ->callAction('unggah_pernyataan', data: [
                'statement_reference' => 'stmt-1',
                'period' => self::PERIOD,
                'entity_ref' => self::ENTITY,
                'statement_file' => $this->csv(),
            ]);

        Queue::assertNotPushed(ReconcileStatementJob::class);
        // Setup itself audits `ROLE_GRANT` (a sensitive action), so this
        // asserts no row for THIS action specifically, not an empty table.
        $this->assertSame(
            0,
            AuditEvent::query()->where('action', UploadProviderStatementAction::AUDIT_ACTION)->count(),
        );
    }

    public function test_a_malformed_period_is_refused_before_dispatch(): void
    {
        Queue::fake();
        $this->authorisedFinanceUser();

        Livewire::test(ListReconciliations::class)
            ->callAction('unggah_pernyataan', data: [
                'statement_reference' => 'stmt-1',
                'period' => '2026-13',
                'entity_ref' => self::ENTITY,
                'statement_file' => $this->csv(),
            ]);

        Queue::assertNotPushed(ReconcileStatementJob::class);
    }

    public function test_a_csv_with_an_unexpected_header_is_refused_with_an_honest_notification(): void
    {
        Queue::fake();
        $this->authorisedFinanceUser();

        Livewire::test(ListReconciliations::class)
            ->callAction('unggah_pernyataan', data: [
                'statement_reference' => 'stmt-1',
                'period' => self::PERIOD,
                'entity_ref' => self::ENTITY,
                'statement_file' => $this->csv("reference,amount\ntrx-1,100\n"),
            ])
            ->assertNotified();

        Queue::assertNotPushed(ReconcileStatementJob::class);
    }

    public function test_a_csv_with_a_duplicate_reference_is_refused(): void
    {
        Queue::fake();
        $this->authorisedFinanceUser();

        Livewire::test(ListReconciliations::class)
            ->callAction('unggah_pernyataan', data: [
                'statement_reference' => 'stmt-1',
                'period' => self::PERIOD,
                'entity_ref' => self::ENTITY,
                'statement_file' => $this->csv("line_reference,amount\ntrx-1,100\ntrx-1,200\n"),
            ])
            ->assertNotified();

        Queue::assertNotPushed(ReconcileStatementJob::class);
    }

    /**
     * Filament's `TextInput` trims before validating, so a whitespace-only
     * reference is caught by the field's own `required()` rule as an inline
     * action error — it never reaches `run()`, let alone `ProviderStatement`'s
     * own constructor check for the same rule.
     */
    public function test_a_blank_statement_reference_is_refused_inline_by_the_forms_own_required_rule(): void
    {
        Queue::fake();
        $this->authorisedFinanceUser();

        Livewire::test(ListReconciliations::class)
            ->callAction('unggah_pernyataan', data: [
                'statement_reference' => '   ',
                'period' => self::PERIOD,
                'entity_ref' => self::ENTITY,
                'statement_file' => $this->csv(),
            ])
            ->assertHasActionErrors(['statement_reference' => ['required']]);

        Queue::assertNotPushed(ReconcileStatementJob::class);
    }

    /**
     * The actor keeps a SECOND, unrelated grant so page-level access
     * (`Contracts\LedgerReadAuthorizer`'s coarse "any active grant" check)
     * survives the revoke — isolating this test to the action's own
     * entity-specific re-check via `Contracts\ReconciliationAuthorizer`,
     * the same "mid-session revoke" race
     * `CertificateAdminTest::test_the_issue_action_is_refused_after_the_issuer_role_is_revoked_mid_session`
     * proves for the certificate issuer gate.
     */
    public function test_a_revoked_entity_grant_refuses_a_previously_authorised_actor_mid_session(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->grant($user, self::ENTITY);
        $this->grant($user, 'other-badan-usaha-keeps-page-access');
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $component = Livewire::test(ListReconciliations::class)->assertOk();

        ScopeAssignment::query()
            ->where('actor_identifier', (string) $user->getAuthIdentifier())
            ->where('entity_id', self::ENTITY)
            ->update(['revoked_at' => now()]);
        $this->forgetResolvedActorContext();

        $component->callAction('unggah_pernyataan', data: [
            'statement_reference' => 'stmt-1',
            'period' => self::PERIOD,
            'entity_ref' => self::ENTITY,
            'statement_file' => $this->csv(),
        ]);

        Queue::assertNotPushed(ReconcileStatementJob::class);
    }
}
