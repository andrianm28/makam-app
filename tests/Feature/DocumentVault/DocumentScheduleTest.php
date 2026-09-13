<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentVault;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * FN-1 — `ReconcileDocumentStorageCleanupJob` used to be scheduled TWICE:
 * once as a `withSchedule()` job entry in `bootstrap/app.php`
 * (`everyFiveMinutes()`, mutex `document-vault:reconcile-storage-cleanups`)
 * and once as `Schedule::command('documents:reconcile-storage-cleanup')`
 * in `routes/console.php` (`hourly()`, added later by the QUE-09 fix).
 *
 * The two mutexes keyed off different names, so neither suppressed the
 * other: 312 dispatches/day of a job that currently fails 100% against an
 * unconfigured Document Vault, writing ~13 identical rows an hour into
 * `failed_jobs` and burying any real failure an operator needed to see.
 *
 * This test previously asserted only that the `bootstrap/app.php` entry
 * existed, by its mutex name — which is exactly why it could not see the
 * duplication. It now asserts on the RESOLVED schedule as a whole, because
 * a test scoped to one registration site passes no matter how many other
 * sites register the same work.
 *
 * ---------------------------------------------------------------------------
 * Why `schedule:list` and not `app(Schedule::class)->events()`
 * ---------------------------------------------------------------------------
 * `ApplicationBuilder::withSchedule()` registers its callback through
 * `Artisan::starting()`, so a `bootstrap/app.php` schedule entry only
 * materialises once the Artisan console application has actually booted.
 * Resolving `Schedule` straight out of the container in a test sees the
 * `routes/console.php` entries and NOTHING from `withSchedule()` — verified
 * by re-introducing the duplicate registration and watching a container-
 * based version of this test stay green. Running `schedule:list` is what
 * makes both registration sites visible, which is the whole point here.
 */
final class DocumentScheduleTest extends TestCase
{
    public function test_document_storage_reconciliation_is_scheduled_exactly_once(): void
    {
        Artisan::call('schedule:list');

        $entries = collect(preg_split('/\R/', Artisan::output()) ?: [])
            ->map(static fn (string $line): string => trim($line))
            ->filter(static fn (string $line): bool => str_contains($line, 'reconcile-storage-cleanup'))
            ->values();

        $this->assertCount(
            1,
            $entries,
            'The document-vault storage-cleanup reconciliation must be registered exactly once across the '
            .'WHOLE resolved schedule (bootstrap/app.php AND routes/console.php). Matching entries: '
            .$entries->implode(' | ')
        );

        $this->assertStringContainsString(
            'documents:reconcile-storage-cleanup',
            (string) $entries->first(),
            'routes/console.php is the single discoverable schedule inventory (EDGE-03); the surviving '
            .'registration must be the artisan command, not a bare job entry in bootstrap/app.php.'
        );
    }
}
