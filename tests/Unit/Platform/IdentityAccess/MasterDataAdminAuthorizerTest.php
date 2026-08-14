<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\MasterData\MasterDataAdminAuthorizer;
use App\Platform\IdentityAccess\Roles\ActorRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shared master-data administration policy in isolation: which roles may
 * administer the master-data entities, and which may not.
 *
 * A plain PHPUnit `TestCase`, not `Tests\TestCase`: the authorizer touches
 * no database, no container, and no request. Booting the framework for it
 * would hide a dependency creeping in later — same discipline as
 * `FinanceOrRestrictedAdminPaymentAuthorizerTest`.
 */
final class MasterDataAdminAuthorizerTest extends TestCase
{
    private function authorizer(): MasterDataAdminAuthorizer
    {
        return new MasterDataAdminAuthorizer;
    }

    public function test_it_implements_the_module_contract(): void
    {
        $this->assertInstanceOf(MasterDataAdminAuthorizerContract::class, $this->authorizer());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function authorizedRoles(): iterable
    {
        yield 'admin' => [ActorRole::ADMIN];
        yield 'restricted_admin' => [ActorRole::RESTRICTED_ADMIN];
        yield 'operator' => [ActorRole::OPERATOR];
        yield 'finance' => [ActorRole::FINANCE];
    }

    #[DataProvider('authorizedRoles')]
    public function test_authorizes_the_four_back_office_roles(string $role): void
    {
        $actor = new ActorContext(identityReference: 1, roles: [$role]);

        // Must not throw.
        $this->authorizer()->authorize($actor);

        $this->assertTrue(true);
    }

    public function test_rejects_an_authenticated_customer(): void
    {
        $actor = new ActorContext(identityReference: 1, roles: []);

        $this->expectException(MasterDataNotAuthorisedException::class);

        $this->authorizer()->authorize($actor);
    }
}
