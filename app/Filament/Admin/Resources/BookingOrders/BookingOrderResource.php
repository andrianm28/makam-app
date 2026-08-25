<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders;

use App\Domain\OrderWorkflow\Models\Order;
use App\Filament\Admin\Resources\BookingOrders\Pages\EditBookingOrder;
use App\Filament\Admin\Resources\BookingOrders\Pages\ListBookingOrders;
use App\Filament\Admin\Resources\BookingOrders\Pages\ViewBookingOrder;
use App\Filament\Admin\Resources\BookingOrders\Schemas\BookingOrderEditForm;
use App\Filament\Admin\Resources\BookingOrders\Schemas\BookingOrderInfolist;
use App\Filament\Admin\Resources\BookingOrders\Tables\BookingOrdersTable;
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
 * Admin management surface for the commercial order lifecycle
 * (`orders` + its status-event history) — the P1
 * `admin-order-management` plan's Task 4 resource.
 *
 * ---------------------------------------------------------------------------
 * Authorization: same shape as `CemeteryResource` — one shared
 * `MasterDataAdminAuthorizerContract` gate answered twice
 * ---------------------------------------------------------------------------
 * `canAccess()` is the predicate the panel's page-mount guard and navigation
 * consult; `getAuthorizationResponse()` is ALSO overridden (as
 * `CemeteryResource` documents for `FaqArticleResource`) because in Filament 5
 * every row-action predicate routes through it and, without the override,
 * would fall through to Filament's no-policy allow. Both answer the same
 * question from the same authorizer, so they cannot disagree.
 *
 * The four roles admitted by `MasterDataAdminAuthorizer` (admin,
 * restricted_admin, operator, finance) are exactly the roles whose
 * `actor_role` value `auditRoleFor()` must be able to derive — that method
 * walks the same fixed precedence order and reports the first match, the
 * `CemeteryResource` precedent.
 *
 * ---------------------------------------------------------------------------
 * Append-only records: no create/edit/delete beyond the dedicated pages
 * ---------------------------------------------------------------------------
 * `App\Domain\OrderWorkflow\Models\Order`'s write guard makes `update()` and
 * `delete()` throw for every caller except the two status/paid-source doors
 * (see the model's own class doc block). So this Resource exposes exactly:
 * the list page, the view page (with the dynamic transition actions), and a
 * minimal non-financial edit page whose save button is hidden — see
 * `Pages\EditBookingOrder` for why.
 *
 * `status` moves ONLY through `TransitionOrderAction` (which routes through
 * `OrderTransitionAuthorizerContract` + `ReauthenticationGuard` and then the
 * domain Actions) — never through an Eloquent write from this Resource.
 */
final class BookingOrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $slug = 'pesanan-pemakaman';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

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
            return Response::deny('Anda tidak berwenang mengelola pesanan.');
        }
    }

    public static function infolist(Schema $schema): Schema
    {
        return BookingOrderInfolist::configure($schema);
    }

    public static function form(Schema $schema): Schema
    {
        return BookingOrderEditForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BookingOrdersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return Order::query()->with('bookingDraft');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookingOrders::route('/'),
            'view' => ViewBookingOrder::route('/{record}'),
            'edit' => EditBookingOrder::route('/{record}/edit'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'pesanan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pesanan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Pesanan';
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
}
