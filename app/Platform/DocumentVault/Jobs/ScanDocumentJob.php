<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Jobs;

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
 * ---------------------------------------------------------------------------
 * `handle()` is an intentional no-op in this task
 * ---------------------------------------------------------------------------
 * The actual scan dispatch (calling `Actions\ScanDocument::scan()`,
 * `task-5-brief.md`) is Task 5's responsibility, and that class does not
 * exist yet in this task's scope — `task-4-brief.md` lists only
 * `Jobs/ScanDocumentJob.php` among this task's owned files, not
 * `Actions/ScanDocument.php`. This job exists now so `UploadDocument`'s
 * Step 6 has a real, correctly-queued, document-referencing job to
 * dispatch; Task 5 wires the actual scan call into `handle()` below rather
 * than this task guessing at an interface Task 5 has not built yet.
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

    public function handle(): void
    {
        // Deferred to Task 5's Actions\ScanDocument — see class doc block.
    }
}
