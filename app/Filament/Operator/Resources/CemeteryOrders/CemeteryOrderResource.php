<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\CemeteryOrders;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use App\Domain\OrderWorkflow\Models\Order;
use App\Filament\Admin\Resources\BookingOrders\Schemas\BookingOrderInfolist;
use App\Filament\Admin\Resources\BookingOrders\Tables\BookingOrdersTable;
use App\Filament\Operator\Concerns\ScopesToCurrentCemetery;
use App\Filament\Operator\Resources\CemeteryOrders\Pages\ListCemeteryOrders;
use App\Filament\Operator\Resources\CemeteryOrders\Pages\ViewCemeteryOrder;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Cemetery\CemeteryOrderAccessPolicy;
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
 * `/operator/pesanan` — the cemetery operator's orders dashboard, the TPU/TPS
 * operator dashboard roadmap's Phase C.
 *
 * ---------------------------------------------------------------------------
 * Its own gate, deliberately NOT `BookingOrderResource`'s
 * ---------------------------------------------------------------------------
 * The `/admin` twin delegates to `MasterDataAdminAuthorizerContract`, which
 * performs no record scoping (its own doc block: master data "is
 * platform-wide: there is no record scope to check") and whose
 * `getEloquentQuery()` is correspondingly unscoped. Reusing either here —
 * or adding `cemetery_operator` to that authorizer's role list — would grant
 * an operator authorization over every cemetery's orders. So this resource
 * carries `CemeteryOrderAccessPolicy` (role + at least one active cemetery
 * grant) instead. See that class's doc block for the full argument.
 *
 * `getAuthorizationResponse()` is overridden alongside `canAccess()` for the
 * reason `BookingOrderResource` documents: in Filament 5 every row-action
 * predicate routes through it and, without the override, would fall through
 * to Filament's no-policy allow. Both answer from the same policy, so they
 * cannot disagree.
 *
 * ---------------------------------------------------------------------------
 * The scope column is two hops away, so `applyCemeteryScope()` is overridden
 * ---------------------------------------------------------------------------
 * `orders` has no `cemetery_id`. The chain is
 * `orders.booking_draft_id -> booking_drafts.id`, and `booking_drafts
 * .cemetery_id` is the real column. A subquery rather than a join, on
 * purpose: it keeps `orders` as the single base table, so record
 * resolution, sorting, searching and `BookingOrdersTable`'s own `whereHas`
 * filters all keep working against unqualified column names. A join would
 * force the SHARED table builder to qualify every column, coupling it to
 * this one resource.
 *
 * An order with a NULL `booking_draft_id` is excluded by construction, which
 * is correct: with no draft it has no cemetery, so no cemetery operator owns
 * it.
 *
 * ---------------------------------------------------------------------------
 * First cross-panel reuse of the `/admin` table and infolist builders
 * ---------------------------------------------------------------------------
 * `BookingOrdersTable::configure()` and `BookingOrderInfolist::configure()`
 * are plain panel-agnostic statics with no panel, actor or Resource
 * coupling, so they are reused VERBATIM here. There is no prior precedent in
 * this codebase for a `/operator` or `/vendor` resource calling an
 * `App\Filament\Admin\...` builder — this is the first, and it is safe
 * exactly because of that absence of coupling. Any future change to either
 * builder that reads the current panel or an actor would silently break this
 * resource's scoping guarantees; both classes' doc blocks say so.
 */
final class CemeteryOrderResource extends Resource
{
    use ScopesToCurrentCemetery;

    protected static ?string $model = Order::class;

    protected static ?string $slug = 'pesanan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return app(CemeteryOrderAccessPolicy::class)->allows(app(ActorContext::class));
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return self::canAccess()
            ? Response::allow()
            : Response::deny('Anda tidak berwenang mengelola pesanan makam ini.');
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public static function applyCemeteryScope(Builder $query): Builder
    {
        // whereIn() on an empty grant list compiles to an always-false
        // clause, so a guest and an ungranted actor both see nothing — the
        // deliberate closed default `CurrentCemeteryScope` documents.
        return $query->whereIn(
            $query->qualifyColumn('booking_draft_id'),
            BookingDraft::query()
                ->whereIn('cemetery_id', app(CurrentCemeteryScope::class)->grantedCemeteryIds())
                ->select('id'),
        );
    }

    public static function infolist(Schema $schema): Schema
    {
        return BookingOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BookingOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCemeteryOrders::route('/'),
            'view' => ViewCemeteryOrder::route('/{record}'),
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
}
