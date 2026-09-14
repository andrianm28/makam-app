<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentVerifications\Actions;

use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use App\Platform\Payment\Contracts\PaymentActionAuthorizer;
use App\Platform\Payment\Exceptions\PaymentActionNotAuthorisedException;
use App\Platform\Payment\Models\PaymentVerification;
use App\Platform\Payment\PaymentVerificationDecision;
use App\Platform\Payment\PaymentVerificationStatus;
use App\Platform\Payment\VerifyManualPayment;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * FIL-04 remediation — the View page's decision header actions for
 * `PaymentVerificationsResource`. An authorised finance/restricted-admin
 * actor approves or rejects a `SUBMITTED` manual payment verification
 * (`App\Platform\Payment\VerifyManualPayment::verify()`), the same write
 * path `VerifyManualPaymentController` already exposes over raw HTTP with
 * no admin UI in front of it.
 *
 * Structured after `RenewalOrders\Actions\RecordExternalRenewalPaymentAction`
 * (confirmed the right template — combines a required reason textarea, a
 * transition/authority check, THEN a `ReauthenticationGuard::assertFresh()`
 * re-check, in that order, with the standard
 * `ReauthenticationRequiredException` catch block): `->authorize()` gates
 * whether the button renders and mounts; the `->action()` closure re-checks
 * `PaymentActionAuthorizer` (capturing the SERVER-approved role — never a
 * caller-supplied one, per that contract's own doc block) AND
 * `ReauthenticationGuard` before the domain action ever runs, because
 * deciding a manual payment is a money-moving action.
 */
final class DecidePaymentVerificationAction
{
    private const string REASON_SESSION_VALUE = 'payment_manual_verification';

    public static function make(PaymentVerification $verification, PaymentVerificationDecision $decision): Action
    {
        $name = match ($decision) {
            PaymentVerificationDecision::Approve => 'approve_payment_verification',
            PaymentVerificationDecision::Reject => 'reject_payment_verification',
        };

        $label = match ($decision) {
            PaymentVerificationDecision::Approve => 'Setujui',
            PaymentVerificationDecision::Reject => 'Tolak',
        };

        return Action::make($name)
            ->label($label)
            ->color($decision === PaymentVerificationDecision::Approve ? 'success' : 'danger')
            ->icon($decision === PaymentVerificationDecision::Approve ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedXCircle)
            ->requiresConfirmation()
            ->modalHeading($decision === PaymentVerificationDecision::Approve
                ? 'Setujui verifikasi pembayaran ini?'
                : 'Tolak verifikasi pembayaran ini?')
            ->modalDescription('Keputusan tercatat di jejak audit dan tidak dapat diubah kembali.')
            ->schema([
                Textarea::make('reason')
                    ->label('Alasan')
                    ->rows(2)
                    ->required(),
            ])
            ->authorize(fn (): bool => self::authorized())
            ->visible(fn (PaymentVerification $record): bool => $record->status() === PaymentVerificationStatus::Submitted)
            ->action(function (array $data) use ($verification, $decision): void {
                $actor = app(ActorContext::class);

                try {
                    $actorRole = app(PaymentActionAuthorizer::class)->authorize($actor);
                    app(ReauthenticationGuard::class)->assertFresh($actor);
                } catch (ReauthenticationRequiredException) {
                    Notification::make()
                        ->warning()
                        ->title('Perlu verifikasi ulang')
                        ->body('Lakukan verifikasi ulang untuk tindakan ini.')
                        ->send();

                    session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, self::REASON_SESSION_VALUE);
                    session()->put('url.intended', route('filament.admin.resources.verifikasi-pembayaran.view', ['record' => $verification->getKey()]));
                    redirect()->route(PasswordReauthentication::ROUTE_NAME);

                    return;
                } catch (PaymentActionNotAuthorisedException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                try {
                    app(VerifyManualPayment::class)->verify(
                        verification: $verification,
                        decision: $decision,
                        reason: (string) $data['reason'],
                        actorRef: $actor->identityReference,
                        actorRole: $actorRole,
                        source: AuditSource::Panel,
                    );
                    Notification::make()->success()->title('Keputusan verifikasi pembayaran dicatat.')->send();
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Gagal mencatat keputusan')->body($exception->getMessage())->send();
                }
            });
    }

    private static function authorized(): bool
    {
        try {
            app(PaymentActionAuthorizer::class)->authorize(app(ActorContext::class));

            return true;
        } catch (PaymentActionNotAuthorisedException) {
            return false;
        }
    }
}
