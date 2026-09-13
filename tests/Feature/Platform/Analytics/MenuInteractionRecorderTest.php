<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Jobs\RecordMenuImpressions;
use App\Platform\Analytics\MenuInteractionRecorder;
use App\Platform\Analytics\Models\MenuInteractionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PERF-06 (batch M7b) — `MenuInteractionRecorder::impressions()` (the
 * batched write `App\Jobs\RecordMenuImpressions` dispatches to) and the
 * job itself. `HomePageRouteTest` already covers the end-to-end behaviour
 * through the real homepage route; this file covers the new pieces
 * directly.
 */
final class MenuInteractionRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_impressions_writes_one_row_per_menu_in_a_single_batched_insert(): void
    {
        MenuInteractionRecorder::impressions([
            ['menuKey' => 'pemesanan', 'route' => '/pemesanan-makam'],
            ['menuKey' => 'layanan', 'route' => '/marketplace'],
        ]);

        $events = MenuInteractionEvent::query()->orderBy('id')->get();

        $this->assertSame(2, $events->count());
        $this->assertSame(['pemesanan', 'layanan'], $events->pluck('menu_key')->all());
        $this->assertTrue($events->every(fn (MenuInteractionEvent $event): bool => $event->interaction === 'impression'));
        $this->assertTrue($events->every(fn (MenuInteractionEvent $event): bool => $event->occurred_at !== null));
    }

    public function test_impressions_with_an_empty_list_writes_nothing(): void
    {
        MenuInteractionRecorder::impressions([]);

        $this->assertSame(0, MenuInteractionEvent::query()->count());
    }

    public function test_home_page_dispatches_one_batched_job_to_the_default_queue(): void
    {
        Bus::fake();

        $this->get('/');

        Bus::assertDispatched(RecordMenuImpressions::class, function (RecordMenuImpressions $job): bool {
            return $job->queue === 'default';
        });

        Bus::assertDispatchedTimes(RecordMenuImpressions::class, 1);
    }

    public function test_record_menu_impressions_job_writes_all_given_menus(): void
    {
        Queue::fake();

        (new RecordMenuImpressions([
            ['menuKey' => 'faq', 'route' => '/faq'],
        ]))->handle();

        $this->assertSame(1, MenuInteractionEvent::query()->where('menu_key', 'faq')->count());
    }

    public function test_stale_menu_interaction_events_are_prunable(): void
    {
        MenuInteractionEvent::query()->create([
            'menu_key' => 'faq',
            'route' => '/faq',
            'interaction' => 'impression',
            'occurred_at' => CarbonImmutable::now()->subDays(181),
        ]);

        MenuInteractionEvent::query()->create([
            'menu_key' => 'faq',
            'route' => '/faq',
            'interaction' => 'impression',
            'occurred_at' => CarbonImmutable::now()->subDays(1),
        ]);

        $this->artisan('model:prune', ['--model' => [MenuInteractionEvent::class]]);

        $this->assertSame(1, MenuInteractionEvent::query()->count());
        $this->assertSame(1, MenuInteractionEvent::query()->where('occurred_at', '>=', CarbonImmutable::now()->subDays(180))->count());
    }
}
