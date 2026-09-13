<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\Contracts\NotificationMatrixSource;
use App\Platform\Notification\Recipient;
use App\Platform\Notification\RecipientResolutionSubject;
use App\Platform\Notification\RecipientResolver;
use App\Platform\Notification\RecipientRole;
use App\Platform\Notification\RecipientRoleColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `RecipientRoleColumns` exists as a deliberate second copy of
 * `RecipientResolver`'s own role-to-matrix-column mapping — that class's doc
 * block explains why (the original is `private const` on a frozen Task 2
 * file) and flags the drift risk it accepts in exchange.
 *
 * Guarding that drift by reading the private constant back out with
 * reflection proved only that two arrays are `===` today. It said nothing
 * about whether the resolver USES its constant to pick a column, so the two
 * could agree perfectly while the resolver read some third thing — and it
 * broke on any rename of a private implementation detail.
 *
 * The parity is asserted through the resolver instead. For each role, a
 * matrix is synthesised in which the ONLY column carrying a recipient is the
 * one `RecipientRoleColumns::columnFor()` names for that role; every other
 * column reads `none`. If the resolver's private mapping pointed that role at
 * any other column, it would find `none` there and resolve nobody — so the
 * recipient coming back IS the proof that both mappings name the same column.
 */
final class RecipientRoleColumnsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The scope entity type whose grant implies each role, per
     * `ProvisionalScopeEntityRecipientRoleSource`. Needed because a role is
     * only reachable through a grant of its entity type.
     *
     * @var array<string, string>
     */
    private const array ENTITY_TYPE_FOR_ROLE = [
        RecipientRole::PLATFORM_ADMIN => ScopeEntityType::BUSINESS_ENTITY,
        RecipientRole::CEMETERY_OPERATOR => ScopeEntityType::CEMETERY,
        RecipientRole::VENDOR => ScopeEntityType::VENDOR,
        RecipientRole::CASE_MANAGER => ScopeEntityType::CASE_RECORD,
    ];

    private const string EVENT = 'Parity probe';

    /**
     * The matrix's real header row, so the synthesised document has the same
     * shape the resolver parses in production.
     *
     * @var list<string>
     */
    private const array COLUMNS = [
        'Customer',
        'Admin platform',
        'Pengelola TPU/TPS',
        'Vendor',
        'Case manager',
        'Finance',
    ];

    public function test_the_resolver_reads_the_column_that_recipient_role_columns_names_for_each_role(): void
    {
        // Anchored so a future emptying of SCOPE_ROLE_COLUMNS cannot turn this
        // into a loop that asserts nothing.
        $this->assertCount(4, RecipientRoleColumns::SCOPE_ROLE_COLUMNS);

        foreach (RecipientRoleColumns::SCOPE_ROLE_COLUMNS as $role => $column) {
            $entityType = self::ENTITY_TYPE_FOR_ROLE[$role];

            ScopeAssignment::query()->create([
                'actor_identifier' => "actor-for-{$role}",
                'entity_type' => $entityType,
                'entity_id' => '10',
            ]);

            $set = $this->resolverForMatrixWithOnly($column)->resolve(
                self::EVENT,
                new RecipientResolutionSubject(
                    ownerRef: null,
                    scopeEntityType: $entityType,
                    scopeEntityId: '10',
                ),
            );

            $roles = array_map(static fn (Recipient $r): string => $r->actorRole, $set->all());

            $this->assertSame(
                [$role],
                $roles,
                "The resolver did not read column [{$column}] for role [{$role}] — "
                .'RecipientRoleColumns has drifted from RecipientResolver::ROLE_COLUMNS.'
            );
        }
    }

    /**
     * The control. With the SAME grant and the SAME event but every column set
     * to `none`, nobody resolves — so the assertions above are evidence that
     * the named column was read, and not evidence that this event resolves
     * that role no matter what the matrix says.
     */
    public function test_no_role_resolves_when_its_named_column_is_empty(): void
    {
        foreach (RecipientRoleColumns::SCOPE_ROLE_COLUMNS as $role => $column) {
            $entityType = self::ENTITY_TYPE_FOR_ROLE[$role];

            ScopeAssignment::query()->create([
                'actor_identifier' => "actor-for-{$role}",
                'entity_type' => $entityType,
                'entity_id' => '20',
            ]);

            $set = $this->resolverForMatrixWithOnly(null)->resolve(
                self::EVENT,
                new RecipientResolutionSubject(
                    ownerRef: null,
                    scopeEntityType: $entityType,
                    scopeEntityId: '20',
                ),
            );

            $this->assertTrue($set->isEmpty(), "Role [{$role}] resolved from a matrix row where [{$column}] reads none.");
        }
    }

    /**
     * A one-row matrix document in which `$populatedColumn` carries a channel
     * token and every other column reads `none`. Passing `null` populates
     * nothing, which is the control above.
     */
    private function resolverForMatrixWithOnly(?string $populatedColumn): RecipientResolver
    {
        $cells = array_map(
            static fn (string $column): string => $column === $populatedColumn ? 'in-app' : 'none',
            self::COLUMNS,
        );

        $matrix = '| Event | '.implode(' | ', self::COLUMNS)." |\n"
            .'| --- | '.implode(' | ', array_fill(0, count(self::COLUMNS), '---'))." |\n"
            .'| '.self::EVENT.' | '.implode(' | ', $cells)." |\n";

        $path = tempnam(sys_get_temp_dir(), 'matrix').'.md';
        file_put_contents($path, $matrix);

        // Cleaned up with the test, not left behind in the system temp dir.
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            @unlink($path);
        });

        return new RecipientResolver(
            new NotificationMatrixSource($path),
            $this->app->make(ScopeAssignmentResolver::class),
        );
    }
}
