<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentVault;

use App\Platform\DocumentVault\Jobs\ReconcileDocumentStorageCleanupJob;
use App\Platform\Outbox\OutboxQueueName;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
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
 * THE FIELD TO CHECK IS `CallbackEvent::$description`. Re-running the
 * mutation is what an upgrader DOES; this is what they LOOK AT. The two
 * registration sites are identified by different fields — the
 * `routes/console.php` one by `Event::$command` (its `description` is null),
 * the `bootstrap/app.php` one by `CallbackEvent::$description` (its `command`
 * is null) — and the duplicate being detected is the second. If `description`
 * stops carrying the `->name()` value, detection silently stops working.
 * `test_the_matcher_can_still_see_a_job_entry_identified_only_by_its_description`
 * below is the canary that turns that from silent into loud; read it too.
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
    /**
     * Deliberately does NOT contain `reconcile-storage-cleanup`, so the
     * canary entry cannot perturb the count the real assertion makes.
     */
    private const string CANARY_NAME = 'fn1-canary-callback-event-description';

    /**
     * The one matcher both tests below run on, so the canary really does
     * exercise the code path the real assertion depends on rather than an
     * approximation of it.
     *
     * @return Collection<int, string>
     */
    private function scheduleSummaries(Schedule $schedule): Collection
    {
        return collect($schedule->events())
            ->map(static fn ($event): string => trim(
                ($event->command ?? '').' '.$event->getSummaryForDisplay().' '.($event->description ?? '')
            ));
    }

    public function test_document_storage_reconciliation_is_scheduled_exactly_once(): void
    {
        // Called for its side effect only — booting the console application
        // is what makes `withSchedule()` entries exist. The output is not read.
        Artisan::call('schedule:list');

        $entries = $this->scheduleSummaries(app(Schedule::class))
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

    /**
     * The canary for the test above — read it before changing either.
     *
     * The two registration sites are identified by DIFFERENT fields, which a
     * probe of the resolved schedule shows directly:
     *
     *   Event         (routes/console.php)   command = "… artisan documents:…"
     *                                        description = NULL
     *   CallbackEvent (bootstrap/app.php)    command = NULL
     *                                        description = "document-vault:…"
     *
     * The duplicate this suite exists to catch is the CallbackEvent, and
     * `description` is its ONLY identifying string. The test above has a
     * positive control for the `Event` branch — its `assertStringContainsString`
     * on `documents:reconcile-storage-cleanup` — and had NOTHING exercising the
     * `CallbackEvent` branch.
     *
     * That made it fail-OPEN in a way no amount of care would show: if a future
     * Laravel stops populating `description` on `$schedule->job()` entries, or
     * `->name()` stops writing it, the matcher finds one entry, `assertCount(1)`
     * passes, and a re-added duplicate goes undetected. Green, silent, wrong —
     * the same shape as the `schedule:list` blind spot this file already
     * documents, one layer further in.
     *
     * So this registers a throwaway `$schedule->job(...)->name(...)` — the same
     * construction the deleted `bootstrap/app.php` block used — and asserts the
     * matcher can see it. If `description` ever stops carrying the name, THIS
     * fails loudly while the real assertion above goes on passing, which is
     * precisely the swap from fail-open to fail-closed that makes the green
     * above mean something.
     *
     * The canary's name deliberately shares no substring with
     * `reconcile-storage-cleanup`, so it can never inflate the real count.
     */
    public function test_the_matcher_can_still_see_a_job_entry_identified_only_by_its_description(): void
    {
        Artisan::call('schedule:list');

        $schedule = app(Schedule::class);

        $schedule->job(new ReconcileDocumentStorageCleanupJob, OutboxQueueName::Media->value)
            ->name(self::CANARY_NAME);

        $canaries = $this->scheduleSummaries($schedule)
            ->filter(static fn (string $summary): bool => str_contains($summary, self::CANARY_NAME))
            ->values();

        $this->assertCount(
            1,
            $canaries,
            'A `$schedule->job(...)->name(...)` entry is a CallbackEvent whose ONLY identifying string is '
            .'`description`. The duplicate-detection in this file rests entirely on that field, so if this '
            .'assertion fails, `test_document_storage_reconciliation_is_scheduled_exactly_once` above is '
            .'passing VACUOUSLY and can no longer see a re-added bootstrap/app.php registration. Fix the '
            .'matcher in `scheduleSummaries()` to key off whatever field now carries the name — do not '
            .'delete this test to get green.'
        );
    }
}
