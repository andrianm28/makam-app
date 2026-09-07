<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscriptions\Actions;

use App\Domain\CareSubscription\Models\Subscription;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Header action placeholder for cancelling a subscription.
 *
 * ARCH-11: see `PauseSubscriptionAction`'s doc block for the full
 * reasoning — this class had the identical problem (a working-looking
 * confirmation-modal action that always refused via a hardcoded
 * notification) and the identical fix. No configuration surface for a
 * cancellation policy exists yet, so `App\Domain\CareSubscription\Actions
 * \CancelSubscription` (the real, tested domain action) has nothing to be
 * gated on; building that gate is a real product/config decision, out of
 * scope for this fix. This control stays disabled until it does.
 */
final class CancelSubscriptionAction
{
    public static function make(Subscription $subscription): Action
    {
        return Action::make('batalkan')
            ->label('Batalkan Langganan')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->visible(fn (): bool => in_array(
                $subscription->status,
                ['active', 'paused', 'draft'],
                true,
            ))
            ->disabled()
            ->tooltip('Kebijakan pembatalan langganan belum dikonfigurasi. Hubungi administrator.');
    }
}
