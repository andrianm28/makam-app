<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductResource;

use App\Domain\Marketplace\Models\Product;
use App\Filament\Admin\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Admin\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Admin\Resources\ProductResource\Schemas\ProductForm;
use App\Filament\Admin\Resources\ProductResource\Tables\ProductsTable;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\Roles\ActorRole;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The admin resource for `products` — the marketplace catalogue's master
 * data (`admin-managed-master-data` design spec; plan Task 5). Follows the
 * `FaqArticles/` layout exactly: Resource + `Schemas/` + `Tables/` +
 * `Pages/` split.
 *
 * ---------------------------------------------------------------------------
 * Authorization — `canAccess()` only, and why that is a complete gate here
 * ---------------------------------------------------------------------------
 * Every `Filament\Resources\Pages\Page` mounts and hydrates through
 * `CanAuthorizeResourceAccess`, which aborts with 403 unless
 * `Resource::canAccess()` passes — so the list, create, and edit pages are
 * each re-checked on every Livewire request, not just at first load. That
 * single method is the whole gate: the `MasterDataAdminAuthorizer` refuses
 * every actor without one of the four back-office roles (admin,
 * restricted_admin, operator, finance), fail-closed, so a bare customer or
 * guest never reaches any page or action this resource exposes.
 *
 * The table's row `EditAction` is checked by Filament through the resource's
 * per-ability `getAuthorizationResponse()`, whose default policy path allows
 * (no `ProductPolicy` exists, and none may be added — the same reasoning as
 * `FaqArticleResource`'s doc block). That is not a hole here: the row action
 * is only reachable from the list page, and the list page 403s before the
 * table renders for any actor `canAccess()` refuses. The FaqArticles
 * resource overrides `getAuthorizationResponse()` instead of `canAccess()`
 * because its resource also gates on per-ability distinctions; this one has
 * a single all-or-nothing decision, so `canAccess()` is the honest single
 * place to state it.
 *
 * ---------------------------------------------------------------------------
 * Writes stay on the Eloquent path — no raw bypass
 * ---------------------------------------------------------------------------
 * Create/update run through the model (`saving` hook: `ProductCode::
 * assertKnown` / `MarketplaceProductCategory::assertKnown`), never a raw
 * insert, and both are wrapped in `Audit::wrap()` so the row change and its
 * audit record commit together (AC4). Base-price edits bump `price_version`
 * in the page's save path — see `Pages\EditProduct`.
 */
final class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    /**
     * Explicit — the resource lives in a `ProductResource/` directory (the
     * plan's Task 5 file list), and Filament's default slug derivation would
     * otherwise render `product-resource/products` as the route prefix.
     */
    protected static ?string $slug = 'products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));

            return true;
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }
    }

    /**
     * The actor's role as recorded on `audit_events` — the first of
     * `ActorRole::KNOWN_ROLES` (declaration order is precedence order, most
     * privileged first) the actor holds. The pages that write audit rows
     * are only reachable by the four back-office roles, so the first-match
     * role is always one of them in practice; the authenticated/guest
     * fallbacks mirror `RecordPaymentActionRefusal::auditRoleFor()` and
     * keep this honest for any future non-panel caller.
     */
    public static function auditRoleFor(ActorContext $actor): string
    {
        foreach (ActorRole::KNOWN_ROLES as $role) {
            if ($actor->hasRole($role)) {
                return $role;
            }
        }

        return $actor->isAuthenticated() ? 'authenticated_actor' : 'guest';
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'produk layanan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Produk Layanan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Produk Layanan';
    }
}
