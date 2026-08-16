<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Vendors;

use App\Domain\Marketplace\Models\Vendor;
use App\Filament\Admin\Resources\Vendors\Pages\CreateVendor;
use App\Filament\Admin\Resources\Vendors\Pages\EditVendor;
use App\Filament\Admin\Resources\Vendors\Pages\ListVendors;
use App\Filament\Admin\Resources\Vendors\RelationManagers\AvailabilityRelationManager;
use App\Filament\Admin\Resources\Vendors\RelationManagers\ListingsRelationManager;
use App\Filament\Admin\Resources\Vendors\RelationManagers\MembersRelationManager;
use App\Filament\Admin\Resources\Vendors\Schemas\VendorForm;
use App\Filament\Admin\Resources\Vendors\Tables\VendorsTable;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\Roles\ActorRole;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin management surface for the marketplace vendor master data
 * (`vendors` + `vendor_users` + `vendor_listings` + `vendor_availability`
 * via the three relation managers) — the second master-data resource built
 * on the shared `MasterDataAdminAuthorizerContract` gate, following the
 * `CemeteryResource` ground-truth layout (Resource + Schemas + Pages +
 * Tables + RelationManagers subdirectories).
 *
 * ---------------------------------------------------------------------------
 * Authorization: `canAccess()` AND `getAuthorizationResponse()`, both
 * delegating to the one shared authorizer
 * ---------------------------------------------------------------------------
 * Exactly the `CemeteryResource` shape: `canAccess()` is the predicate the
 * panel's page-mount guard and navigation renderer consult, and
 * `getAuthorizationResponse()` is overridden because in Filament 5 every
 * row-action predicate routes through it and — without the override —
 * would fall through to Filament's no-policy allow for every panel user,
 * bypassing the master-data gate at the row level.
 */
final class VendorResource extends Resource
{
    protected static ?string $model = Vendor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

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
            return Response::deny('Anda tidak berwenang mengelola data vendor.');
        }

        return Response::allow();
    }

    public static function form(Schema $schema): Schema
    {
        return VendorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VendorsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendors::route('/'),
            'create' => CreateVendor::route('/create'),
            'edit' => EditVendor::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            ListingsRelationManager::class,
            AvailabilityRelationManager::class,
        ];
    }

    public static function getModelLabel(): string
    {
        return 'vendor';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Vendor';
    }

    public static function getNavigationLabel(): string
    {
        return 'Vendor';
    }

    /**
     * The single deterministic `actor_role` value for this resource's
     * `Audit::record()` calls — the same reasoning and shape as
     * `CemeteryResource::auditRoleFor()`: `audit_events.actor_role` is NOT
     * NULL and `ActorContext` has no single "the actor's role" field, so
     * one is derived here by walking the authorizer's own fixed four-role
     * precedence order. Falls back to `authenticated_actor` for an
     * authenticated actor holding no back-office role and `guest` for a
     * guest — both unreachable in practice behind `canAccess()`, but the
     * audit row must never be written with a blank role.
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
