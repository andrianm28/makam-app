<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Agreements;

use App\Domain\AgreementCertificate\Models\Agreement;
use App\Filament\Admin\Resources\Agreements\Pages\ListAgreements;
use App\Filament\Admin\Resources\Agreements\Pages\ViewAgreement;
use App\Filament\Admin\Resources\Agreements\Schemas\AgreementInfolist;
use App\Filament\Admin\Resources\Agreements\Tables\AgreementsTable;
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
 * Admin surface for `agreements` — Task 2 (P5a, Lane 1). One row per
 * AGREEMENT VERSION, so the list shows rows across the lineage; the view
 * renders the AC4 display fields plus the AC2 acceptance binding, and the
 * 'Terima' (accept — AC2) and 'Supersesi' (supersede — AC5) header actions
 * route through the domain Actions.
 *
 * Same access shape as `CertificatesResource`: the shared
 * `MasterDataAdminAuthorizerContract` gate answered twice, and the same
 * deterministic `auditRoleFor()`.
 */
final class AgreementsResource extends Resource
{
    protected static ?string $model = Agreement::class;

    protected static ?string $slug = 'persetujuan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $recordTitleAttribute = 'reference';

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
            return Response::deny('Anda tidak berwenang mengelola perjanjian.');
        }
    }

    /**
     * Same deterministic role derivation as `CertificatesResource`.
     */
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
        return AgreementsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AgreementInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return Agreement::query()->latest('created_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgreements::route('/'),
            'view' => ViewAgreement::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'perjanjian';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Perjanjian';
    }

    public static function getNavigationLabel(): string
    {
        return 'Perjanjian';
    }
}
