<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MemorialProfiles;

use App\Domain\Memorial\Models\MemorialProfile;
use App\Filament\Admin\Resources\MemorialProfiles\Pages\ListMemorialProfiles;
use App\Filament\Admin\Resources\MemorialProfiles\Pages\ViewMemorialProfile;
use App\Filament\Admin\Resources\MemorialProfiles\Tables\MemorialProfilesTable;
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
 * Admin surface for `memorial_profiles` (`.kiro/specs/memorial-and-qr/
 * requirements.md` AC1/AC2/AC5/AC6; the plan's Task 4 brief): list
 * (grave ref, privacy badge, published state), view with four relation
 * managers (editors — consent-gated grants; contents/media — moderation;
 * QR tokens — issue/rotate + SVG), and the moderator-backed
 * publish/unpublish header actions.
 *
 * ---------------------------------------------------------------------------
 * Access: the shared master-data gate, fail-closed
 * ---------------------------------------------------------------------------
 * Same shape as `GravePlotsResource`: `canAccess()` +
 * `getAuthorizationResponse()` delegate to the
 * `MasterDataAdminAuthorizerContract` four-role gate, and `auditRoleFor()`
 * walks that gate's fixed role precedence for the Audit calls the
 * relation managers and header actions make.
 *
 * PUBLISH/UNPUBLISH ARE MORE RESTRICTED THAN ACCESS (the Lane-3 review
 * watch: "PublishMemorial must be moderator-backed in the ADMIN surface
 * ... role-gated"). The view page's header actions require a
 * moderation-capable role (`admin`/`restricted_admin`/`operator`) — a
 * `finance` actor may view and moderate nowhere near publish; see
 * `actorMayModerate()`.
 *
 * ---------------------------------------------------------------------------
 * Cemetery scoping (the visitation pattern)
 * ---------------------------------------------------------------------------
 * `getEloquentQuery()` narrows the list to the actor's granted cemeteries
 * when the actor holds any `scope_assignments` for `cemetery`; an actor
 * with no grants (admins) sees all. Scoping follows the profile's own
 * grave record (`memorial_profiles.grave_record_id` is the ONLY link to
 * GraveRegistry — AC7).
 */
final class MemorialProfileResource extends Resource
{
    protected static ?string $model = MemorialProfile::class;

    protected static ?string $slug = 'profil-kenangan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $recordTitleAttribute = 'display_name';

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
            return Response::deny('Anda tidak berwenang mengelola profil memorial.');
        }

        return Response::allow();
    }

    /**
     * The single deterministic `actor_role` value for this resource's
     * `Audit::record()`/`Audit::wrap()` calls — same reasoning and shape
     * as `GravePlotsResource::auditRoleFor()`.
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

    /**
     * The moderator gate for the publish/unpublish header actions —
     * deliberately narrower than the four-role access gate above.
     */
    public static function actorMayModerate(ActorContext $actor): bool
    {
        if (! $actor->isAuthenticated()) {
            return false;
        }

        return $actor->hasRole(ActorRole::ADMIN)
            || $actor->hasRole(ActorRole::RESTRICTED_ADMIN)
            || $actor->hasRole(ActorRole::OPERATOR);
    }

    public static function table(Table $table): Table
    {
        return MemorialProfilesTable::configure($table);
    }

    /**
     * Cemetery scoping via the actor's grants — see the class doc block.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = MemorialProfile::query()
            ->with(['graveRecord.cemetery', 'editors', 'qrTokens'])
            ->latest('created_at');

        $actor = app(ActorContext::class);

        if ($actor->identityReference === null) {
            return $query;
        }

        $grantedCemeteryIds = app(ScopeAssignmentReader::class)
            ->grantedEntityIds((string) $actor->identityReference, ScopeEntityType::CEMETERY);

        if ($grantedCemeteryIds !== []) {
            $query->whereHas(
                'graveRecord',
                fn (Builder $graveQuery): Builder => $graveQuery->whereIn('cemetery_id', $grantedCemeteryIds),
            );
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMemorialProfiles::route('/'),
            'view' => ViewMemorialProfile::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'profil memorial';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Profil Memorial';
    }

    public static function getNavigationLabel(): string
    {
        return 'Profil Memorial';
    }
}
