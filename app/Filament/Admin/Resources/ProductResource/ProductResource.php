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
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The admin resource for `products` — the marketplace catalogue's master
 * data (`admin-managed-master-data` design spec; plan Task 5). Follows the
 * `FaqArticles/` layout exactly: Resource + `Schemas/` + `Tables/` +
 * `Pages/` split.
 *
 * ---------------------------------------------------------------------------
 * Authorization — `canAccess()` AND `getAuthorizationResponse()`, both
 * delegating to the one shared authorizer
 * ---------------------------------------------------------------------------
 * `canAccess()` is overridden per the spec's "same pattern as
 * `FinanceReports::canAccess()`" instruction — authorizer try/catch -> bool,
 * fail-closed for any actor without one of the four back-office roles
 * (admin, restricted_admin, operator, finance). It is the predicate the
 * panel's page-mount guard (`CanAuthorizeResourceAccess`) and the
 * navigation renderer consult, and `Filament\Resources\Pages\Page` mounts
 * and hydrates through it on every Livewire request — so the list, create,
 * and edit pages are hard-gated by that one method, never just at first
 * load.
 *
 * `getAuthorizationResponse()` is ALSO overridden, exactly as
 * `CemeteryResource` and `ServiceDefinitionResource` do, because in Filament
 * 5 (`Resources\Resource\Concerns\HasAuthorization`, verified against the
 * installed v5.7.3) every row-action predicate (`getEditAuthorization
 * Response()`, `getDeleteAuthorizationResponse()`, ...) routes through it
 * and — without the override — would fall through to Filament's no-policy
 * allow for every panel user, bypassing the master-data gate at the row
 * level. Both methods answer the same question from the same authorizer, so
 * they cannot disagree.
 *
 * Relation managers are the one component type that mounts WITHOUT the page
 * gate (`CanAuthorizeResourceAccess` only guards `Pages\Page` subclasses),
 * so those carry their own hardening — see `PriceVersionsRelationManager`
 * and `PackagesRelationManager`, which override `canViewForRecord()` and
 * put `->authorize(...)` on their actions. `ProductResource` has no
 * relation manager today; if one is ever added it must do the same.
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

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return Response::deny('Anda tidak berwenang mengelola produk layanan.');
        }

        return Response::allow();
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
