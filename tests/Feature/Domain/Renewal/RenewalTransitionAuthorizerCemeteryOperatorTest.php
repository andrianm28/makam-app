<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Renewal;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalSource;
use App\Domain\Renewal\RenewalStatus;
use App\Filament\Admin\Resources\RenewalOrders\Actions\ExpireRenewalAction;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * `RecordExternalRenewalPaymentAction`/`ExpireRenewalAction` resolve their
 * cemetery id via `Renewal->graveRecord->cemetery_id` (Task 6). This proves
 * the non-money `expire_renewal` transition end-to-end through the real
 * Filament Action call site — the money-transition case
 * (`record_external_renewal_payment`) is already covered at the authorizer
 * level by `OrderTransitionAuthorizerTest
 * ::test_cemetery_operator_cannot_run_a_money_transition_even_for_their_own_cemetery`,
 * since cemetery_operator can never pass a MONEY_TRANSITIONS check
 * regardless of cemetery.
 */
final class RenewalTransitionAuthorizerCemeteryOperatorTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    public function test_a_cemetery_operator_cannot_expire_another_cemeterys_renewal(): void
    {
        $cemeteryA = Cemetery::factory()->create();
        $cemeteryB = Cemetery::factory()->create();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemeteryB->id]);
        $renewal = Renewal::query()->create([
            'grave_record_id' => $grave->id,
            'target_due_period' => now()->addYear()->format('Y-m-d'),
            'reference' => 'PPJ-'.random_int(1_000, 99_999),
            'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
            'source' => RenewalSource::ONLINE,
        ]);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemeteryA->id,
        ]);
        $this->actingAs($user);

        $action = ExpireRenewalAction::make($renewal);

        $this->assertFalse($action->isAuthorized());
    }

    public function test_a_cemetery_operator_can_expire_their_own_cemeterys_renewal(): void
    {
        $cemeteryA = Cemetery::factory()->create();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemeteryA->id]);
        $renewal = Renewal::query()->create([
            'grave_record_id' => $grave->id,
            'target_due_period' => now()->addYear()->format('Y-m-d'),
            'reference' => 'PPJ-'.random_int(1_000, 99_999),
            'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
            'source' => RenewalSource::ONLINE,
        ]);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemeteryA->id,
        ]);
        $this->actingAs($user);

        $action = ExpireRenewalAction::make($renewal);

        $this->assertTrue($action->isAuthorized());
    }
}
