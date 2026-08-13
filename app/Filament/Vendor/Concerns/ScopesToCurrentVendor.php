<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Concerns;

use App\Domain\Marketplace\Access\CurrentVendorScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Constrains a `/vendor` Resource's base query to the vendors the current
 * actor holds an active `scope_assignments` grant for.
 *
 * Every Resource in `App\Filament\Vendor\Resources` uses this, and
 * `tests/Feature/Filament/Vendor/VendorPanelScopingTest` fails CI if one
 * stops doing so. See `CurrentVendorScope`'s class doc block for why the
 * enforcement point is the panel boundary rather than a model global scope.
 *
 * ---------------------------------------------------------------------------
 * Why `getEloquentQuery()` and not a table filter
 * ---------------------------------------------------------------------------
 * `Filament\Resources\Resource::getEloquentQuery()` carries its own source
 * comment saying exactly this: "Security: Override this method to scope
 * queries to the current user's permissions. By default all records are
 * returned... Failing to scope in multi-user apps can expose unauthorized
 * records."
 *
 * It is the right seam because it is the ONLY query the resource's pages
 * share. The list table, the edit page's record resolution, and the delete
 * action all build from it — so scoping here also makes
 * `/vendor/products/{id}/edit` for another vendor's listing a 404 rather than
 * an edit form. A table-level filter would have hidden the row from the list
 * and left that URL open, which is the "UI hiding, not query-level scope"
 * failure this lane exists to close.
 */
trait ScopesToCurrentVendor
{
    /**
     * The column on this Resource's model that carries the owning vendor's id.
     * Every vendor-owned marketplace table names it `vendor_id`; a Resource
     * whose model reaches its vendor indirectly overrides
     * `applyVendorScope()` instead.
     */
    public static function vendorScopeColumn(): string
    {
        return 'vendor_id';
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function applyVendorScope(Builder $query): Builder
    {
        // whereIn() on an empty array compiles to an always-false clause, so a
        // guest and an actor with no vendor grant both see nothing. That is
        // the deliberate closed default — see CurrentVendorScope's doc block.
        return $query->whereIn(
            $query->qualifyColumn(static::vendorScopeColumn()),
            app(CurrentVendorScope::class)->grantedVendorIds(),
        );
    }

    public static function getEloquentQuery(): Builder
    {
        return static::applyVendorScope(parent::getEloquentQuery());
    }
}
