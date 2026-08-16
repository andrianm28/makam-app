<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

/**
 * The audit action names for the admin panel's writes to the vendor master
 * data (`vendors`, `vendor_users`, `vendor_listings`,
 * `vendor_availability`) — written by `App\Filament\Admin\Resources\Vendors\*`
 * via `Audit::record()` (through `Audit::wrap()`), the only write paths the
 * VendorResource exposes.
 *
 * Mirrors `App\Domain\CemeteryCapability\CemeteryPackageAuditActions`'s
 * shape: one constants class per resource family, named constants (not
 * inline string literals) so tests reference the same values the panel
 * actually emits.
 *
 * ---------------------------------------------------------------------------
 * Deliberately NOT added to `App\Platform\Audit\SensitiveActions::ACTIONS`
 * ---------------------------------------------------------------------------
 * `SensitiveActions`'s own doc block defines that list narrowly: actions
 * with real fraud/harm risk if performed without a recorded justification
 * (vendor payout, plot override, manual payment verification, gate
 * change, ...). Creating/editing a vendor row, adding or revoking a member,
 * and editing a listing or schedule day are routine master-data changes —
 * an error is fixable by editing again, they touch no money, and there is
 * no human-authored "reason" a mandatory-reason gate would meaningfully
 * extract. The same reasoning `MarketplaceAuditActions` applies to order
 * status changes applies here. Every write still calls `Audit::record()`
 * (not skipped) so a complete "who changed which vendor row, when" history
 * exists.
 */
final class VendorAuditActions
{
    public const string VENDOR_CREATED = 'VENDOR_CREATED';

    public const string VENDOR_UPDATED = 'VENDOR_UPDATED';

    public const string VENDOR_DELETED = 'VENDOR_DELETED';

    public const string MEMBER_ADDED = 'MEMBER_ADDED';

    public const string MEMBER_REVOKED = 'MEMBER_REVOKED';

    public const string LISTING_CREATED = 'LISTING_CREATED';

    public const string LISTING_UPDATED = 'LISTING_UPDATED';

    public const string AVAILABILITY_CREATED = 'AVAILABILITY_CREATED';

    public const string AVAILABILITY_UPDATED = 'AVAILABILITY_UPDATED';
}
