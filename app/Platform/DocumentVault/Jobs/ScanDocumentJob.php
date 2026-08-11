<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Jobs;

use App\Platform\DocumentVault\Actions\ScanDocument;
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

        $scanDocument->scan($document);
    }
}
