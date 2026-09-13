<?php

declare(strict_types=1);

namespace Tests\Feature\Renewal;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\ExpireRenewal;
use App\Domain\Renewal\Actions\MarkRenewalPaidExternally;
use App\Domain\Renewal\Exceptions\RenewalAlreadySettledException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalStatus;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminRenewalActionsTest extends TestCase
{
    use RefreshDatabase;

    private function renewal(string $status): Renewal
    {
        $grave = GraveRecord::factory()->create();

        return Renewal::query()->create([
            'grave_record_id' => $grave->getKey(),
            'target_due_period' => '2026-12-01',
            'reference' => 'EXT-'.strtoupper(substr(uniqid(), 0, 8)),
            'status' => $status,
            'source' => 'online',
        ]);
    }

    private function grantRole(User $user, string $role): void
    {
        ActorRoleAssignment::create([
            'actor_identifier' => (string) $user->getAuthIdentifier(),
            'role' => $role,
        ]);
    }

    private function grantCemeteryScope(User $user, string $cemeteryId): void
    {
        ScopeAssignment::create([
            'actor_identifier' => (string) $user->getAuthIdentifier(),
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => $cemeteryId,
            'grant_level' => ScopeGrantLevel::PRIVILEGED,
        ]);
    }

    /**
     * A fully authorized admin — role AND a privileged cemetery-scope grant
     * for the renewal's own grave's cemetery — the same shape
     * `RenewalMarkingPolicy` requires on the CREATE path
     * (`MarkExternalRenewal`). AUTHZ-04 moved this SETTLE path onto the
     * exact same policy, so a test exercising it now needs a real,
     * authenticated, fully-granted actor rather than an arbitrary
     * `actorRef`/`actorRole` string pair.
     */
    private function fullyAuthorizedAdminFor(Renewal $renewal): User
    {
        $user = User::factory()->create();
        $this->grantRole($user, ActorRole::ADMIN);
        $this->grantCemeteryScope($user, (string) $renewal->graveRecord->cemetery_id);
        $this->actingAs($user);

        return $user;
    }

    public function test_mark_paid_externally_records_evidence(): void
    {
        $renewal = $this->renewal(RenewalStatus::MENUNGGU_PEMBAYARAN);
        $this->fullyAuthorizedAdminFor($renewal);

        app(MarkRenewalPaidExternally::class)($renewal, 'Bukti transfer BCA #123', 'Pelunasan di kasir');

        $this->assertSame(RenewalStatus::DIBAYAR, $renewal->status);
        $this->assertNotNull($renewal->settled_at);
        $this->assertDatabaseHas('renewal_external_markings', ['renewal_id' => $renewal->getKey()]);
        $this->assertDatabaseHas('audit_events', ['action' => 'RENEWAL_EXTERNAL_MARKING']);
    }

    public function test_mark_paid_refuses_settled_renewal(): void
    {
        $renewal = $this->renewal(RenewalStatus::DIBAYAR);
        $this->fullyAuthorizedAdminFor($renewal);

        $this->expectException(RenewalAlreadySettledException::class);
        app(MarkRenewalPaidExternally::class)($renewal, 'x', 'y');
    }

    /**
     * AUTHZ-04 regression: a `finance` actor with NO cemetery-scope grant
     * used to be able to settle any renewal (the Filament action's
     * `->authorize()` gate was role-only). The settle path now enforces
     * the same `RenewalMarkingPolicy` the CREATE path always did, so this
     * must be refused before any state change.
     */
    public function test_mark_paid_externally_refuses_a_finance_actor_with_no_cemetery_grant(): void
    {
        $renewal = $this->renewal(RenewalStatus::MENUNGGU_PEMBAYARAN);
        $user = User::factory()->create();
        $this->grantRole($user, ActorRole::FINANCE);
        $this->actingAs($user);

        $this->expectException(AuthorizationException::class);
        app(MarkRenewalPaidExternally::class)($renewal, 'x', 'y');

        $this->assertSame(RenewalStatus::MENUNGGU_PEMBAYARAN, $renewal->fresh()->status);
    }

    /**
     * AUTHZ-04 regression: an admin holding the role but no cemetery-scope
     * grant for THIS renewal's cemetery must still be refused — mirrors
     * `MarkExternalRenewalTest`'s scope-only denial case.
     */
    public function test_mark_paid_externally_refuses_an_admin_with_no_cemetery_grant(): void
    {
        $renewal = $this->renewal(RenewalStatus::MENUNGGU_PEMBAYARAN);
        $user = User::factory()->create();
        $this->grantRole($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $this->expectException(AuthorizationException::class);
        app(MarkRenewalPaidExternally::class)($renewal, 'x', 'y');

        $this->assertSame(RenewalStatus::MENUNGGU_PEMBAYARAN, $renewal->fresh()->status);
    }

    public function test_expire_transitions_to_kedaluwarsa(): void
    {
        $renewal = $this->renewal(RenewalStatus::MENUNGGU_PEMBAYARAN);
        app(ExpireRenewal::class)($renewal, 'user:1', 'operator');
        $this->assertSame(RenewalStatus::KEDALUWARSA, $renewal->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'RENEWAL_EXPIRED']);
    }
}
