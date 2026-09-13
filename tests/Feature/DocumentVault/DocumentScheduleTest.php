<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentVault;

use Illuminate\Console\Scheduling\Schedule;
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
 * Why `schedule:list` is called and its output thrown away
 * ---------------------------------------------------------------------------
 * `ApplicationBuilder::withSchedule()` registers its callback through
 * `Artisan::starting()`, so a `bootstrap/app.php` schedule entry only
 * materialises once the Artisan console application has actually booted.
 * Resolving `Schedule` straight out of the container in a test sees the
 * `routes/console.php` entries and NOTHING from `withSchedule()` — verified
 * by re-introducing the duplicate registration and watching a container-
 * based version of this test stay green, and independently replicated in
 * review (container-resolved Schedule: 1 hit; after a console boot: 2).
 *
 * That constraint says only that SOMETHING must boot the console
 * application first. It does not say the assertion has to be made against
 * rendered text. So `schedule:list` is called for its boot side effect and
 * its output discarded, and the assertion runs against the structured
 * `Schedule::events()` — same detection, with no dependence on a display
 * format Laravel is free to change in any minor release.
 *
 * ---------------------------------------------------------------------------
 * ON A LARAVEL MAJOR BUMP: RE-RUN THE MUTATION. DO NOT TRUST THE GREEN.
 * ---------------------------------------------------------------------------
 * This assertion reads `Event::$command`, `Event::getSummaryForDisplay()` and
 * `Event::$description`. That is more stable than rendered text, but it is
 * still framework surface, not a public contract. If a future Laravel
 * changes how `$schedule->job()` populates `description` — or renames any of
 * the three — every string this test matches on goes empty, the filter
 * matches nothing, and `assertCount(1, ...)` fails loudly on ONE registration
 * while `assertCount(2, ...)` would have. Worse: the reverse shape, where a
 * changed `$command` leaves exactly one match no matter how many entries
 * exist, makes this test go QUIET rather than red. A green run would then
 * prove nothing at all.
 *
 * So on a Laravel major upgrade, do not take this test passing as evidence.
 * Re-run the mutation that built it: re-add the `withSchedule()` block to
 * `bootstrap/app.php` (git history of this file's FN-1 commit has it
 * verbatim), confirm this test FAILS and that its message names BOTH
 * registration sites, then restore and confirm green. That round trip is the
 * only thing that proves this test still bites.
 *
 * Historical, and the reason this test no longer parses that output:
 * `ScheduleListCommand` prints a SECOND line per event carrying the event
 * description when `$this->output->isVerbose()` (`:233-236`). For the
 * surviving entry that second line also contains `reconcile-storage-cleanup`,
 * so a text-counting version of this test would have counted one
 * registration twice. It was unreachable in practice — `Artisan::call()`
 * runs at `VERBOSITY_NORMAL` and PHPUnit's own `-v` does not propagate into
 * it — but only until someone passed `['-v' => true]`. Asserting on
 * `events()` removes the hazard rather than relying on nobody doing that.
 */
final class DocumentScheduleTest extends TestCase
{
    public function test_document_storage_reconciliation_is_scheduled_exactly_once(): void
    {
        // Called for its side effect only — booting the console application
        // is what makes `withSchedule()` entries exist. The output is not read.
        Artisan::call('schedule:list');

        $entries = collect(app(Schedule::class)->events())
            ->map(static fn ($event): string => trim(
                ($event->command ?? '').' '.$event->getSummaryForDisplay().' '.($event->description ?? '')
            ))
            ->filter(static fn (string $summary): bool => str_contains($summary, 'reconcile-storage-cleanup'))
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
