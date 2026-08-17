<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarePlans;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Filament\Admin\Resources\CarePlans\Pages\CreateCarePlan;
use App\Filament\Admin\Resources\CarePlans\Pages\ListCarePlans;
use App\Filament\Admin\Resources\CarePlans\Pages\ViewCarePlan;
use App\Filament\Admin\Resources\CarePlans\Schemas\CarePlanInfolist;
use App\Filament\Admin\Resources\CarePlans\Tables\CarePlansTable;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin surface for `care_plans` — master-data management for recurring
 * grave maintenance plans. Same access shape as `AgreementsResource`:
 * `MasterDataAdminAuthorizerContract` gate, `auditRoleFor()` for every
 * action.
 */
final class CarePlansResource extends Resource
{
    protected static ?string $model = CarePlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?int $navigationSort = 60;

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

            return Response::allow();
        } catch (MasterDataNotAuthorisedException) {
            return Response::deny('Anda tidak berwenang mengelola rencana perawatan.');
        }
    }

    public static function auditRoleFor(ActorContext $actor): string
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            if ($actor->hasRole($role)) {
                return $role;
            }
        }

        return $actor->isAuthenticated() ? 'authenticated_actor' : 'guest';
    }

    public static function table(Table $table): Table
    {
        return CarePlansTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CarePlanInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return CarePlan::query()->latest('created_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCarePlans::route('/'),
            'view' => ViewCarePlan::route('/{record}'),
            'create' => CreateCarePlan::route('/create'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'rencana perawatan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Rencana Perawatan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Rencana Perawatan';
    }
}
