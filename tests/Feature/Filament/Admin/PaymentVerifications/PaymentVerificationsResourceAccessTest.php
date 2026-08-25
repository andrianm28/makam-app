<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\PaymentVerifications;

use App\Filament\Admin\Resources\PaymentVerifications\PaymentVerificationsResource;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\Payment\Models\PaymentVerification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Proves the access boundary on `PaymentVerificationsResource`: only
 * `finance` and `restricted_admin` may reach it (the exact pair
 * `FinanceOrRestrictedAdminPaymentAuthorizer` admits — its own doc block
 * explicitly rules a plain `admin` OUT for this action), every other role
 * and a guest is refused, and the refusal is real — grant rows go through
 * `GrantsActorRoles`, which proves the whole live chain (grant row ->
 * `ActorRoleReader` -> `ActorContext::$roles` -> the authorizer), not a
 * hand-constructed context.
 */
final class PaymentVerificationsResourceAccessTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function seedVerification(): PaymentVerification
    {
        return PaymentVerification::createSubmitted([
            'reference' => 'order-access-1',
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'TRX-ACCESS-1',
            'instructions' => null,
            'submitted_at' => CarbonImmutable::now(),
        ]);
    }

    public function test_a_guest_cannot_access_the_resource(): void
    {
        $this->assertFalse(PaymentVerificationsResource::canAccess());
    }

    public function test_an_authenticated_actor_with_no_role_cannot_access_the_resource(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse(PaymentVerificationsResource::canAccess());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rolesWithoutPaymentVerificationAccess(): iterable
    {
        // `admin` is deliberately included here, not just the obviously
        // unrelated roles: `FinanceOrRestrictedAdminPaymentAuthorizer`'s own
        // doc block rules a plain `admin` OUT for this action, an explicit
        // human ruling (12 Aug 2026) distinct from the nearest other RBAC
        // row that WOULD admit `admin` — pinning that exclusion here is the
        // whole point of this data provider.
        yield 'admin' => [ActorRole::ADMIN];
        yield 'operator' => [ActorRole::OPERATOR];
        yield 'case_manager' => [ActorRole::CASE_MANAGER];
        yield 'vendor' => [ActorRole::VENDOR];
        yield 'customer' => [ActorRole::CUSTOMER];
    }

    #[DataProvider('rolesWithoutPaymentVerificationAccess')]
    public function test_a_role_without_payment_verification_authority_cannot_access_the_resource(string $role): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, $role);
        $this->actingAs($user);

        $this->assertFalse(
            PaymentVerificationsResource::canAccess(),
            "Expected role [{$role}] to be refused payment verification access."
        );
    }

    public function test_finance_and_restricted_admin_can_access_the_resource(): void
    {
        foreach ([ActorRole::FINANCE, ActorRole::RESTRICTED_ADMIN] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(
                PaymentVerificationsResource::canAccess(),
                "Expected role [{$role}] to access the payment verifications resource."
            );

            $this->forgetResolvedActorContext();
        }
    }

    public function test_a_guest_is_redirected_away_from_the_list_page(): void
    {
        $response = $this->get(PaymentVerificationsResource::getUrl('index'));

        $response->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_finance_can_open_the_list_page_over_http_and_see_a_seeded_row(): void
    {
        $verification = $this->seedVerification();

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);

        $response = $this->actingAs($user)->get(PaymentVerificationsResource::getUrl('index'));

        $response->assertOk();
        $response->assertSee($verification->reference);
    }

    public function test_restricted_admin_can_open_the_list_page_over_http(): void
    {
        $this->seedVerification();

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::RESTRICTED_ADMIN);

        $response = $this->actingAs($user)->get(PaymentVerificationsResource::getUrl('index'));

        $response->assertOk();
    }

    public function test_a_plain_admin_gets_forbidden_over_http(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);

        $response = $this->actingAs($user)->get(PaymentVerificationsResource::getUrl('index'));

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
            PaymentVerificationsResource::getEloquentQuery();
            $this->fail('An unauthorised actor must not reach an unfiltered query.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_the_resource_offers_no_create_or_edit_page(): void
    {
        $pages = PaymentVerificationsResource::getPages();

        $this->assertArrayHasKey('index', $pages);
        $this->assertArrayHasKey('view', $pages);
        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }
}
