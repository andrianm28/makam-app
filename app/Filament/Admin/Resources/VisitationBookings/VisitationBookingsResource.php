<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VisitationBookings;

use App\Domain\Visitation\Models\VisitationBooking;
use App\Filament\Admin\Resources\VisitationBookings\Pages\ListVisitationBookings;
use App\Filament\Admin\Resources\VisitationBookings\Tables\VisitationBookingsTable;
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
 * Admin surface for `visitation_bookings` — Task 2 (Lane 2 — Visitation):
 * the operator's request queue for the public `/kunjungan` page. Table:
 * reference, cemetery, visit date, visitor count, contact, status badge;
 * row actions confirm/cancel/no-show run
 * `App\Domain\Visitation\Actions\ChangeVisitationBookingStatus`
 * (`VISITATION_STATUS_CHANGED` audit + the transactional
 * `visit.booking_confirmed.v1` outbox row on confirm).
 *
 * ---------------------------------------------------------------------------
 * Per-cemetery scoping (AC6: "show an operator only bookings for their
 * assigned cemetery")
 * ---------------------------------------------------------------------------
 * `getEloquentQuery()` scopes by the actor's active cemetery grants
 * (`ScopeAssignmentReader::grantedEntityIds($actor->identityReference,
 * ScopeEntityType::CEMETERY)` — the same `scope_assignments` grants
 * `GrantScopeAssignment` writes): an actor holding any cemetery grant
 * sees ONLY those cemeteries' bookings (a cross-cemetery id in a URL or
 * wire call resolves nothing, because Filament resolves the record from
 * this scoped query), and an admin — who holds no cemetery grants —
 * sees all. This is query-level scope at the resource boundary, exactly
 * the enforcement point `platform-identity-and-access` design.md lists:
 * the status actions inherit the scoping (their records come from this
 * query), so a scoped operator cannot act on another cemetery's booking.
 *
 * Same access-gate shape as the sibling master-data resources
 * (`MasterDataAdminAuthorizerContract` + `auditRoleFor()`), with one
 * deliberate addition documented above `getEloquentQuery()`: the read
 * gate adds the scope check the shared role gate deliberately does not
 * do.
 */
final class VisitationBookingsResource extends Resource
{
    protected static ?string $model = VisitationBooking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

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
        } catch (MasterDataNotAuthorisedException) {
            return Response::deny('Anda tidak berwenang mengelola permintaan kunjungan.');
        }

        return Response::allow();
    }

    public static function table(Table $table): Table
    {
        return VisitationBookingsTable::configure($table);
    }

    /**
     * The AC6 query-level scope: cemetery-granted actors see only their
     * cemeteries' bookings; everyone else (admins, and any master-data
     * actor with no grants yet) sees all. Deliberately NO global scope on
     * `VisitationBooking` itself: `ScopeAssignmentGlobalScope`'s
     * closed-by-default semantics would make every booking invisible to
     * every actor until grants exist — right for models whose rows are
     * all privately scoped, wrong for a queue whose whole point is that
     * the (admin) back office reviews it. The resource boundary is the
     * enforcement point here, and the negative criterion (a changed id
     * in a URL reaches no cross-scope row) holds because Filament
     * resolves records from this query.
     */
    public static function getEloquentQuery(): Builder
    {
        $actor = app(ActorContext::class);

        if ($actor->isAuthenticated()) {
            $grantedCemeteryIds = app(ScopeAssignmentReader::class)->grantedEntityIds(
                $actor->identityReference,
                ScopeEntityType::CEMETERY,
            );

            if ($grantedCemeteryIds !== []) {
                return VisitationBooking::query()
                    ->whereIn('cemetery_id', $grantedCemeteryIds)
                    ->with('cemetery');
            }
        }

        return VisitationBooking::query()->with('cemetery');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVisitationBookings::route('/'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'permintaan kunjungan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Permintaan Kunjungan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Permintaan Kunjungan';
    }

    /**
     * The single deterministic `actor_role` value for this resource's
     * audit calls — same reasoning and shape as
     * `CemeteryVisitationPolicyResource::auditRoleFor()`.
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
