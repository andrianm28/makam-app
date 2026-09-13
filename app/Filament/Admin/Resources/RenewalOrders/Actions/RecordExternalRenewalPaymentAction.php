<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RenewalOrders\Actions;

use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\Renewal\Actions\MarkRenewalPaidExternally;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalStatus;
use App\Filament\Admin\Pages\PasswordReauthentication;
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
 *
 * NEITHER of those is the source of truth for whether the settlement
 * itself is permitted (AUTHZ-04). `OrderTransitionAuthorizerContract`
 * (used by both `->authorize()` and the mount-time re-check above) is
 * role-only for this money transition — it never reads a cemetery-scope
 * grant. The actual authorization — role AND a privileged cemetery-scope
 * grant, matching `MarkExternalRenewal`'s CREATE path exactly — lives in
 * `MarkRenewalPaidExternally::__invoke()` via `RenewalMarkingPolicy`. A
 * `finance` actor can still see and click this button (the checks above
 * pass), but the domain action itself denies them unless
 * `RenewalMarkingPolicy::PERMITTED_ROLES` is widened — see that class and
 * `MarkRenewalPaidExternally`'s own doc block for the open decision point.
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
            ->authorize(fn (): bool => self::authorized($renewal))
            ->visible(fn (Renewal $record): bool => $record->status === RenewalStatus::MENUNGGU_PEMBAYARAN)
            ->action(function (array $data) use ($renewal): void {
                $actor = app(ActorContext::class);

                try {
                    app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
                        $actor,
                        self::TRANSITION,
                        $renewal->graveRecord?->cemetery_id,
                    );
                    app(ReauthenticationGuard::class)->assertFresh($actor);
                } catch (ReauthenticationRequiredException) {
                    Notification::make()
                        ->warning()
                        ->title('Perlu verifikasi ulang')
                        ->body('Lakukan verifikasi ulang untuk tindakan ini.')
                        ->send();

                    session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, 'money_action');
                    session()->put('url.intended', route('filament.admin.resources.pesanan-perpanjangan.view', ['record' => $renewal->getKey()]));
                    redirect()->route(PasswordReauthentication::ROUTE_NAME);

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
                    );
                    Notification::make()->success()->title('Pembayaran eksternal dicatat.')->send();
                } catch (\Throwable $exception) {
                    Notification::make()->danger()->title('Gagal mencatat pembayaran')->body($exception->getMessage())->send();
                }
            });
    }

    private static function authorized(Renewal $renewal): bool
    {
        try {
            app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
                app(ActorContext::class),
                self::TRANSITION,
                $renewal->graveRecord?->cemetery_id,
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
