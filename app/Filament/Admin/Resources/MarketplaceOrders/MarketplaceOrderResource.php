<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MarketplaceOrders;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Filament\Admin\Resources\MarketplaceOrders\Pages\ListMarketplaceOrders;
use App\Filament\Admin\Resources\MarketplaceOrders\Pages\ViewMarketplaceOrder;
use App\Filament\Admin\Resources\MarketplaceOrders\Schemas\MarketplaceOrderInfolist;
use App\Filament\Admin\Resources\MarketplaceOrders\Tables\MarketplaceOrdersTable;
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
 * Admin management surface for the customer-side marketplace orders
 * (`marketplace_orders`, the marketplace-checkout lane's order root).
 * Read-only by construction: an order is written only by the domain
 * (`PlaceMarketplaceOrder` at placement, `MarkMarketplaceOrderPaid` at the
 * paid transition, and the payment webhook path), so this resource exposes
 * list + view pages and a single finance-gated header action
 * (`Actions\MarkMarketplaceOrderPaidAction`) — never create/edit/delete
 * rows.
 *
 * ---------------------------------------------------------------------------
 * Authorization — `canAccess()` AND `getAuthorizationResponse()`, both
 * delegating to the one shared authorizer
 * ---------------------------------------------------------------------------
 * Same shape as `CemeteryResource`/`ProductResource`/`ServiceDefinition
 * Resource`: `canAccess()` delegates to
 * `MasterDataAdminAuthorizerContract` (try/catch -> bool, fail-closed for
 * any actor without one of the four back-office roles), and
 * `getAuthorizationResponse()` is ALSO overridden because in Filament 5
 * (`Resources\Resource\Concerns\HasAuthorization`, verified against the
 * installed v5.7.3) every row-action predicate routes through it and would
 * otherwise fall through to Filament's no-policy allow.
 *
 * Note the money transitions themselves are NOT governed by this master-data
 * gate: `MarkMarketplaceOrderPaidAction` additionally authorizes
 * `mark_marketplace_order_paid` through `OrderTransitionAuthorizerContract`
 * (finance/admin only, Task 1's lane) and enforces recent re-authentication
 * via `ReauthenticationGuard` — the master-data gate here only decides
 * whether the resource exists for the actor at all.
 */
final class MarketplaceOrderResource extends Resource
{
    protected static ?string $model = MarketplaceOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'order_number';

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
            return Response::deny('Anda tidak berwenang mengelola pesanan marketplace.');
        }

        return Response::allow();
    }

    public static function infolist(Schema $schema): Schema
    {
        return MarketplaceOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MarketplaceOrdersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return MarketplaceOrder::query()
            ->with(['items', 'vendor'])
            ->withCount('items');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketplaceOrders::route('/'),
            'view' => ViewMarketplaceOrder::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'pesanan marketplace';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pesanan Marketplace';
    }

    public static function getNavigationLabel(): string
    {
        return 'Pesanan Marketplace';
    }

    /**
     * The single deterministic `actor_role` value for this resource's
     * `Audit::record()` calls. `audit_events.actor_role` is NOT NULL and
     * `ActorContext` has no single "the actor's role" field, so one is
     * derived here — the same reasoning and shape as
     * `CemeteryResource::auditRoleFor()`: walk the four back-office roles in
     * the master-data authorizer's precedence order, fall back to
     * `authenticated_actor` for an authenticated actor holding none, and
     * `guest` for a guest.
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
