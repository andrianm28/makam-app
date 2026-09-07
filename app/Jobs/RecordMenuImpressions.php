<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Platform\Analytics\MenuInteractionRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * PERF-06 — moves the homepage's four `menu_interaction_events` impression
 * writes off the request path. Before this job existed,
 * `App\Livewire\Public\HomePage::mount()` called
 * `MenuInteractionRecorder::impression()` four times inline, each one a
 * separate synchronous `INSERT` on every real page view.
 *
 * Dispatched to the `default` queue (explicit `$queue`, not left to
 * `config('queue.default')`'s own default-connection queue name — this
 * job's destination queue is a deliberate choice, not an accident of
 * config). Carries all four `(menuKey, route)` pairs so
 * `MenuInteractionRecorder::impressions()` can write them as ONE batched
 * `insert()` statement instead of four, whichever process (a real worker,
 * or `sync` in tests/local) ends up running it.
 *
 * Never throws back into the dispatcher: `MenuInteractionRecorder`'s own
 * write path already swallows failures after `report()`-ing them (see its
 * class doc block) — this job adds no new failure mode, only a new
 * transport.
 */
final class RecordMenuImpressions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<array{menuKey: string, route: string}>  $menus
     */
    public function __construct(private readonly array $menus)
    {
        // Explicit destination queue — `Queueable::$queue` is untyped
        // (`public $queue;`), so it is set here rather than redeclared as
        // a typed property, which would be an incompatible property
        // definition against the trait.
        $this->onQueue('default');
    }

    public function handle(): void
    {
        MenuInteractionRecorder::impressions($this->menus);
    }
}
