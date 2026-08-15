<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RenewalOrders\Actions;

use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\Renewal\Actions\ExpireRenewal;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalStatus;
use App\Filament\Admin\Resources\RenewalOrders\RenewalOrderResource;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The View page's expiry header action for `RenewalOrderResource` — an
 * authorised operator/admin closes a `MENUNGGU_PEMBAYARAN` renewal window
 * without payment (`RenewalStatus::KEDALUWARSA`) through
 * `App\Domain\Renewal\Actions\ExpireRenewal`, which records the decision
 * in the audit trail.
 *
 * `expire_renewal` is a non-money transition, so the authorizer admits
 * operator and restricted_admin — no `ReauthenticationGuard` here. The
 * reason field is optional: `RENEWAL_EXPIRED` is deliberately not on
 * `SensitiveActions::ACTIONS`, so `Audit::wrap()` never requires one.
 */
final class ExpireRenewalAction
{
    private const string TRANSITION = 'expire_renewal';

    public static function make(Renewal $renewal): Action
    {
        return Action::make('expire_renewal')
            ->label('Kedaluwarsakan')
            ->color('danger')
            ->icon(Heroicon::OutlinedXCircle)
            ->requiresConfirmation()
            ->modalHeading('Konfirmasi kedaluwarsa')
            ->modalDescription('Renewal yang dibiarkan tanpa pembayaran akan ditutup dan dicatat di audit.')
            ->schema([
                Textarea::make('reason')
                    ->label('Alasan (opsional)')
                    ->rows(2),
            ])
            ->authorize(fn (): bool => self::authorized())
            ->visible(fn (Renewal $record): bool => $record->status === RenewalStatus::MENUNGGU_PEMBAYARAN)
            ->action(function (array $data) use ($renewal): void {
                $actor = app(ActorContext::class);

                try {
                    app(OrderTransitionAuthorizerContract::class)->authorizeTransition($actor, self::TRANSITION);
                } catch (\Throwable $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                $actorRef = $actor->identityReference;
                $actorRole = RenewalOrderResource::auditRoleFor($actor);

                try {
                    app(ExpireRenewal::class)(
                        $renewal,
                        (string) $actorRef,
                        $actorRole,
                        filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                    );
                    Notification::make()->success()->title('Renewal kedaluwarsa.')->send();
                } catch (\Throwable $exception) {
                    Notification::make()->danger()->title('Gagal mengubah status')->body($exception->getMessage())->send();
                }
            });
    }

    private static function authorized(): bool
    {
        try {
            app(OrderTransitionAuthorizerContract::class)->authorizeTransition(app(ActorContext::class), self::TRANSITION);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
