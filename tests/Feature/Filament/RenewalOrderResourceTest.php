<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalStatus;
use App\Filament\Admin\Resources\RenewalOrders\Actions\ExpireRenewalAction;
use App\Filament\Admin\Resources\RenewalOrders\Actions\RecordExternalRenewalPaymentAction;
use App\Filament\Admin\Resources\RenewalOrders\RenewalOrderResource;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

final class RenewalOrderResourceTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function renewal(): Renewal
    {
        $grave = GraveRecord::factory()->create();

        return Renewal::query()->create([
            'grave_record_id' => $grave->getKey(),
            'target_due_period' => '2026-12-01',
            'reference' => 'EXT-'.strtoupper(substr(uniqid(), 0, 8)),
            'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
            'source' => 'online',
        ]);
    }

    public function test_guests_and_bare_users_are_denied(): void
    {
        $this->assertFalse(RenewalOrderResource::canAccess());
        $this->actingAs(User::factory()->create());
        $this->assertFalse(RenewalOrderResource::canAccess());
    }

    public function test_back_office_roles_can_access(): void
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);
            $this->assertTrue(RenewalOrderResource::canAccess(), "role {$role} should access");
        }
    }

    public function test_vendor_role_cannot_access(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::VENDOR);
        $this->actingAs($user);
        $this->assertFalse(RenewalOrderResource::canAccess());
    }

    public function test_operator_cannot_run_record_external_payment_action(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $action = RecordExternalRenewalPaymentAction::make($this->renewal());

        $this->assertFalse($action->isAuthorized());
    }

    public function test_finance_can_run_record_external_payment_action(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);

        $action = RecordExternalRenewalPaymentAction::make($this->renewal());

        $this->assertTrue($action->isAuthorized());
    }

    public function test_operator_can_run_expire_action(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $action = ExpireRenewalAction::make($this->renewal());

        $this->assertTrue($action->isAuthorized());
    }

    public function test_operator_calling_expire_action_transitions_the_renewal(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $renewal = $this->renewal();
        ExpireRenewalAction::make($renewal)
            ->data(['reason' => 'Jendela pembayaran berakhir tanpa pelunasan'])
            ->call();

        $this->assertSame(RenewalStatus::KEDALUWARSA, $renewal->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'RENEWAL_EXPIRED']);
    }
}
