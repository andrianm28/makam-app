<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Reconciliations\Actions;

use App\Filament\Admin\Resources\Reconciliations\ReconciliationsResource;
use App\Filament\Admin\Resources\Reconciliations\Support\ProviderStatementCsvException;
use App\Filament\Admin\Resources\Reconciliations\Support\ProviderStatementCsvParser;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\FinancialLedger\Contracts\ReconciliationAuthorizer;
use App\Platform\FinancialLedger\Exceptions\InvalidReconciliationException;
use App\Platform\FinancialLedger\Exceptions\ReconciliationNotAuthorisedException;
use App\Platform\FinancialLedger\Jobs\ReconcileStatementJob;
use App\Platform\FinancialLedger\LedgerPeriod;
use App\Platform\FinancialLedger\ProviderStatement;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The 'Unggah Pernyataan Provider' header action on `ListReconciliations` —
 * the admin-facing half `Jobs\ReconcileStatementJob`/`Actions\RunReconciliation`
 * were missing: nothing in this codebase constructed a `ProviderStatement`
 * from anything real, and nothing scheduled the job. SumoPod (the payment
 * provider) exposes settlement data only through its own dashboard with no
 * API, so this is a manual admin-uploaded CSV export, not a live adapter —
 * see `Support\ProviderStatementCsvParser`'s class doc block for the exact
 * format and its stated limitation.
 *
 * ---------------------------------------------------------------------------
 * This action does not move money and is not itself a decision
 * ---------------------------------------------------------------------------
 * It only feeds `Jobs\ReconcileStatementJob`, which runs the READ-only
 * comparison `Actions\RunReconciliation` already is (see that class's own
 * doc block: "nothing in this file writes journal_batches or
 * journal_entries"). Any difference becomes a `reconciliation_exceptions`
 * row a human with finance authority still has to decide through
 * `Actions\ResolveException` — this action cannot resolve one. Still treated
 * with full financial-code rigor: integer minor units only (via
 * `ProviderStatementCsvParser`, never a caller-supplied pre-converted
 * value), no bank/account/card/customer data ever leaves the CSV (only
 * opaque `line_reference` values and integer amounts reach
 * `ProviderStatement`), and every accepted upload is audited.
 *
 * ---------------------------------------------------------------------------
 * Two-layer authorization, composed from two existing policies
 * ---------------------------------------------------------------------------
 * `->authorize()` (render gate) reuses `ReconciliationsResource::canAccess()`
 * — the same `Contracts\LedgerReadAuthorizer` coarse check the page itself
 * is gated on. `run()` re-checks authoritatively with
 * `Contracts\ReconciliationAuthorizer::authorize($actor, $entityRef)` for
 * the SPECIFIC badan usaha named in the form — the same policy
 * `Actions\ResolveException` uses to gate deciding a variance. See
 * `ReconciliationsResource`'s own doc block for why this reuse, composing
 * two policies neither was designed with the other in mind, is flagged for
 * human confirmation rather than treated as self-evidently correct.
 *
 * ---------------------------------------------------------------------------
 * Not routed through the platform document vault
 * ---------------------------------------------------------------------------
 * Unlike `Certificates\Actions\CreateCertificateAction`'s PDF upload, this
 * CSV is never retained as an artifact and never served back to a user —
 * `ProviderStatement`'s own class doc block frames the CSV as transient
 * input data: only opaque references and integer minor units survive into
 * `reconciliations`/`reconciliation_exceptions`, which ARE the persisted
 * evidence. AGENTS.md's "every untrusted file enters private quarantine"
 * rule reads most naturally as governing documents that get retained or
 * downloaded later, which this is not — but there is no `DocumentKind` case
 * for "financial statement CSV" to route it through even if that reading is
 * wrong, and adding one would modify a Platform closed list this batch does
 * not own. Flagged explicitly in this batch's report as an open question
 * for human review, not decided here. In place of vault quarantine, this
 * action applies its own hardening: an accepted-file-type/size cap on the
 * Filament upload field, and `ProviderStatementCsvParser`'s strict
 * structural parsing (exact header, exact column count, a row cap) before
 * any byte of the file influences a database write.
 */
final class UploadProviderStatementAction
{
    public const string AUDIT_ACTION = 'RECONCILIATION_STATEMENT_UPLOADED';

    public static function make(): Action
    {
        return Action::make('unggah_pernyataan')
            ->label('Unggah Pernyataan Provider')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('primary')
            ->authorize(fn (): bool => ReconciliationsResource::canAccess())
            ->schema(self::schema())
            ->action(fn (array $data) => self::run($data));
    }

    /**
     * @return array<TextInput|FileUpload>
     */
    private static function schema(): array
    {
        return [
            TextInput::make('statement_reference')
                ->label('Referensi pernyataan')
                ->helperText('ID/nomor pernyataan dari dasbor SumoPod — referensi opaque, bukan detail bank.')
                ->required()
                ->maxLength(255),

            TextInput::make('period')
                ->label('Periode (YYYY-MM)')
                ->placeholder('2026-08')
                ->regex(LedgerPeriod::PATTERN)
                ->required(),

            TextInput::make('entity_ref')
                ->label('Badan usaha')
                ->required()
                ->maxLength(255),

            FileUpload::make('statement_file')
                ->label('Berkas CSV pernyataan')
                ->disk('local')
                ->directory('reconciliation-statement-uploads')
                ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                ->maxSize(2048)
                ->required()
                ->helperText('CSV maksimal 2 MB. Kolom: line_reference,amount (lihat ProviderStatementCsvParser).'),
        ];
    }

    private static function run(array $data): void
    {
        $entityRef = trim((string) ($data['entity_ref'] ?? ''));
        $period = trim((string) ($data['period'] ?? ''));
        $statementReference = trim((string) ($data['statement_reference'] ?? ''));
        $storedPath = (string) ($data['statement_file'] ?? '');

        if ($entityRef === '') {
            self::deny('Badan usaha wajib diisi.');

            return;
        }

        if (! LedgerPeriod::matches($period)) {
            self::deny('Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.');

            return;
        }

        $actor = app(ActorContext::class);

        try {
            $role = app(ReconciliationAuthorizer::class)->authorize($actor, $entityRef);
        } catch (ReconciliationNotAuthorisedException $exception) {
            self::deny($exception->getMessage());

            return;
        }

        $storage = Storage::disk('local');

        try {
            if (! $storage->exists($storedPath)) {
                self::deny('Berkas pernyataan tidak ditemukan pada penyimpanan sementara.');

                return;
            }

            $contents = (string) $storage->get($storedPath);

            try {
                $lines = app(ProviderStatementCsvParser::class)->parse($contents);
            } catch (ProviderStatementCsvException $exception) {
                self::deny($exception->getMessage());

                return;
            }

            try {
                $statement = new ProviderStatement($statementReference, $period, $entityRef, $lines);
            } catch (InvalidReconciliationException $exception) {
                self::deny($exception->getMessage());

                return;
            }

            ReconcileStatementJob::dispatch($period, $entityRef, $statement);

            Audit::record(
                action: self::AUDIT_ACTION,
                subject: new AuditSubject('reconciliation_statement_upload', "{$entityRef}:{$period}"),
                outcome: AuditOutcome::Allowed,
                actorRef: $actor->identityReference,
                actorRole: $role,
                source: AuditSource::Panel,
                reason: null,
                correlationId: app(CorrelationContext::class)->current()?->value,
                metadata: [
                    'reference_number' => $statementReference,
                    'note' => "period={$period};lines=".count($lines),
                ],
            );

            Notification::make()
                ->success()
                ->title('Pernyataan diunggah.')
                ->body('Rekonsiliasi akan diproses di latar belakang pada antrean laporan.')
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Unggah pernyataan gagal')
                ->body($exception->getMessage())
                ->send();
        } finally {
            $storage->delete($storedPath);
        }
    }

    private static function deny(string $title): void
    {
        Notification::make()->danger()->title($title)->send();
    }
}
