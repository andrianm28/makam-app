<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Jobs;

use App\Platform\DocumentVault\Actions\ScanDocument;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched by `Actions\UploadDocument` onto the `media` queue (brief Step
 * 6). Import scans use `media`, not `imports`, so a large `GRAVE_IMPORT`
 * scan run never starves `imports`' own queue (`queue-and-outbox.md` §2).
 *
 * Takes the document's `id`, not the model itself — the same "re-fetch
 * fresh state in `handle()`" practice as
 * `App\Platform\Outbox\Jobs\PublishOutboxEventJob`.
 *
 * `handle()` delegates the attempt to `Actions\ScanDocument`; scanner errors
 * are rescheduled by that Action with bounded delay.
 *
 * The consumer is idempotent (AGENTS.md: "Consumers are idempotent; queue
 * delivery is at-least-once"): `handle()` re-checks the freshly loaded state
 * and only delegates when the document is still scannable
 * (`DocumentState::Quarantined` — the normal upload path — or
 * `DocumentState::Scanning` — a rescheduled retry after a scanner error).
 * A document that reached `DocumentState::Accepted` before this job dequeued
 * (e.g. the synchronous quarantine→scan→promote pipeline driven by
 * `Filament\Admin\Resources\Certificates\Actions\CreateCertificateAction`)
 * has already had its scan done; scanning again would throw inside the
 * strict `Actions\ScanDocument` and fail the job on every issuance, so the
 * job no-ops instead. Its action-level acceptance list is kept in one place
 * — the Action remains strict, this job is the tolerant consumer.
 */
final class ScanDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $documentId,
    ) {}

    public function handle(ScanDocument $scanDocument): void
    {
        $document = Document::query()->findOrFail($this->documentId);

        if (! $this->scannable($document)) {
            return;
        }

        $scanDocument->scan($document);
    }

    /**
     * Whether the freshly loaded document still warrants a scan attempt —
     * the same state window `Actions\ScanDocument` itself accepts.
     */
    private function scannable(Document $document): bool
    {
        return in_array($document->state, [DocumentState::Quarantined, DocumentState::Scanning], true);
    }
}
