<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Filament\Admin\Resources\CemeteryResource\Pages\ListCemeteries;
use App\Filament\Admin\Resources\CemeteryResource\Tables\CemeteriesTable;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\Roles\ActorRole;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin management surface for the cemetery master data (`cemeteries` +
 * `cemetery_packages` via `PackagesRelationManager`) — the first master-data
 * resource built on the shared `MasterDataAdminAuthorizerContract` gate
 * (merged by the `masterdata-authorizer` lane), following the
 * `FaqArticles/` ground-truth layout (Resource + Schemas + Pages + Tables +
 * RelationManagers subdirectories).
 *
 * `docs/superpowers/specs/2026-08-13-admin-managed-master-data-design.md`
 * assigns this resource the `cemeteries` + `cemetery_packages` rows of the
 * master-data ownership model: the `App\Support\ExampleData\*` generators
 * remain the dev/test bootstrap; the admin panel becomes the source of
 * truth for real data.
 *
 * ---------------------------------------------------------------------------
 * Authorization: `canAccess()` AND `getAuthorizationResponse()`, both
 * delegating to the one shared authorizer
 * ---------------------------------------------------------------------------
 * `canAccess()` is overridden per the spec's "same pattern as
 * `FinanceReports::canAccess()`" instruction — authorizer try/catch -> bool,
 * fail-closed for any actor without one of the four back-office roles. It is
 * the predicate the panel's page-mount guard (`CanAuthorizeResourceAccess`)
 * and the navigation renderer consult.
 *
 * `getAuthorizationResponse()` is ALSO overridden, exactly as
 * `FaqArticleResource` does, because in Filament 5 (`Resources\Resource
 * \Concerns\HasAuthorization`, verified against the installed v5.7.3) every
 * row-action predicate (`getEditAuthorizationResponse()`,
 * `getDeleteAuthorizationResponse()`, ...) routes through it and — without
 * the override — would fall through to Filament's no-policy allow for every
 * panel user, bypassing the master-data gate at the row level. Both methods
 * answer the same question from the same authorizer, so they cannot
 * disagree; `canAccess()`'s override exists because the spec names it
 * explicitly, and the refusal arm of `getAuthorizationResponse()` exists
 * because the row level is where a naive `canAccess()`-only implementation
 * silently leaks.
 */
final class CemeteryResource extends Resource
{
    protected static ?string $model = Cemetery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return Response::deny('Anda tidak berwenang mengelola data makam.');
        }

        return Response::allow();
    }

    public static function table(Table $table): Table
    {
        return CemeteriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCemeteries::route('/'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'makam';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Makam / TPU';
    }

    public static function getNavigationLabel(): string
    {
        return 'Makam / TPU';
    }

    /**
     * The single deterministic `actor_role` value for this resource's
     * `Audit::record()` calls. `audit_events.actor_role` is NOT NULL and
     * `ActorContext` has no single "the actor's role" field, so one is
     * derived here — the same reasoning and shape as
     * `DocumentVault\Policies\DocumentAccessPolicy::auditRoleFor()`.
     *
     * Determinism matters for evidence review: reading `$roles[0]` would
     * not be deterministic (the array's order is whatever the identity
     * adapter emitted). This walks the authorizer's own fixed four-role
     * precedence order and reports the first match. Falls back to
     * `authenticated_actor` for an authenticated actor holding no
     * back-office role and `guest` for a guest — both unreachable in
     * practice behind `canAccess()`, but the audit row must never be
     * written with a blank role.
     */
    public static function auditRoleFor(ActorContext $actor): string
    {
        if (! $actor->isAuthenticated()) {
            return 'guest';
        }

        foreach ([
            ActorRole::ADMIN,
            ActorRole::RESTRICTED_ADMIN,
            ActorRole::OPERATOR,
            ActorRole::FINANCE,
        ] as $role) {
            if ($actor->hasRole($role)) {
                return $role;
            }
        }

        return 'authenticated_actor';
    }
}
