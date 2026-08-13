<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Concerns;

use App\Domain\Marketplace\Access\CurrentVendorScope;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Decides the `vendor_id` a newly created `/vendor` record is filed under, on
 * the server, from `scope_assignments` — never from whatever the form posted.
 *
 * `ScopesToCurrentVendor` closes the READ side: an actor cannot see or edit
 * another vendor's rows. This closes the WRITE side, which is a separate hole:
 * without it an actor granted vendor A could post `vendor_id = B` and create a
 * listing, a service area, or a calendar entry inside vendor B's records —
 * rows they would then immediately lose sight of, but which would be live in
 * B's catalogue and on B's calendar.
 *
 * The create forms offer a vendor picker only when the actor holds more than
 * one grant, and it only ever lists granted vendors. That is a usability
 * affordance, not the control: Livewire will accept any value posted for a
 * form field regardless of the options the server rendered. `allows()` below
 * is the authorization decision, and it re-reads the grant table rather than
 * trusting anything carried in the request.
 */
trait StampsCurrentVendor
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws AuthorizationException when the resolved vendor is not one the
     *                                current actor holds an active grant for —
     *                                including the "actor holds several grants
     *                                and named none" case, which is a genuinely
     *                                unanswered question and not something to
     *                                guess at.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $scope = app(CurrentVendorScope::class);

        $submitted = $data['vendor_id'] ?? null;
        $vendorId = is_string($submitted) && $submitted !== ''
            ? $submitted
            : $scope->defaultVendorId();

        if (! $scope->allows($vendorId)) {
            throw new AuthorizationException(
                'Anda tidak berwenang membuat data untuk vendor ini.'
            );
        }

        $data['vendor_id'] = $vendorId;

        return $data;
    }
}
