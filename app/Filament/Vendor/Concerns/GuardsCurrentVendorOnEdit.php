<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Concerns;

use App\Domain\Marketplace\Access\CurrentVendorScope;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The edit-path counterpart of `StampsCurrentVendor`.
 *
 * `ScopesToCurrentVendor` closes the READ side of edit: a record resolved
 * through the scoped `getEloquentQuery()` is already inside the actor's own
 * grants, and another vendor's record is a 404. That alone does not close the
 * WRITE side: `vendor_id` is `$fillable` on every vendor-owned model, so an
 * edit form posting a forged `vendor_id` for a vendor the actor does NOT hold
 * would silently move the record into that vendor's catalogue or calendar.
 *
 * In Filament 5.7.3 the edit forms are already refused by the form itself:
 * `Select::getInValidationRuleValues()` (Select.php) derives the `in:` values
 * from `options()`, and `VendorPicker` only offers granted vendors, so a
 * forged value fails validation ("The selected vendor is invalid") and no
 * write occurs. That derivation is an implementation detail, though — it is
 * not a stated API contract and it could change or be bypassed in a future
 * Filament release. `allows()` below is the explicit authorization decision,
 * and it re-reads the grant table rather than trusting anything carried in the
 * request — the same seam `StampsCurrentVendor` uses on create. It is
 * defence in depth: it does not replace the form's validation, it makes the
 * edit write correct by construction no matter what the form layer does.
 *
 * A submitted `vendor_id` inside the actor's own grants is preserved. A blank
 * or absent `vendor_id` (the picker never rendered for a single-grant actor,
 * or the actor left it unset) is dropped from the payload so the record keeps
 * its current owner — which is inside the actor's scope by construction,
 * because the record was resolved through the scoped query.
 */
trait GuardsCurrentVendorOnEdit
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws AuthorizationException when the submitted `vendor_id` is not one
     *                                the current actor holds an active grant for.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $submitted = $data['vendor_id'] ?? null;

        if (is_string($submitted) && $submitted !== '') {
            if (! app(CurrentVendorScope::class)->allows($submitted)) {
                throw new AuthorizationException(
                    'Anda tidak berwenang mengubah data vendor ini.'
                );
            }

            return $data;
        }

        // The picker either never rendered (single-grant actor) or was left
        // unset. Preserve the record's current owner — it is inside the actor's
        // scope because the record itself was resolved through the vendor-scoped
        // query — and drop the blank value so it can never reach the database.
        unset($data['vendor_id']);

        return $data;
    }
}
