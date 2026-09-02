<?php

declare(strict_types=1);

namespace App\Domain\Renewal;

use Illuminate\Support\Facades\Session;

/**
 * Carries the grave a visitor selected from Screen 1's search results
 * (`RenewalStart`, after the `GraveSearch` merge) across the redirect to
 * Screen 2 (`RenewalPayment`, after the `RenewalFee` merge), server-side
 * only.
 *
 * Never a `#[Url]`-bound property and never a query parameter.
 * `GraveRecordProjection` deliberately carries no `id` at all — a public
 * search result must never leak an addressable id an attacker could
 * enumerate (its own doc block) — and putting the id in the URL to bridge
 * the two screens would reopen exactly that tradeoff. The PHP session is
 * this visitor's own storage, keyed to their session cookie, never rendered
 * into a Blade view and never a Livewire component's public property (so it
 * is never part of Livewire's serialised client payload either).
 *
 * Same shape and reasoning as `App\Domain\Booking\BookingDraftBinding`'s use
 * of the session for state that must survive a redirect without becoming a
 * client-visible identifier — see that class's own doc block.
 */
final class RenewalGraveSelection
{
    private const string SESSION_KEY = 'renewal.selected_grave_id';

    public static function remember(string $graveId): void
    {
        Session::put(self::SESSION_KEY, $graveId);
    }

    public static function current(): ?string
    {
        $value = Session::get(self::SESSION_KEY);

        return is_string($value) ? $value : null;
    }

    public static function forget(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
