<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RefundObligations\Actions;

use App\Domain\RefundObligation\Actions\ExecuteRefundObligation;
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
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The 'Catat Eksekusi' row action — Stage R2's *"aksi 'catat eksekusi' yang
 * mewajibkan jumlah, tanggal, rujukan transfer, dan unggahan bukti"*.
 *
 * ---------------------------------------------------------------------------
 * This action records a claim; it does not move money
 * ---------------------------------------------------------------------------
 * SumoPod cannot refund. The operator opens their banking app, makes the
 * transfer by hand, and comes back here to write down what they did. Nothing
 * in this file calls a provider, and Stage R5's `refund()` seam does not exist
 * yet — see `Actions\ExecuteRefundObligation`'s class doc block.
 *
 * ---------------------------------------------------------------------------
 * Evidence storage: a private local disk, NOT the platform document vault
 * ---------------------------------------------------------------------------
 * Follows `Reconciliations\Actions\UploadProviderStatementAction`'s upload
 * pattern (private `local` disk, accepted-type allowlist, size cap) with ONE
 * deliberate difference: that action deletes the file in a `finally` because
 * its CSV is transient input. This one KEEPS the file, because the file is the
 * evidence — it is deleted only when the execution was refused and the upload
 * would otherwise be orphaned.
 *
 * **Stated consequence, required by this lane's brief:** the evidence file is
 * NOT malware-scanned and NOT quarantined, which departs from `AGENTS.md`
 * §Authentication and uploads ("every untrusted file enters private
 * quarantine ... malware scan acceptance"). The three reasons — R0's column is
 * a path not a vault reference pair, the vault pipeline is asynchronous, and
 * the vault is hard-unavailable on beta because `config/document-vault.php`
 * resolves both providers to `null` outside development and the provider then
 * binds a throwing closure — are set out in full in
 * `Actions\ExecuteRefundObligation`'s doc block. Routing evidence through the
 * vault today would make it impossible for any operator to close a refund on
 * beta. Migrating these files into the vault once it is configured is a
 * follow-up carrying a human gate.
 *
 * ---------------------------------------------------------------------------
 * The amount field is a control, not a data entry field
 * ---------------------------------------------------------------------------
 * Deliberately NOT prefilled from the obligation. The operator re-types, from
 * their banking app, the figure they actually sent; `ExecuteRefundObligation`
 * refuses the execution if it is not the amount owed. Prefilling would turn
 * the one check that can catch a transposed digit into a button the operator
 * clicks past. See `Exceptions\RefundObligationAmountMismatchException`.
 *
 * Entered in rupiah and multiplied by 100, the same shape
 * `Vendors\RelationManagers\ListingsRelationManager` uses for `price_minor`
 * (MKT-09). An obligation whose amount is not a whole number of rupiah could
 * therefore not be matched through this form at all — it would be refused
 * rather than silently rounded, which is the correct direction to fail on a
 * money path, and is recorded here as a known limitation.
 */
final class RecordRefundExecutionAction
{
    /**
     * Where evidence files live on the private disk. Not `public`: `AGENTS.md`
     * §Authorization and files requires payment proof and work evidence to be
     * stored privately.
     */
    public const string EVIDENCE_DIRECTORY = 'refund-execution-evidence';

    /**
     * The `PaymentActionAuthorizer` vocabulary this action authorises under —
     * used for the audited refusal when an actor without payment authority
     * reaches it. See `RefundObligationsResource`'s doc block for why that
     * authorizer is reused here and why the reuse is flagged for review.
     */
    public const string PAYMENT_ACTION = 'refund_obligation_execution';

    public static function make(): Action
    {
        return Action::make('catat_eksekusi')
            ->label('Catat Eksekusi')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('primary')
            ->modalHeading('Catat eksekusi refund')
            ->modalDescription(
                'Sistem tidak memindahkan uangnya. Catat di sini transfer yang sudah Anda lakukan, '
                .'beserta buktinya.'
            )
            ->modalSubmitActionLabel('Catat eksekusi')
            // Only an outstanding debt can be executed. The domain Action
            // refuses the rest under a row lock regardless — this merely
            // avoids offering a button that cannot work.
            ->visible(fn (RefundObligation $record): bool => $record->status === RefundObligationStatus::TERUTANG)
            ->schema(self::schema())
            ->action(fn (RefundObligation $record, array $data) => self::run($record, $data));
    }

    /**
     * @return array<DatePicker|FileUpload|Textarea|TextInput>
     */
    private static function schema(): array
    {
        return [
            TextInput::make('amount_rupiah')
                ->label('Jumlah ditransfer (rupiah)')
                ->helperText(
                    'Ketik ulang nominal yang benar-benar Anda transfer. Harus sama persis dengan '
                    .'kewajiban; selisih sekecil apa pun akan ditolak.'
                )
                ->required()
                ->numeric()
                ->minValue(1)
                ->prefix('Rp'),

            DatePicker::make('executed_on')
                ->label('Tanggal eksekusi')
                ->helperText('Tanggal transfer benar-benar dikirim, bukan tanggal pencatatan.')
                ->required()
                ->maxDate(fn (): CarbonImmutable => CarbonImmutable::now()),

            TextInput::make('execution_reference')
                ->label('Rujukan transfer')
                ->helperText(
                    'Nomor referensi transfer dari bank. Rujukan opaque saja — jangan isi nomor '
                    .'rekening, nama pemilik rekening, atau baris mutasi.'
                )
                ->required()
                ->maxLength(191),

            FileUpload::make('evidence')
                ->label('Bukti transfer')
                ->disk('local')
                ->directory(self::EVIDENCE_DIRECTORY)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                ->maxSize(5120)
                ->required()
                ->helperText('Tangkapan layar atau PDF bukti transfer, maksimal 5 MB.'),

            Textarea::make('reason')
                ->label('Alasan / keterangan')
                ->helperText(
                    'Wajib. Tidak ada satu pun bagian sistem yang menyaksikan transfer ini, jadi '
                    .'keterangan Anda adalah bagian dari buktinya.'
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
        $storedPath = (string) ($data['evidence'] ?? '');
        $actor = app(ActorContext::class);

        try {
            $role = app(PaymentActionAuthorizer::class)->authorize($actor);
        } catch (PaymentActionNotAuthorisedException $exception) {
            // Leaves a `Denied` audit row rather than only a toast — the same
            // posture `VerifyManualPaymentController` takes on a refusal.
            app(RecordPaymentActionRefusal::class)->record($actor, self::PAYMENT_ACTION);
            self::discard($storedPath);
            self::deny($exception->getMessage());

            return;
        }

        try {
            app(ExecuteRefundObligation::class)->handle(
                obligation: $record,
                // Integer arithmetic only — never `(float) * 100`, which is
                // how a money value acquires a rounding error. Same shape as
                // `ListingsRelationManager`'s `price_minor` dehydration.
                amountMinor: ((int) ($data['amount_rupiah'] ?? 0)) * 100,
                executedAt: self::executedAt((string) ($data['executed_on'] ?? '')),
                executionReference: (string) ($data['execution_reference'] ?? ''),
                evidencePath: $storedPath,
                reason: (string) ($data['reason'] ?? ''),
                actorRef: $actor->identityReference,
                actorRole: $role,
                source: AuditSource::Panel,
            );
        } catch (Throwable $exception) {
            // The execution did not happen, so the upload is an orphan. Delete
            // it rather than leaving unreferenced evidence on a private disk.
            self::discard($storedPath);
            self::deny($exception->getMessage());

            return;
        }

        Notification::make()
            ->success()
            ->title('Eksekusi refund tercatat.')
            ->body('Kewajiban menunggu konfirmasi penerimaan dari pelanggan.')
            ->send();
    }

    /**
     * Deliberately narrow: deletes only inside this action's own evidence
     * directory, so a malformed or hostile `$path` cannot be turned into a
     * delete anywhere else on the private disk.
     */
    private static function discard(string $path): void
    {
        if ($path === '' || ! str_starts_with($path, self::EVIDENCE_DIRECTORY.'/')) {
            return;
        }

        Storage::disk('local')->delete($path);
    }

    private static function deny(string $title): void
    {
        Notification::make()->danger()->title($title)->send();
    }

    /**
     * The `DatePicker` yields a date with no time, but `executed_at` is a
     * timestamp and `ExecuteRefundObligation` refuses a future one.
     *
     * End of the chosen day is the most accurate instant available for a past
     * date — the transfer happened at some unknown time that day — but for
     * TODAY that instant is still hours in the future and the Action would
     * (correctly) refuse it. Clamped to now, so recording a transfer made an
     * hour ago works, while a genuinely future date still fails the Action's
     * own check rather than being silently pulled back to now: the
     * `->maxDate()` on the field stops that case before it reaches here.
     */
    private static function executedAt(string $date): CarbonImmutable
    {
        $endOfChosenDay = CarbonImmutable::parse($date)->endOfDay();
        $now = CarbonImmutable::now();

        return $endOfChosenDay->greaterThan($now) && $endOfChosenDay->isSameDay($now)
            ? $now
            : $endOfChosenDay;
    }
}
