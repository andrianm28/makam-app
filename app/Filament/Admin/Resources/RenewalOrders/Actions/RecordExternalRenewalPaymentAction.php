<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RenewalOrders\Actions;

use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\Renewal\Actions\MarkRenewalPaidExternally;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalStatus;
use App\Filament\Admin\Resources\RenewalOrders\RenewalOrderResource;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The View page's money header action for `RenewalOrderResource` — an
 * authorised finance/admin actor records that a `MENUNGGU_PEMBAYARAN`
 * renewal was settled by payment outside the platform (AC10), with the
 * evidence and the mandatory reason (`RENEWAL_EXTERNAL_MARKING` is on
 * `SensitiveActions::ACTIONS`, so `MarkRenewalPaidExternally` refuses a
 * blank one and `Audit::wrap()` would too).
 *
 * Two enforcement layers, mirroring the sibling order resources:
 * `->authorize()` gates whether the button renders and mounts, and the
 * `->action()` closure re-checks the transition authorizer AND the
 * `ReauthenticationGuard` (money transition — recent re-authentication
 * required) before the domain action runs.
 */
final class RecordExternalRenewalPaymentAction
{
    private const string TRANSITION = 'record_external_renewal_payment';

    public static function make(Renewal $renewal): Action
    {
        return Action::make('record_external_payment')
            ->label('Catat Pembayaran Eksternal')
            ->color('warning')
            ->icon(Heroicon::OutlinedBanknotes)
            ->requiresConfirmation()
            ->modalHeading('Konfirmasi pembayaran eksternal')
            ->modalDescription('Renewal ditandai dibayar di luar platform dan dicatat di audit.')
            ->schema([
                Textarea::make('evidence')
                    ->label('Bukti')
                    ->rows(2)
                    ->required(),

                Textarea::make('reason')
                    ->label('Alasan')
                    ->rows(2)
                    ->required(),
            ])
            ->authorize(fn (): bool => self::authorized())
            ->visible(fn (Renewal $record): bool => $record->status === RenewalStatus::MENUNGGU_PEMBAYARAN)
            ->action(function (array $data) use ($renewal): void {
                $actor = app(ActorContext::class);
                $actorRef = $actor->identityReference;
                $actorRole = RenewalOrderResource::auditRoleFor($actor);

                try {
                    app(OrderTransitionAuthorizerContract::class)->authorizeTransition($actor, self::TRANSITION);
                    app(ReauthenticationGuard::class)->assertFresh($actor);
                } catch (ReauthenticationRequiredException) {
                    Notification::make()
                        ->warning()
                        ->title('Perlu verifikasi ulang')
                        ->body('Lakukan verifikasi ulang untuk tindakan ini.')
                        ->send();

                    session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, 'money_action');
                    session()->put('url.intended', route('filament.admin.resources.renewal-orders.view', ['record' => $renewal->getKey()]));
                    redirect()->route('filament.admin.pages.mfa-challenge');

                    return;
                } catch (\Throwable $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                try {
                    app(MarkRenewalPaidExternally::class)(
                        $renewal,
                        (string) $data['evidence'],
                        (string) $data['reason'],
                        (string) $actorRef,
                        $actorRole,
                    );
                    Notification::make()->success()->title('Pembayaran eksternal dicatat.')->send();
                } catch (\Throwable $exception) {
                    Notification::make()->danger()->title('Gagal mencatat pembayaran')->body($exception->getMessage())->send();
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
