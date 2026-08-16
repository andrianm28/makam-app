<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ModerationCases;

use App\Domain\Memorial\Models\ModerationCase;
use App\Filament\Admin\Resources\ModerationCases\Pages\ListModerationCases;
use App\Filament\Admin\Resources\ModerationCases\Pages\ViewModerationCase;
use App\Filament\Admin\Resources\ModerationCases\Tables\ModerationCasesTable;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentReader;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin surface for `moderation_cases` — the AC6 moderation queue
 * (`.kiro/specs/memorial-and-qr/requirements.md` AC6 report intake; the
 * plan's Task 4 brief: "queue (open cases first), resolve/dismiss with
 * reason + audit").
 *
 * Same access shape as `MemorialProfileResource` (the shared
 * `MasterDataAdminAuthorizerContract` gate + `auditRoleFor`). Cemetery
 * scoping follows the CASE's reported profile's grave → the actor's
 * cemetery grants, the visitation pattern — an operator holding
 * cemetery grants only sees cases from their cemeteries.
 *
 * Open cases sort first (the CASE-ordering in `getEloquentQuery()`, not a
 * lexical `orderBy('status')` — 'dismissed' would beat 'open'
 * alphabetically), then by recency: the queue surfaces what needs a
 * moderator's hand first.
 */
final class ModerationCaseResource extends Resource
{
    protected static ?string $model = ModerationCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

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
        } catch (MasterDataNotAuthorisedException) {
            return Response::deny('Anda tidak berwenang mengelola kasus moderasi.');
        }

        return Response::allow();
    }

    /**
     * Same deterministic role derivation as `MemorialProfileResource`.
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

    public static function table(Table $table): Table
    {
        return ModerationCasesTable::configure($table);
    }

    /**
     * Cemetery scoping via the case's reported profile's grave record —
     * see the class doc block.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = ModerationCase::query()
            ->with(['profile.graveRecord.cemetery', 'abuseReports'])
            // Open cases first, then by recency. NOT orderBy('status'):
            // the lexical order 'dismissed' < 'open' < 'resolved' would put
            // dismissed cases at the top of the queue. The CASE form is the
            // repo idiom (ServiceCatalogQuery), keeping the column value
            // out of the ordering logic.
            ->orderByRaw("CASE status WHEN 'open' THEN 0 ELSE 1 END")
            ->latest('created_at');

        $actor = app(ActorContext::class);

        if ($actor->identityReference === null) {
            return $query;
        }

        $grantedCemeteryIds = app(ScopeAssignmentReader::class)
            ->grantedEntityIds((string) $actor->identityReference, ScopeEntityType::CEMETERY);

        if ($grantedCemeteryIds !== []) {
            $query->whereHas(
                'profile.graveRecord',
                fn (Builder $graveQuery): Builder => $graveQuery->whereIn('cemetery_id', $grantedCemeteryIds),
            );
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModerationCases::route('/'),
            'view' => ViewModerationCase::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'kasus moderasi';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Kasus Moderasi';
    }

    public static function getNavigationLabel(): string
    {
        return 'Kasus Moderasi';
    }
}
