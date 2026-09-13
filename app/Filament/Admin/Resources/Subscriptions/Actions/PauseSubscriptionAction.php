<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscriptions\Actions;

use App\Domain\CareSubscription\Models\Subscription;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Header action placeholder for pausing a subscription.
 *
 * ARCH-11: this used to look like a working action — a confirmation modal,
 * a click handler, an audit-shaped `run()` method — that in fact always
 * refused via a hardcoded notification, regardless of subscription state or
 * any real check. That shipped both a working-looking control AND an
 * "honest stub" message at the same time, which is worse than either alone:
 * an operator has no way to tell "this is disabled because a policy is
 * missing" from "this is disabled because I lack permission" or "this is a
 * bug." `App\Domain\CareSubscription\Actions\PauseSubscription` (the real
 * domain action) is correct and tested on its own terms, but nothing in
 * this codebase implements the AC7 "pause policy configured" gate the old
 * doc block claimed to check — no configuration surface for a pause policy
 * exists yet. Building that gate is a real product/config decision, out of
 * scope for this fix (see this batch's plan doc,
 * `docs/superpowers/plans/2026-09-07-batchm5c-dead-code-and-static-analysis.md`).
 *
 * Until that policy configuration exists, this control stays disabled —
 * visibly inert, not a live control that pretends to act and then refuses.
 * No `->action()` handler, no confirmation modal: a disabled Filament
 * action never dispatches its handler, so there is nothing to "always
 * refuse" from.
 */
final class PauseSubscriptionAction
{
    public static function make(Subscription $subscription): Action
    {
        return Action::make('jeda')
            ->label('Jeda Langganan')
            ->icon(Heroicon::OutlinedPause)
            ->color('gray')
            ->visible(fn (): bool => $subscription->status === 'active')
            ->disabled()
            ->tooltip('Kebijakan penjedaan langganan belum dikonfigurasi. Hubungi administrator.');
    }
}
