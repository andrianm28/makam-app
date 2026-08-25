<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PreNeedCases;

use App\Domain\PreNeed\Models\PreNeedCase;
use App\Filament\Admin\Resources\PreNeedCases\Pages\ListPreNeedCases;
use App\Filament\Admin\Resources\PreNeedCases\Pages\ViewPreNeedCase;
use App\Filament\Admin\Resources\PreNeedCases\Schemas\PreNeedCaseInfolist;
use App\Filament\Admin\Resources\PreNeedCases\Tables\PreNeedCasesTable;
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
 * Admin management surface for the paid Pre-Need flow's coordination
 * aggregate — `pre_need_cases` (plan Task 4, Lane 2). Read-only by
 * construction: a case's status and links move ONLY through the seven
 * paid-flow domain Actions (`Actions\PreNeedCaseActions` build the header
 * actions the view page mounts) and `OpenPaymentSession` (the per-
 * installment payment-link seam) — never through an Eloquent write from
 * this Resource.
 *
 * ---------------------------------------------------------------------------
 * Authorization — the shared master-data gate, same shape as every other
 * back-office Resource
 * ---------------------------------------------------------------------------
 * `canAccess()` is the predicate the panel's page-mount guard and
 * navigation consult; `getAuthorizationResponse()` is ALSO overridden
 * (Filament 5 routes every row-action predicate through it and would
 * otherwise fall through to its no-policy allow). Both answer the same
 * question from `MasterDataAdminAuthorizerContract`. The paid-flow
 * ACTIONS carry their own per-step role gates (`PreNeedCaseActions`), on
 * top of this resource-level admission.
 */
final class PreNeedCaseResource extends Resource
{
    protected static ?string $model = PreNeedCase::class;

    protected static ?string $slug = 'kasus-preneed';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'id';

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
            return Response::deny('Anda tidak berwenang mengelola kasus pra-layanan.');
        }
    }

    public static function infolist(Schema $schema): Schema
    {
        return PreNeedCaseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PreNeedCasesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return PreNeedCase::query()
            ->with(['interest', 'interest.bookingDraft', 'cemetery', 'package']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPreNeedCases::route('/'),
            'view' => ViewPreNeedCase::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'kasus pra-layanan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Kasus Pra-Layanan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Kasus Pra-Layanan';
    }

    /**
     * The single deterministic `actor_role` value for this Resource's
     * domain-action calls — the `CemeteryResource::auditRoleFor()` shape
     * (walk the four back-office roles in the authorizer's precedence
     * order; fall back to `authenticated_actor` / `guest`).
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
