<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LaunchCities;

use App\Domain\CemeteryDirectory\Models\LaunchCity;
use App\Filament\Admin\Resources\LaunchCities\Pages\CreateLaunchCity;
use App\Filament\Admin\Resources\LaunchCities\Pages\EditLaunchCity;
use App\Filament\Admin\Resources\LaunchCities\Pages\ListLaunchCities;
use App\Filament\Admin\Resources\LaunchCities\Schemas\LaunchCityForm;
use App\Filament\Admin\Resources\LaunchCities\Tables\LaunchCitiesTable;
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
 * Admin management surface for the launch-city catalogue (`launch_cities`)
 * — the table-backed source of "which cities exist" for every city-code
 * consumer in the codebase (spec §4.6: admin extension is a
 * product-approved capability; `LaunchCityQuery`'s own doc block lists the
 * seams this catalogue feeds).
 *
 * Built on the same shared master-data gate and layout as
 * `CemeteryResource`: `canAccess()` + `getAuthorizationResponse()` both
 * delegate to `MasterDataAdminAuthorizerContract`, and the directory
 * mirrors `CemeteryResource/` (Resource + Schemas + Pages + Tables
 * subdirectories).
 *
 * ---------------------------------------------------------------------------
 * Write/audit shape — model save wrapped in `Audit::wrap`, per task brief
 * ---------------------------------------------------------------------------
 * Unlike `CemeteryResource` (whose create/update audit rows are written
 * from `afterCreate()`/`afterSave()` hooks inside Filament's own
 * transaction), this resource's pages override `handleRecordCreation()`
 * /`handleRecordUpdate()` to route the model save through `Audit::wrap()`
 * — the task brief is explicit: "All writes `Audit::wrap`". The wrap's own
 * `DB::transaction()` is a savepoint inside Filament's page transaction,
 * so AC4's "state change and audit record commit together" guarantee is
 * preserved, and the delete action uses the same wrap shape with the
 * `QueryException` race backstop `EditCemetery` documents.
 */
final class LaunchCityResource extends Resource
{
    protected static ?string $model = LaunchCity::class;

    protected static ?string $slug = 'kota-peluncuran';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $recordTitleAttribute = 'label';

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
            return Response::deny('Anda tidak berwenang mengelola data kota.');
        }

        return Response::allow();
    }

    public static function form(Schema $schema): Schema
    {
        return LaunchCityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LaunchCitiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLaunchCities::route('/'),
            'create' => CreateLaunchCity::route('/create'),
            'edit' => EditLaunchCity::route('/{record}/edit'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'kota';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Kota';
    }

    public static function getNavigationLabel(): string
    {
        return 'Kota';
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
