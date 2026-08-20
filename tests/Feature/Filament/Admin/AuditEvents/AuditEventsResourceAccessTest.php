<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\AuditEvents;

use App\Filament\Admin\Resources\AuditEvents\AuditEventsResource;
use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\RoleAuditActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Proves the access boundary on `AuditEventsResource`: only `admin` and
 * `restricted_admin` may reach it, every other role (and a guest) is
 * refused, and the refusal is real — grant rows go through
 * `GrantsActorRoles`, which proves the whole live chain (grant row ->
 * `ActorRoleReader` -> `ActorContext::$roles` -> the authorizer), not a
 * hand-constructed context. See `RoleBasedAuditReadAuthorizer`'s own doc
 * block for why exactly these two roles.
 */
final class AuditEventsResourceAccessTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_a_guest_cannot_access_the_audit_events_resource(): void
    {
        $this->assertFalse(AuditEventsResource::canAccess());
    }

    public function test_an_authenticated_actor_with_no_role_cannot_access_the_audit_events_resource(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse(AuditEventsResource::canAccess());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rolesWithoutAuditAccess(): iterable
    {
        yield 'finance' => [ActorRole::FINANCE];
        yield 'operator' => [ActorRole::OPERATOR];
        yield 'case_manager' => [ActorRole::CASE_MANAGER];
        yield 'vendor' => [ActorRole::VENDOR];
        yield 'customer' => [ActorRole::CUSTOMER];
    }

    #[DataProvider('rolesWithoutAuditAccess')]
    public function test_a_role_without_audit_authority_cannot_access_the_resource(string $role): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, $role);
        $this->actingAs($user);

        $this->assertFalse(AuditEventsResource::canAccess(), "Expected role [{$role}] to be refused audit access.");
    }

    public function test_admin_and_restricted_admin_can_access_the_resource(): void
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(AuditEventsResource::canAccess(), "Expected role [{$role}] to access the audit events resource.");

            $this->forgetResolvedActorContext();
        }
    }

    public function test_a_guest_is_redirected_away_from_the_audit_events_list_page(): void
    {
        $response = $this->get(AuditEventsResource::getUrl('index'));

        $response->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_an_admin_can_open_the_audit_events_list_page_over_http(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);

        $response = $this->actingAs($user)->get(AuditEventsResource::getUrl('index'));

        $response->assertOk();
    }

    public function test_a_customer_gets_forbidden_over_http(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CUSTOMER);

        $response = $this->actingAs($user)->get(AuditEventsResource::getUrl('index'));

        $response->assertForbidden();
    }

    /**
     * `getEloquentQuery()` fails closed on its own — proven directly rather
     * than only through `canAccess()` — because it is the method Filament
     * calls to build every list/view query, and a caller reaching it any
     * other way must not silently fall through to an unfiltered query.
     */
    public function test_get_eloquent_query_aborts_for_an_unauthorised_actor(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        try {
            AuditEventsResource::getEloquentQuery();
            $this->fail('An unauthorised actor must not reach an unfiltered query.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_restricted_admin_sees_a_scoped_query_and_admin_sees_everything(): void
    {
        $ordinary = Audit::record(
            action: 'booking.updated',
            subject: new AuditSubject(type: 'booking', id: 1),
            outcome: AuditOutcome::Allowed,
            actorRef: 1,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $privilegeEscalation = Audit::record(
            action: RoleAuditActions::GRANT,
            subject: new AuditSubject(type: 'actor_role_assignment', id: 1),
            outcome: AuditOutcome::Allowed,
            actorRef: 1,
            actorRole: 'admin',
            source: AuditSource::Console,
            reason: 'Test fixture: seeding a privilege-escalation audit row.',
        );

        $admin = User::factory()->create();
        $this->grantRoleTo($admin, ActorRole::ADMIN);
        $this->actingAs($admin);

        $adminIds = AuditEventsResource::getEloquentQuery()->pluck('id')->all();
        $this->assertContains($ordinary->id, $adminIds);
        $this->assertContains($privilegeEscalation->id, $adminIds);

        $this->forgetResolvedActorContext();

        $restrictedAdmin = User::factory()->create();
        $this->grantRoleTo($restrictedAdmin, ActorRole::RESTRICTED_ADMIN);
        $this->actingAs($restrictedAdmin);

        $restrictedIds = AuditEventsResource::getEloquentQuery()->pluck('id')->all();
        $this->assertContains($ordinary->id, $restrictedIds);
        $this->assertNotContains($privilegeEscalation->id, $restrictedIds);
    }

    public function test_the_resource_offers_no_create_or_edit_page(): void
    {
        $pages = AuditEventsResource::getPages();

        $this->assertArrayHasKey('index', $pages);
        $this->assertArrayHasKey('view', $pages);
        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }
}
