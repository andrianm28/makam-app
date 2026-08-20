<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditEvents;

use App\Filament\Admin\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Admin\Resources\AuditEvents\Pages\ViewAuditEvent;
use App\Filament\Admin\Resources\AuditEvents\Schemas\AuditEventInfolist;
use App\Filament\Admin\Resources\AuditEvents\Tables\AuditEventsTable;
use App\Platform\Audit\Contracts\AuditReadAuthorizer;
use App\Platform\Audit\Exceptions\AuditReadNotAuthorisedException;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\ActorContext;
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
 * ADM-100 (`.kiro/specs/admin-operations/requirements.md` AC8, AC10) — the
 * admin surface for reviewing `audit_events`, the one table every sensitive
 * mutation across this platform writes to (`App\Platform\Audit\Audit`'s own
 * doc block). Read-only by construction: `getPages()` below registers only
 * `index`/`view`, no `create`/`edit`, so Filament never mounts a form that
 * could write to this model — which would fail anyway, since `AuditEvent`
 * itself throws on `update()`/`performUpdate()`/`delete()` (its own
 * class-level doc block, AC1).
 *
 * ---------------------------------------------------------------------------
 * Access: the same "coarse mount gate + row-level query scope" shape every
 * other authorizer-backed resource in this codebase uses
 * ---------------------------------------------------------------------------
 * `canAccess()` (page mount / navigation) and `getAuthorizationResponse()`
 * (every action predicate Filament asks) both resolve
 * `Contracts\AuditReadAuthorizer` — same two-method shape
 * `CertificatesResource` establishes for `MasterDataAdminAuthorizerContract`.
 * `getEloquentQuery()` applies the SAME authorizer's
 * `AuditReadScope::$excludedActions` as a `whereNotIn('action', …)` filter,
 * so a `restricted_admin` who reaches the list or view page never sees a
 * row `getAuthorizationResponse()` would also have denied acting on — the
 * mount gate and the query scope cannot disagree, because both read the same
 * authorizer call. See `RoleBasedAuditReadAuthorizer`'s own doc block for
 * which roles are admitted and why, and for the reasoning behind the
 * action-level (not business-entity) scope shape.
 */
final class AuditEventsResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'action';

    public static function canAccess(): bool
    {
        try {
            app(AuditReadAuthorizer::class)->authorize(app(ActorContext::class));
        } catch (AuditReadNotAuthorisedException) {
            return false;
        }

        return true;
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        try {
            app(AuditReadAuthorizer::class)->authorize(app(ActorContext::class));

            return Response::allow();
        } catch (AuditReadNotAuthorisedException) {
            return Response::deny('Anda tidak berwenang meninjau jejak audit.');
        }
    }

    public static function table(Table $table): Table
    {
        return AuditEventsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AuditEventInfolist::configure($schema);
    }

    /**
     * Newest event first, filtered to whatever
     * `Contracts\AuditReadAuthorizer` excludes for the current actor's role.
     * An unauthorised actor never reaches this method in practice
     * (`canAccess()`/`getAuthorizationResponse()` gate the page first), but
     * this still fails closed on its own: a refusal here throws
     * `AuditReadNotAuthorisedException`, `abort(403)`, rather than silently
     * falling through to an unfiltered query.
     */
    public static function getEloquentQuery(): Builder
    {
        try {
            $scope = app(AuditReadAuthorizer::class)->authorize(app(ActorContext::class));
        } catch (AuditReadNotAuthorisedException) {
            abort(403);
        }

        return AuditEvent::query()
            ->when(
                $scope->excludedActions !== [],
                fn (Builder $query): Builder => $query->whereNotIn('action', $scope->excludedActions),
            )
            ->latest('occurred_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEvents::route('/'),
            'view' => ViewAuditEvent::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'peristiwa audit';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Jejak Audit';
    }

    public static function getNavigationLabel(): string
    {
        return 'Jejak Audit';
    }
}
