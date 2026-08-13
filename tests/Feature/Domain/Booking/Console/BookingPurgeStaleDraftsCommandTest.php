<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Console;

use App\Domain\Booking\Models\BookingDraft;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `booking:purge-stale-drafts` command — the operator-facing surface of
 * the abandoned-draft retention ruling (scheduled daily in
 * `routes/console.php`). The Action's own suite pins the boundary logic,
 * dry-run semantics and audit shape; this file pins the command layer that
 * wraps it: the default window coming from config, the `--days` override,
 * the `--dry-run` passthrough, and the readable failure for a nonsense
 * window.
 */
final class BookingPurgeStaleDraftsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_deletes_drafts_past_the_config_window(): void
    {
        config(['booking.draft_retention_days' => 30]);
        $abandoned = $this->makeDraftAged(days: 45);

        $this->artisan('booking:purge-stale-drafts')
            ->expectsOutputToContain('Deleted 1 abandoned draft')
            ->assertSuccessful();

        $this->assertDatabaseMissing('booking_drafts', ['id' => $abandoned->id]);
    }

    public function test_days_option_overrides_the_config_default(): void
    {
        $abandoned = $this->makeDraftAged(days: 45);

        $this->artisan('booking:purge-stale-drafts', ['--days' => 60])
            ->assertSuccessful();

        // A 60-day window keeps a 45-day-old draft.
        $this->assertDatabaseHas('booking_drafts', ['id' => $abandoned->id]);
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $abandoned = $this->makeDraftAged(days: 45);

        $this->artisan('booking:purge-stale-drafts', ['--dry-run' => true])
            ->expectsOutputToContain('would be deleted')
            ->assertSuccessful();

        $this->assertDatabaseHas('booking_drafts', ['id' => $abandoned->id]);
    }

    public function test_a_window_below_one_day_is_rejected(): void
    {
        BookingDraft::create();

        $this->artisan('booking:purge-stale-drafts', ['--days' => 0])
            ->expectsOutputToContain('at least 1 day')
            ->assertFailed();

        $this->assertDatabaseCount('booking_drafts', 1);
    }

    public function test_the_scheduler_runs_the_command_daily(): void
    {
        $events = collect(app('Illuminate\Console\Scheduling\Schedule')->events());

        $matching = $events->first(
            static fn ($event): bool => str_contains($event->command ?? $event->description ?? '', 'booking:purge-stale-drafts')
        );

        $this->assertNotNull($matching, 'no schedule entry runs booking:purge-stale-drafts');
        $this->assertSame('15 3 * * *', (string) $matching->expression);
    }

    private function makeDraftAged(int $days): BookingDraft
    {
        $draft = BookingDraft::create();

        BookingDraft::query()->whereKey($draft->id)->update([
            'updated_at' => Carbon::now()->subDays($days)->toDateTimeString(),
        ]);

        return $draft->fresh();
    }
}
