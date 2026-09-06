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
use App\Platform\Payment\Exceptions\PaymentReversalAlreadyRecordedException;
use App\Platform\Payment\Models\PaymentVerification;
use App\Platform\Payment\PaymentReversalType;
use App\Platform\Payment\PaymentVerificationStatus;
use App\Platform\Payment\ReversalService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * FIL-04 remediation — the View page's refund/chargeback header actions for
 * `PaymentVerificationsResource`. `payment_reversals` carries no foreign key
 * to `payment_verifications` (see `2026_08_11_100010_create_payment_
 * reversals_table.php`'s own doc block — the two tables are deliberately
 * decoupled), so this action does not "reverse the verification" in a
 * structural sense; it lets an authorised finance/restricted-admin actor
 * record a refund or chargeback against the SAME external `reference` the
 * verification itself carries, from the one screen that already shows that
 * reference and the confirmed paid amount.
 *
 * Only offered once a verification is `VERIFIED` — nothing to reverse
 * before an approval exists.
 *
 * Same three-piece shape as `DecidePaymentVerificationAction`/
 * `RenewalOrders\Actions\RecordExternalRenewalPaymentAction`: authorize,
 * then re-authenticate, then the domain call — money-moving action.
 *
 * `RecordRefund`/`RecordChargeback` (via `ReversalService`) can throw
 * `PaymentReversalAlreadyRecordedException` for a duplicate
 * `(reversal_type, reference)` pair — caught with its own notification,
 * distinct from the generic `Throwable` catch, so a double-submit reads as
 * "already recorded" rather than a generic failure.
 */
final class RecordPaymentReversalAction
{
    private const string REASON_SESSION_VALUE = 'payment_reversal';

    public static function make(PaymentVerification $verification, PaymentReversalType $type): Action
    {
        $name = match ($type) {
            PaymentReversalType::Refund => 'record_refund',
            PaymentReversalType::Chargeback => 'record_chargeback',
        };

        $label = match ($type) {
            PaymentReversalType::Refund => 'Catat Refund',
            PaymentReversalType::Chargeback => 'Catat Chargeback',
        };

        return Action::make($name)
            ->label($label)
            ->color('warning')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->requiresConfirmation()
            ->modalHeading($label.'?')
            ->modalDescription('Pembalikan dicatat sebagai transaksi terpisah dan tidak dapat diubah setelah disimpan.')
            ->schema([
                TextInput::make('reference')
                    ->label('Referensi')
                    ->default(fn (): string => $verification->reference)
                    ->required()
                    ->maxLength(191),

                TextInput::make('amount_minor')
                    ->label('Jumlah (opsional)')
                    ->numeric()
                    ->minValue(1),

                Textarea::make('reason')
                    ->label('Alasan')
                    ->rows(2)
                    ->required(),
            ])
            ->authorize(fn (): bool => self::authorized())
            ->visible(fn (PaymentVerification $record): bool => $record->status() === PaymentVerificationStatus::Verified)
            ->action(function (array $data) use ($type): void {
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
                    redirect()->route(PasswordReauthentication::ROUTE_NAME);

                    return;
                } catch (PaymentActionNotAuthorisedException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                try {
                    app(ReversalService::class)->record(
                        type: $type,
                        reference: (string) $data['reference'],
                        amountMinor: array_key_exists('amount_minor', $data) && $data['amount_minor'] !== null
                            ? (int) $data['amount_minor']
                            : null,
                        reason: (string) $data['reason'],
                        actorRef: $actor->identityReference,
                        actorRole: $actorRole,
                        source: AuditSource::Panel,
                    );
                    Notification::make()->success()->title('Pembalikan pembayaran dicatat.')->send();
                } catch (PaymentReversalAlreadyRecordedException $exception) {
                    Notification::make()->danger()->title('Pembalikan sudah pernah dicatat')->body($exception->getMessage())->send();
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Gagal mencatat pembalikan')->body($exception->getMessage())->send();
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
