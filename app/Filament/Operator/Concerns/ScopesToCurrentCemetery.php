<?php

declare(strict_types=1);

namespace App\Filament\Operator\Concerns;

use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Constrains an `/operator` Resource's base query to the cemeteries the
 * current actor holds an active `scope_assignments` grant for.
 *
 * Mirrors `App\Filament\Vendor\Concerns\ScopesToCurrentVendor` exactly — see
 * that trait's own doc block for why `getEloquentQuery()` (not a table
 * filter) is the right seam: it is the one query every page in a Resource
 * shares, so scoping here also makes a direct URL to another cemetery's
 * record a 404 rather than an open edit form.
 *
 * `App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource`
 * (Phase C) is the first and currently only consumer. As anticipated here
 * when Phase A shipped the mechanism, it reaches its cemetery indirectly via
 * `bookingDraft.cemetery_id` and therefore overrides `applyCemeteryScope()`
 * with a subquery rather than relying on this trait's direct-column default;
 * `cemeteryScopeColumn()` is unused by it. A future Resource on a table with
 * a real `cemetery_id` column inherits both defaults unchanged.
 */
trait ScopesToCurrentCemetery
{
    /**
     * The column on this Resource's model that carries the owning
     * cemetery's id. Every cemetery-owned table in this codebase names it
     * `cemetery_id` (`grave_records`, `booking_drafts`, `cemetery_packages`,
     * `cemetery_blocks`); a Resource whose model reaches its cemetery
     * indirectly overrides `applyCemeteryScope()` instead.
     */
    public static function cemeteryScopeColumn(): string
    {
        return 'cemetery_id';
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function applyCemeteryScope(Builder $query): Builder
    {
        // whereIn() on an empty array compiles to an always-false clause, so
        // a guest and an actor with no cemetery grant both see nothing —
        // the deliberate closed default, see CurrentCemeteryScope's doc
        // block.
        return $query->whereIn(
            $query->qualifyColumn(static::cemeteryScopeColumn()),
            app(CurrentCemeteryScope::class)->grantedCemeteryIds(),
        );
    }

    public static function getEloquentQuery(): Builder
    {
        return static::applyCemeteryScope(parent::getEloquentQuery());
    }
}
