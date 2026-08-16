<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Memorial\Actions\CreateMemorialProfile;
use App\Domain\Memorial\Actions\GrantMemorialEditor;
use App\Domain\Memorial\Actions\ReportMemorialContent;
use App\Domain\Memorial\Actions\SubmitMemorialContent;
use App\Domain\Memorial\Exceptions\MemorialConsentMissingException;
use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\MemorialModerationState;
use App\Domain\Memorial\MemorialPrivacyMode;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Domain\Memorial\Models\MemorialQrToken;
use App\Domain\Memorial\Models\ModerationCase;
use App\Filament\Admin\Resources\MemorialProfiles\MemorialProfileResource;
use App\Filament\Admin\Resources\MemorialProfiles\Pages\ViewMemorialProfile;
use App\Filament\Admin\Resources\MemorialProfiles\RelationManagers\ContentsRelationManager;
use App\Filament\Admin\Resources\MemorialProfiles\RelationManagers\EditorsRelationManager;
use App\Filament\Admin\Resources\MemorialProfiles\RelationManagers\QrTokensRelationManager;
use App\Filament\Admin\Resources\ModerationCases\ModerationCaseResource;
use App\Filament\Admin\Resources\ModerationCases\Pages\ViewModerationCase;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The two Task-4 admin surfaces — `MemorialProfileResource` and
 * `ModerationCaseResource` (`.kiro/specs/memorial-and-qr/requirements.md`
 * AC1 consent-gated editors, AC5 moderator unpublish + token rotation,
 * AC6 moderation queue; the plan's Task 4 brief).
 *
 * 1. Access matrix for both resources: the four back-office roles pass, a
 *    bare customer and a vendor fail closed.
 * 2. Publish/unpublish are MODERATOR-BACKED and role-gated (the Lane-3
 *    review watch): finance may view but never publish.
 * 3. Editor grants require consent evidence (AC1): a grant without a
 *    `consent_evidence_ref` is refused.
 * 4. The moderation flow: pending → approved with audit + outbox rows.
 * 5. QR issuance/rotation through the relation manager with the SVG image
 *    rendering.
 * 6. Cemetery scoping (the visitation pattern): an operator holding
 *    cemetery grants sees only their cemeteries' profiles and cases.
 */
final class MemorialAdminTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        return $user;
    }

    private function cemetery(): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
    }

    private function grave(Cemetery $cemetery): GraveRecord
    {
        return GraveRecord::factory()->create(['cemetery_id' => $cemetery->getKey()]);
    }

    private function profile(Cemetery $cemetery, string $privacyMode = MemorialPrivacyMode::DEFAULT): MemorialProfile
    {
        return app(CreateMemorialProfile::class)($this->grave($cemetery), 'user:1', 'operator', $privacyMode);
    }

    // =====================================================================
    // Access matrix (both resources)
    // =====================================================================

    public function test_both_memorial_resources_fail_closed_outside_the_back_office_roles(): void
    {
        $this->assertFalse(MemorialProfileResource::canAccess());
        $this->assertFalse(ModerationCaseResource::canAccess());
        $this->actingAs(User::factory()->create());
        $this->forgetResolvedActorContext();
        $this->assertFalse(MemorialProfileResource::canAccess());

        foreach ([
            ActorRole::ADMIN,
            ActorRole::RESTRICTED_ADMIN,
            ActorRole::OPERATOR,
            ActorRole::FINANCE,
        ] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(
                MemorialProfileResource::canAccess(),
                "Expected role [{$role}] to access the memorial profiles resource.",
            );
            $this->assertTrue(
                ModerationCaseResource::canAccess(),
                "Expected role [{$role}] to access the moderation cases resource.",
            );
            $this->forgetResolvedActorContext();
        }

        $vendor = User::factory()->create();
        $this->grantRoleTo($vendor, ActorRole::VENDOR);
        $this->actingAs($vendor);

        $this->assertFalse(MemorialProfileResource::canAccess());
        $this->assertFalse(ModerationCaseResource::canAccess());
    }

    // =====================================================================
    // Publish/unpublish — moderator-backed, role-gated (review watch)
    // =====================================================================

    /**
     * Publish requires a moderation-capable role: finance (which may VIEW
     * the resource) must be refused the publish header action.
     */
    public function test_publish_is_role_gated_so_finance_cannot_publish(): void
    {
        $cemetery = $this->cemetery();
        $profile = $this->profile($cemetery);

        $finance = User::factory()->create();
        $this->grantRoleTo($finance, ActorRole::FINANCE);
        $this->actingAs($finance);
        $this->forgetResolvedActorContext();

        Livewire::test(ViewMemorialProfile::class, ['record' => $profile->getKey()])
            ->assertOk()
            ->assertActionHidden('publish')
            ->assertActionHidden('unpublish');

        $this->assertNull($profile->fresh()->published_at);
    }

    /**
     * An operator is a moderator for publish: the action runs
     * `PublishMemorial`, sets `published_at`, and writes the audit row.
     */
    public function test_an_operator_publishes_with_audit(): void
    {
        $cemetery = $this->cemetery();
        $profile = $this->profile($cemetery);

        $operator = User::factory()->create();
        $this->grantRoleTo($operator, ActorRole::OPERATOR);
        $this->actingAs($operator);
        $this->forgetResolvedActorContext();

        Livewire::test(ViewMemorialProfile::class, ['record' => $profile->getKey()])
            ->callAction('publish')
            ->assertHasNoActionErrors();

        $this->assertNotNull($profile->fresh()->published_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => MemorialAuditActions::MEMORIAL_PUBLISHED,
            'subject_id' => $profile->getKey(),
        ]);
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'memorial.published.v1']);

        // And unpublish is immediate (AC5).
        Livewire::test(ViewMemorialProfile::class, ['record' => $profile->getKey()])
            ->callAction('unpublish')
            ->assertHasNoActionErrors();

        $this->assertNull($profile->fresh()->published_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => MemorialAuditActions::MEMORIAL_UNPUBLISHED,
            'subject_id' => $profile->getKey(),
        ]);
    }

    // =====================================================================
    // Editors — consent evidence required (AC1)
    // =====================================================================

    /**
     * A grant without consent evidence is refused before any row is
     * written (`MemorialConsentMissingException` — the review watch:
     * consent is REQUIRED).
     */
    public function test_editor_grant_without_consent_evidence_is_refused(): void
    {
        $cemetery = $this->cemetery();
        $profile = $this->profile($cemetery);
        $admin = $this->admin();
        $this->forgetResolvedActorContext();

        Livewire::test(EditorsRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => ViewMemorialProfile::class,
        ])
            ->callTableAction('add', data: [
                'actor_id' => (string) $admin->id,
                'consent_evidence_ref' => '',
            ])
            ->assertHasTableActionErrors(['consent_evidence_ref' => ['required']]);

        $this->assertDatabaseMissing('memorial_editors', ['memorial_profile_id' => $profile->getKey()]);
    }

    /**
     * The grant action itself also refuses a blank evidence reference
     * (defense in depth beyond the form rule).
     */
    public function test_grant_action_refuses_a_blank_evidence_reference(): void
    {
        $cemetery = $this->cemetery();
        $profile = $this->profile($cemetery);

        try {
            app(GrantMemorialEditor::class)($profile, 1, '', 'admin:1', 'admin');
            $this->fail('A blank consent evidence reference must be refused.');
        } catch (MemorialConsentMissingException) {
            // expected
        }
    }

    /**
     * A grant WITH consent evidence lands the editor row plus the audit
     * trail pointing at the evidence.
     */
    public function test_editor_grant_with_consent_evidence_succeeds_and_audits(): void
    {
        $cemetery = $this->cemetery();
        $profile = $this->profile($cemetery);
        $admin = $this->admin();
        $this->forgetResolvedActorContext();

        $evidenceRef = (string) Str::uuid();

        Livewire::test(EditorsRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => ViewMemorialProfile::class,
        ])
            ->callTableAction('add', data: [
                'actor_id' => (string) $admin->id,
                'consent_evidence_ref' => $evidenceRef,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('memorial_editors', [
            'memorial_profile_id' => $profile->getKey(),
            'actor_id' => (string) $admin->id,
            'consent_evidence_ref' => $evidenceRef,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => MemorialAuditActions::MEMORIAL_EDITOR_GRANTED]);
    }

    // =====================================================================
    // Moderation flow (AC6)
    // =====================================================================

    /**
     * Pending content moves to approved via the relation-manager action,
     * with the audit + outbox pair in the same transaction.
     */
    public function test_content_moderation_flow_approves_and_records_audit_and_outbox(): void
    {
        $cemetery = $this->cemetery();
        $profile = $this->profile($cemetery);
        $content = app(SubmitMemorialContent::class)($profile, 'Kenangan indah', 'family:1', 'family');
        $this->admin();
        $this->forgetResolvedActorContext();

        Livewire::test(ContentsRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => ViewMemorialProfile::class,
        ])
            ->callTableAction('approve', $content->getKey())
            ->assertHasNoTableActionErrors();

        $this->assertSame(
            MemorialModerationState::APPROVED->value,
            $content->fresh()->moderation_state,
        );
        $this->assertDatabaseHas('audit_events', [
            'action' => MemorialAuditActions::MEMORIAL_CONTENT_MODERATED,
            'subject_id' => $content->getKey(),
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'memorial.content_moderated.v1',
            'aggregate_id' => $content->getKey(),
        ]);
    }

    // =====================================================================
    // QR — issuance/rotation + the SVG image renders
    // =====================================================================

    /**
     * The QR relation manager renders the SVG for the active token, and the
     * rotate action revokes the incumbent and mints a new one.
     */
    public function test_qr_issuance_and_rotation_render_the_svg(): void
    {
        $cemetery = $this->cemetery();
        $profile = $this->profile($cemetery);
        $this->admin();
        $this->forgetResolvedActorContext();

        $first = MemorialQrToken::issueFor($profile);

        $component = Livewire::test(QrTokensRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => ViewMemorialProfile::class,
        ])
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee($first->token);

        $component->callTableAction('rotate')
            ->assertHasNoTableActionErrors();

        $this->assertNotNull($first->fresh()->revoked_at, 'Rotation must revoke the incumbent token.');
        $second = MemorialQrToken::activeFor($profile);
        $this->assertNotNull($second);
        $this->assertNotSame($first->token, $second->token);
        $this->assertDatabaseHas('audit_events', ['action' => MemorialAuditActions::MEMORIAL_QR_ROTATED]);

        Livewire::test(QrTokensRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => ViewMemorialProfile::class,
        ])
            ->assertSee('<svg', false)
            ->assertSee($second->token);
    }

    // =====================================================================
    // Moderation cases — resolve/dismiss with reason + audit
    // =====================================================================

    public function test_moderation_case_resolves_with_reason_and_audit(): void
    {
        $cemetery = $this->cemetery();
        $profile = $this->profile($cemetery);
        $content = app(SubmitMemorialContent::class)($profile, 'Lapor ini', 'family:1', 'family');
        $case = app(ReportMemorialContent::class)($profile, 'memorial_content', $content->getKey(), 'reporter:1', 'customer', 'Konten tidak pantas.');
        $this->admin();
        $this->forgetResolvedActorContext();

        Livewire::test(ViewModerationCase::class, ['record' => $case->getKey()])
            ->callAction('resolve', data: ['reason' => 'Ditinjau dan disetujui.'])
            ->assertHasNoActionErrors();

        $this->assertSame(ModerationCase::STATUS_RESOLVED, $case->fresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'action' => MemorialAuditActions::MEMORIAL_MODERATION_CASE_RESOLVED,
            'subject_id' => $case->getKey(),
        ]);
    }

    /**
     * Dismissal also requires a reason and records the dismissal audit row.
     */
    public function test_moderation_case_dismissal_requires_a_reason(): void
    {
        $cemetery = $this->cemetery();
        $profile = $this->profile($cemetery);
        $content = app(SubmitMemorialContent::class)($profile, 'Lapor ini', 'family:1', 'family');
        $case = app(ReportMemorialContent::class)($profile, 'memorial_content', $content->getKey(), 'reporter:1', 'customer', 'Laporan tes.');
        $this->admin();
        $this->forgetResolvedActorContext();

        Livewire::test(ViewModerationCase::class, ['record' => $case->getKey()])
            ->callAction('dismiss', data: ['reason' => 'Tidak ditemukan pelanggaran.'])
            ->assertHasNoActionErrors();

        $this->assertSame(ModerationCase::STATUS_DISMISSED, $case->fresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'action' => MemorialAuditActions::MEMORIAL_MODERATION_CASE_DISMISSED,
            'subject_id' => $case->getKey(),
        ]);
    }

    // =====================================================================
    // Cemetery scoping (the visitation pattern)
    // =====================================================================

    /**
     * An operator holding cemetery grants sees only their cemeteries'
     * profiles and cases; an admin sees everything.
     */
    public function test_cemetery_scoping_limits_an_operator_to_their_grants(): void
    {
        $firstCemetery = $this->cemetery();
        $secondCemetery = $this->cemetery();
        $profileA = $this->profile($firstCemetery);
        $profileB = $this->profile($secondCemetery);
        $contentA = app(SubmitMemorialContent::class)($profileA, 'Konten A', 'family:1', 'family');
        $caseA = app(ReportMemorialContent::class)($profileA, 'memorial_content', $contentA->getKey(), 'reporter:1', 'customer', 'Laporan A.');
        $contentB = app(SubmitMemorialContent::class)($profileB, 'Konten B', 'family:1', 'family');
        $caseB = app(ReportMemorialContent::class)($profileB, 'memorial_content', $contentB->getKey(), 'reporter:1', 'customer', 'Laporan B.');

        $operator = User::factory()->create();
        $this->grantRoleTo($operator, ActorRole::OPERATOR);
        app(GrantScopeAssignment::class)(
            $operator->id,
            ScopeEntityType::CEMETERY,
            $firstCemetery->getKey(),
            null,
            'Test fixture: exercising cemetery-scoped moderation visibility.',
            null,
        );
        $this->actingAs($operator);
        $this->forgetResolvedActorContext();

        $visibleProfiles = MemorialProfileResource::getEloquentQuery()->pluck('id')->all();
        $this->assertContains($profileA->getKey(), $visibleProfiles);
        $this->assertNotContains($profileB->getKey(), $visibleProfiles);

        $visibleCases = ModerationCaseResource::getEloquentQuery()->pluck('id')->all();
        $this->assertContains($caseA->getKey(), $visibleCases);
        $this->assertNotContains($caseB->getKey(), $visibleCases);
    }
}
