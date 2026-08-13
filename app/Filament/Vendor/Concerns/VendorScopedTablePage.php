<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Concerns;

use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared wiring for the three read-only `/vendor` pages that are a table over
 * a vendor-scoped query and nothing else: transaction history, payout status,
 * and evidence.
 *
 * These are `Filament\Pages\Page` subclasses rather than Resources because
 * none of them is a CRUD surface — there is no create, edit, or delete, and no
 * record a vendor manages. The same reasoning `App\Filament\Admin\Pages\
 * InAppNotifications` records for the admin inbox.
 *
 * A page using this trait MUST implement `vendorScopedQuery()`, and that query
 * MUST be constrained to `CurrentVendorScope::grantedVendorIds()`. There is no
 * default implementation on purpose: the three pages reach their vendor by
 * different routes (two carry `vendor_id` directly, evidence reaches it
 * through its order), and a guessed default that silently returned an
 * unscoped query would be exactly the failure this lane exists to close.
 * `tests/Feature/Filament/Vendor/VendorPanelScopingTest` asserts every page in
 * the panel uses this trait and that each one's query is actually closed for
 * an actor with no grant.
 */
trait VendorScopedTablePage
{
    use InteractsWithTable;

    /**
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    abstract public static function vendorScopedQuery(): Builder;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    protected function getTableQuery(): Builder
    {
        return static::vendorScopedQuery();
    }
}
