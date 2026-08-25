<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RenewalOrders;

use App\Domain\Renewal\Models\Renewal;
use App\Filament\Admin\Resources\RenewalOrders\Pages\ListRenewalOrders;
use App\Filament\Admin\Resources\RenewalOrders\Pages\ViewRenewalOrder;
use App\Filament\Admin\Resources\RenewalOrders\Schemas\RenewalOrderInfolist;
use App\Filament\Admin\Resources\RenewalOrders\Tables\RenewalOrdersTable;
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
 * Admin management surface for `renewals` — the P1 renewal lane's view of
 * the family renewal journey (`MENUNGGU_PEMBAYARAN` / `DIBAYAR` /
 * `KEDALUWARSA`), with the two privileged header actions on the View page:
 * recording an externally-settled payment (finance + fresh re-auth) and
 * expiring an open renewal (operator). Same layout and same access gate as
 * the sibling order resources (BookingOrderResource, MarketplaceOrderResource):
 * Resource + Schemas + Pages + Tables + Actions subdirectories under
 * `Resources/RenewalOrders/`, one `canAccess()` + one
 * `getAuthorizationResponse()` both delegating to the shared
 * `MasterDataAdminAuthorizerContract` four-role gate, and one
 * `auditRoleFor()` walking that gate's fixed role precedence for the
 * `Audit::record()` calls the two header actions make.
 */
final class RenewalOrderResource extends Resource
{
    protected static ?string $model = Renewal::class;

    protected static ?string $slug = 'pesanan-perpanjangan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

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
            return Response::deny('Anda tidak berwenang mengelola perpanjangan makam.');
        }

        return Response::allow();
    }

    public static function infolist(Schema $schema): Schema
    {
        return RenewalOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RenewalOrdersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return Renewal::query()->with('graveRecord')->with('quotes');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRenewalOrders::route('/'),
            'view' => ViewRenewalOrder::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'perpanjangan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Perpanjangan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Perpanjangan';
    }

    /**
     * The single deterministic `actor_role` value for this resource's
     * `Audit::record()` calls — same reasoning and shape as the sibling
     * order resources: `audit_events.actor_role` is NOT NULL, `ActorContext`
     * has no single role field, so the authorizer's own fixed four-role
     * precedence order is walked and the first match reported. Falls back
     * to `authenticated_actor` for an authenticated actor holding no
     * back-office role and `guest` for a guest.
     */
    public static function auditRoleFor(ActorContext $actor): string
    {
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

        return $actor->isAuthenticated() ? 'authenticated_actor' : 'guest';
    }
}
