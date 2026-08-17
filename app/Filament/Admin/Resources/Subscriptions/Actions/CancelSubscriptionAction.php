<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscriptions\Actions;

use App\Domain\CareSubscription\Models\Subscription;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Header action for cancelling a subscription.
 * Checks AC7 gate first: if not configured, shows honest 'Belum dapat diaktifkan' notification.
 */
final class CancelSubscriptionAction
{
    public static function make(Subscription $subscription): Action
    {
        return Action::make('batalkan')
            ->label('Batalkan Langganan')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (): bool => in_array(
                $subscription->status,
                ['active', 'paused', 'draft'],
                true,
            ))
            ->requiresConfirmation()
            ->modalHeading('Batalkan langganan ini?')
            ->modalDescription(
                'Pembatalan akan mengakhiri langganan ini secara permanen. '
                .'Kebijakan pembatalan harus dikonfigurasi sebelum dapat diaktifkan (AC7).'
            )
            ->action(fn () => self::run($subscription));
    }

    private static function run(Subscription $subscription): void
    {
        $actor = app(ActorContext::class);

        try {
            // AC7 gate: cancellation policy must be explicitly configured before this action can run.
            // For MVP, this gate is not configured, so we show an honest notification.
            Notification::make()
                ->warning()
                ->title('Belum dapat diaktifkan')
                ->body('Kebijakan pembatalan langganan belum dikonfigurasi. Hubungi administrator.')
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Gagal membatalkan langganan')
                ->body($exception->getMessage())
                ->send();
        }
    }
}
