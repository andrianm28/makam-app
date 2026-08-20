<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\WorkOrders\Actions;

use App\Domain\VendorFulfillment\Actions\UploadEvidence;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Platform\DocumentVault\Actions\PromoteDocument;
use App\Platform\DocumentVault\Actions\ScanDocument;
use App\Platform\DocumentVault\Actions\UploadDocument;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * The 'Unggah Bukti' header action on `ViewWorkOrder` — records before/after
 * evidence against a work order, same vault-upload shape as
 * `App\Filament\Admin\Resources\Certificates\Actions\CreateCertificateAction`.
 *
 * ---------------------------------------------------------------------------
 * Real vault upload, driven synchronously to Accepted
 * ---------------------------------------------------------------------------
 * `App\Domain\VendorFulfillment\Actions\UploadEvidence` refuses any document
 * that is not `DocumentState::Accepted`. The combined dev/staging host has no
 * always-on media worker (AGENTS.md: "Development and batch workers run on
 * demand"), so — exactly like `CreateCertificateAction` — this action drives
 * the quarantine → scan → promote pipeline synchronously
 * (`UploadDocument::upload()` then `ScanDocument::scan()` then
 * `PromoteDocument::promote()`) before calling `UploadEvidence`, rather than
 * waiting on the queued `ScanDocumentJob` the async path would dispatch. The
 * invariants (quarantine-first, clean-scan-before-promotion,
 * accepted-before-evidence) are unchanged; only the synchronous invocation
 * differs from the async job path, and the same platform bookkeeping
 * (outbox row, scan record) still happens through `UploadDocument`'s own
 * machinery.
 *
 * ---------------------------------------------------------------------------
 * Authorization
 * ---------------------------------------------------------------------------
 * No extra `->authorize()` gate: `WorkOrdersResource::getEloquentQuery()`
 * (via `ScopesToCurrentVendor`) already refuses to resolve a work order
 * outside the acting vendor's own grant (404, not just a hidden row — see
 * that trait's doc block), and `VendorPanelAccessPolicy` already requires the
 * `vendor` role plus an active vendor scope grant to reach this panel at
 * all. There is no vendor-panel sub-role (view-only vs act) the way the admin
 * panel's operator/finance split has one — see `VendorPanelAccessPolicy`'s
 * own doc block: "This differs from AdminPanelAccessPolicy... every /vendor
 * surface is by definition about one vendor's own records."
 */
final class UploadEvidenceAction
{
    /**
     * `ViewWorkOrder::getHeaderActions()` wires this action's
     * `Filament\Actions\Action::make()` itself (capturing `$record` from
     * `$this->getRecord()`, matching
     * `App\Filament\Vendor\Resources\VendorOrders\Pages\EditVendorOrder`'s
     * own header-action convention — see that class's doc block on why a
     * page-level header action captures `$record` from the page rather than
     * relying on Filament to inject it into the action closure). This class
     * supplies only the schema and the `$record`/`$data` handler, so the
     * upload logic stays in its own file without inventing a different
     * action-wiring shape from this panel's existing header actions.
     *
     * @return array<Select|FileUpload>
     */
    public static function schema(): array
    {
        return [
            Select::make('evidence_type')
                ->label('Jenis bukti')
                ->options([
                    'before' => 'Sebelum',
                    'after' => 'Sesudah',
                ])
                ->required(),

            FileUpload::make('document_file')
                ->label('Foto bukti')
                ->disk('local')
                ->directory('work-evidence-uploads')
                ->acceptedFileTypes(['image/jpeg', 'image/png'])
                ->maxSize(10240)
                ->required()
                ->helperText('JPG/PNG maksimal 10 MB. Foto melewati pemindaian keamanan (karantina) sebelum dicatat sebagai bukti.'),
        ];
    }

    public static function run(WorkOrder $record, array $data): void
    {
        $actor = app(ActorContext::class);

        try {
            $document = self::uploadAndAcceptDocument($record, (string) ($data['document_file'] ?? ''));

            app(UploadEvidence::class)(
                $record,
                $document->getKey(),
                (string) ($data['evidence_type'] ?? ''),
                (string) $actor->identityReference,
                (string) $actor->identityReference,
            );

            Notification::make()->success()->title('Bukti pekerjaan diunggah.')->send();
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->danger()
                ->title('Unggahan bukti ditolak')
                ->body($exception->getMessage())
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Gagal mengunggah bukti')
                ->body($exception->getMessage())
                ->send();
        }
    }

    /**
     * Read the temporarily-stored image (Filament's FileUpload staging path
     * on the `local` disk), hand it to the `UploadDocument` quarantine seam,
     * then drive quarantine → scanning → accepted so the returned document
     * satisfies `UploadEvidence`'s Accepted-only check. Mirrors
     * `CreateCertificateAction::uploadAndAcceptDocument()` exactly, adapted
     * to `DocumentKind::VendorEvidence` and the work order owner type.
     */
    private static function uploadAndAcceptDocument(WorkOrder $workOrder, string $storedPath): Document
    {
        $storage = Storage::disk('local');
        $tempPath = null;

        try {
            if (! $storage->exists($storedPath)) {
                throw new InvalidArgumentException('Berkas bukti tidak ditemukan pada penyimpanan sementara.');
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'work-evidence-upload-');

            if ($tempPath === false) {
                throw new InvalidArgumentException('Tidak dapat membuat berkas sementara untuk bukti pekerjaan.');
            }

            file_put_contents($tempPath, $storage->get($storedPath));

            $mimeType = mime_content_type($tempPath) ?: 'application/octet-stream';

            $file = new UploadedFile($tempPath, basename($storedPath), $mimeType, null, true);

            $document = app(UploadDocument::class)->upload(
                DocumentKind::VendorEvidence,
                $file,
                WorkOrder::class,
                (string) $workOrder->getKey(),
                null,
                [],
            );

            app(ScanDocument::class)->scan($document);

            return app(PromoteDocument::class)->promote($document->fresh());
        } finally {
            if (is_string($tempPath) && $tempPath !== '' && is_file($tempPath)) {
                unlink($tempPath);
            }

            $storage->delete($storedPath);
        }
    }
}
