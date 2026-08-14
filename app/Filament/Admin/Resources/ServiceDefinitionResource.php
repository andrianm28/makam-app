<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Filament\Admin\Resources\ServiceDefinitionResource\Pages\CreateServiceDefinition;
use App\Filament\Admin\Resources\ServiceDefinitionResource\Pages\EditServiceDefinition;
use App\Filament\Admin\Resources\ServiceDefinitionResource\Pages\ListServiceDefinitions;
use App\Filament\Admin\Resources\ServiceDefinitionResource\RelationManagers\PriceVersionsRelationManager;
use App\Filament\Admin\Resources\ServiceDefinitionResource\Schemas\ServiceDefinitionForm;
use App\Filament\Admin\Resources\ServiceDefinitionResource\Tables\ServiceDefinitionsTable;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin resource for `ServiceDefinition` — the service catalogue's master
 * data (admin-managed master-data spec; plan Task 6). Mirrors the
 * `FaqArticles/` layout (Resource + Schemas + Tables + Pages split) exactly.
 *
 * ---------------------------------------------------------------------------
 * What may and may not be changed here
 * ---------------------------------------------------------------------------
 * `code` and `category` are canonical catalogue data
 * (`App\Domain\ServiceCatalog\ServiceCode`/`ServiceCategory` — the exact 12
 * codes `docs/product/service-catalog.md` names). The form draws them from
 * the canonical closed lists, and `code` is `->disabled()` on edit: a
 * registered service's code can never be changed, and no code outside the
 * list can ever be invented (`AGENTS.md`: "do not invent alternate labels").
 * The model's own `saving` hook (`ServiceDefinition::booted()`) re-asserts
 * all three closed lists on every write, so even a payload that bypassed the
 * form is refused.
 *
 * `fulfillment_owner`, `requires_schedule`, `requires_manual_confirmation`,
 * `is_active`, and `description` are the admin-managed operational fields —
 * exactly the columns the seed migration deliberately left to a later admin
 * surface (`2026_07_26_180700_...`'s own doc block).
 *
 * Price history is managed through `PriceVersionsRelationManager`, whose
 * create routes through `RecordServiceDefinitionPriceVersion` — the
 * append-only versioning Action — never a raw insert.
 *
 * ---------------------------------------------------------------------------
 * Authorization: `canAccess()` AND `getAuthorizationResponse()`, both
 * delegating to the shared master-data authorizer
 * ---------------------------------------------------------------------------
 * `canAccess()` is overridden with the try/catch -> bool pattern the spec
 * asks every master-data resource for (same shape as
 * `FinanceReports::canAccess()`); it is what the plan's access tests call
 * directly.
 *
 * `getAuthorizationResponse()` is overridden for a different, independent
 * reason. Traced against the installed Filament 5 source: without a policy
 * (none exists for `ServiceDefinition`, and none may be added — see
 * `MasterDataAdminAuthorizerContract`'s doc block on why this repository has
 * no `app/Policies/`), `get_authorization_response()` FAILS OPEN
 * (`Response::allow()` when no policy method matches and strict mode is
 * off), and `Resource::canAccess()` is only `canViewAny()`. A
 * `canAccess()`-only override would therefore gate the navigation while
 * leaving `create`/`edit`/`view` open to any panel user who addresses the
 * routes directly. Overriding `getAuthorizationResponse()` here routes EVERY
 * ability through the same authorizer, so an unknown ability fails closed
 * too.
 */
final class ServiceDefinitionResource extends Resource
{
    protected static ?string $model = ServiceDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

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
            return Response::deny('Anda tidak berwenang mengelola layanan.');
        }

        return Response::allow();
    }

    public static function form(Schema $schema): Schema
    {
        return ServiceDefinitionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiceDefinitionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceDefinitions::route('/'),
            'create' => CreateServiceDefinition::route('/create'),
            'edit' => EditServiceDefinition::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<class-string<RelationManager> | RelationGroup | RelationManagerConfiguration>
     */
    public static function getRelations(): array
    {
        return [
            PriceVersionsRelationManager::class,
        ];
    }

    public static function getModelLabel(): string
    {
        return 'layanan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Layanan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Layanan';
    }
}
