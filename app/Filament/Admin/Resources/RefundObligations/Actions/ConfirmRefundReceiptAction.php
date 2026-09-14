<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RefundObligations\Actions;

use App\Domain\RefundObligation\Actions\ConfirmRefundObligation;
use App\Domain\RefundObligation\Models\RefundObligation;
use App\Domain\RefundObligation\RefundObligationStatus;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\Payment\Contracts\PaymentActionAuthorizer;
use App\Platform\Payment\Exceptions\PaymentActionNotAuthorisedException;
use App\Platform\Payment\RecordPaymentActionRefusal;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * The 'Konfirmasi Penerimaan' row action — `DIEKSEKUSI` → `TERKONFIRMASI`,
 * the last thing anybody records about a refund debt.
 *
 * ---------------------------------------------------------------------------
 * No upload here, and that is deliberate
 * ---------------------------------------------------------------------------
 * Evidence belongs to the EXECUTION — the event the system cannot see.
 * Confirmation is a different kind of fact: an operator has spoken to the
 * family, or watched the debit clear. Asking for a second file here would
 * mostly produce the same transfer receipt uploaded twice, which makes the
 * evidence trail less honest rather than more. The mandatory audit reason is
 * where the confirmer records how they know. See
 * `Domain\RefundObligation\Actions\ConfirmRefundObligation`.
 *
 * Offered only on an obligation that is already `DIEKSEKUSI`. The domain
 * Action refuses every other state under a row lock regardless — in
 * particular it refuses confirming a `TERUTANG` obligation straight to
 * terminal, which would close a debt with no execution and no evidence
 * beneath it. This visibility rule is convenience; that refusal is the
 * control.
 */
final class ConfirmRefundReceiptAction
{
    /**
     * The `PaymentActionAuthorizer` vocabulary this action authorises under.
     * See `RefundObligationsResource`'s doc block for why that authorizer is
     * reused for refund work and why the reuse is flagged for human review.
     */
    public const string PAYMENT_ACTION = 'refund_obligation_confirmation';

    public static function make(): Action
    {
        return Action::make('konfirmasi_penerimaan')
            ->label('Konfirmasi Penerimaan')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading('Konfirmasi penerimaan refund')
            ->modalDescription(
                'Konfirmasikan hanya bila Anda benar-benar tahu uangnya sudah diterima. '
                .'"Sudah dikirim" bukan "sudah diterima".'
            )
            ->modalSubmitActionLabel('Konfirmasi')
            ->visible(fn (RefundObligation $record): bool => $record->status === RefundObligationStatus::DIEKSEKUSI)
            ->schema(self::schema())
            ->action(fn (RefundObligation $record, array $data) => self::run($record, $data));
    }

    /**
     * @return array<DatePicker|Textarea>
     */
    private static function schema(): array
    {
        return [
            DatePicker::make('confirmed_on')
                ->label('Tanggal penerimaan dikonfirmasi')
                ->required()
                ->maxDate(fn (): CarbonImmutable => CarbonImmutable::now()),

            Textarea::make('reason')
                ->label('Dasar konfirmasi')
                ->helperText(
                    'Wajib. Bagaimana Anda tahu uangnya sudah diterima — mis. dikonfirmasi keluarga '
                    .'lewat telepon, atau mutasi rekening sudah terbit.'
                )
                ->required()
                ->rows(3),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function run(RefundObligation $record, array $data): void
    {
        $actor = app(ActorContext::class);

        try {
            $role = app(PaymentActionAuthorizer::class)->authorize($actor);
        } catch (PaymentActionNotAuthorisedException $exception) {
            app(RecordPaymentActionRefusal::class)->record($actor, self::PAYMENT_ACTION);
            self::deny($exception->getMessage());

            return;
        }

        try {
            app(ConfirmRefundObligation::class)->handle(
                obligation: $record,
                confirmedAt: self::confirmedAt((string) ($data['confirmed_on'] ?? '')),
                reason: (string) ($data['reason'] ?? ''),
                actorRef: $actor->identityReference,
                actorRole: $role,
                source: AuditSource::Panel,
            );
        } catch (Throwable $exception) {
            self::deny($exception->getMessage());

            return;
        }

        Notification::make()
            ->success()
            ->title('Penerimaan refund dikonfirmasi.')
            ->body('Kewajiban ini selesai dan tidak dapat diubah lagi.')
            ->send();
    }

    /**
     * Same date-to-timestamp clamp as
     * `RecordRefundExecutionAction::executedAt()`, and for the same reason: a
     * `DatePicker` has no time, `confirmed_at` is a timestamp, and the domain
     * Action refuses a future one.
     */
    private static function confirmedAt(string $date): CarbonImmutable
    {
        $endOfChosenDay = CarbonImmutable::parse($date)->endOfDay();
        $now = CarbonImmutable::now();

        return $endOfChosenDay->greaterThan($now) && $endOfChosenDay->isSameDay($now)
            ? $now
            : $endOfChosenDay;
    }

    private static function deny(string $title): void
    {
        Notification::make()->danger()->title($title)->send();
    }
}
