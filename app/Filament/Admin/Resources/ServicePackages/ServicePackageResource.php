<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServicePackages;

use App\Domain\ServiceCatalog\Models\ServicePackage;
use App\Filament\Admin\Resources\ServicePackages\Pages\ListServicePackages;
use App\Filament\Admin\Resources\ServicePackages\Pages\ViewServicePackage;
use App\Filament\Admin\Resources\ServicePackages\RelationManagers\VersionItemsRelationManager;
use App\Filament\Admin\Resources\ServicePackages\RelationManagers\VersionsRelationManager;
use App\Filament\Admin\Resources\ServicePackages\Schemas\ServicePackageForm;
use App\Filament\Admin\Resources\ServicePackages\Tables\ServicePackagesTable;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\Roles\ActorRole;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin resource for `ServicePackage` — the admin-managed
 * `service_packages` + `service_package_versions` + `service_package_items`
 * surface (P2 admin-data-management plan, Task 5, Lane C).
 *
 * ---------------------------------------------------------------------------
 * Write routing: every mutation goes through the domain Actions
 * ---------------------------------------------------------------------------
 * - Create routes through `Actions\DefineServicePackage` (list-page header
 *   CreateAction `->using()`) — the action mints the package, its DRAFT v1
 *   and v1's items in one transaction and self-audits
 *   (`SERVICE_PACKAGE_DEFINED`).
 * - Publish/revise route through `Actions\PublishServicePackageVersion` /
 *   `Actions\ReviseServicePackageVersion` from the Versions relation
 *   manager (self-auditing: `SERVICE_PACKAGE_VERSION_PUBLISHED` /
 *   `SERVICE_PACKAGE_VERSION_REVISED`).
 * - Item create/edit on the open draft route through plain model writes
 *   wrapped in `Audit::wrap()` (`SERVICE_PACKAGE_ITEM_CREATED`/
 *   `SERVICE_PACKAGE_ITEM_UPDATED`) — the item model's own `booted()`
 *   editable-version guard enforces draft-only, so a write against a
 *   published version is refused with an honest notification.
 * - Package delete is offered only when safe: the model's own `deleting()`
 *   guard refuses when any published version exists, and the view page
 *   surfaces that refusal as an honest notification.
 *
 * ---------------------------------------------------------------------------
 * Authorization: `canAccess()` AND `getAuthorizationResponse()`, both
 * delegating to the shared master-data authorizer
 * ---------------------------------------------------------------------------
 * Same shape as `ServiceDefinitionResource` and `CemeteryResource`: the
 * try/catch -> bool for navigation/page-mount gating, and the
 * `getAuthorizationResponse()` override so every ability (including the
 * view page's delete) fails closed for an actor without a back-office role.
 */
final class ServicePackageResource extends Resource
{
    protected static ?string $model = ServicePackage::class;

    protected static ?string $slug = 'paket-layanan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

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
            return Response::deny('Anda tidak berwenang mengelola paket layanan.');
        }

        return Response::allow();
    }

    public static function form(Schema $schema): Schema
    {
        return ServicePackageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServicePackagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServicePackages::route('/'),
            'view' => ViewServicePackage::route('/{record}'),
        ];
    }

    /**
     * @return array<class-string<RelationManager>>
     */
    public static function getRelations(): array
    {
        return [
            VersionsRelationManager::class,
            VersionItemsRelationManager::class,
        ];
    }

    public static function getModelLabel(): string
    {
        return 'paket layanan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Paket Layanan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Paket Layanan';
    }

    /**
     * The single deterministic `actor_role` value for this resource's
     * `Audit::record()` calls — same reasoning and shape as
     * `CemeteryResource::auditRoleFor()`.
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
