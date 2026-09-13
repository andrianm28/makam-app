<?php

declare(strict_types=1);

namespace App\Platform\Analytics;

use App\Platform\Analytics\Models\MenuInteractionEvent;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The ONE write API for `menu_interaction_events` — mirrors
 * `App\Platform\Audit\Audit`'s "plain static-method class, one API, called
 * the same way everywhere" shape (see that class's own doc block for the
 * fuller reasoning), scaled down to match this table's much lower stakes:
 * no transaction wrapper, no required-field enforcement, no reason/
 * metadata allowlist — this is an anonymous count, not a legal record.
 *
 * requirements.md AC9: "record an analytics impression or click event
 * without sensitive data." Callers pass only a menu key and a route; there
 * is no parameter here for a user id, session id, or IP, so a caller cannot
 * accidentally smuggle one through even if it tried.
 *
 * ---------------------------------------------------------------------------
 * Never allowed to break the page it is called from
 * ---------------------------------------------------------------------------
 * `record()` swallows any write failure (database unavailable, table
 * missing in an environment mid-migration, etc.) after `report()`-ing it —
 * the same §6.5 "provider unavailable, degrade gracefully" discipline
 * `FaqIndex::render()` applies to its own search query. Analytics is a
 * secondary concern; a visitor must never see a 500 because a count could
 * not be written. See `App\Livewire\Public\HomePage::mount()` for the
 * concrete call site this protects.
 */
final class MenuInteractionRecorder
{
    /**
     * Record one impression — "this menu was visible in a rendered
     * response". Never throws.
     */
    public static function impression(string $menuKey, string $route): void
    {
        self::record($menuKey, $route, 'impression');
    }

    /**
     * PERF-06 — the batched counterpart of `impression()`: writes every
     * given `(menuKey, route)` pair as ONE `impression` `insert()`
     * statement instead of one `create()` call per pair. Used by
     * `App\Jobs\RecordMenuImpressions`, the job the homepage now dispatches
     * instead of calling `impression()` in a loop on the request path.
     *
     * `insert()` (not `create()` in a loop) deliberately bypasses Eloquent
     * model events for these rows — there are none registered on
     * `MenuInteractionEvent` to bypass (see that model's own doc block; it
     * is a plain fillable model with no observers), so this is a pure
     * perf win with no behavioural difference from four `create()` calls.
     * Never throws — same swallow-after-`report()` discipline as
     * `record()`, since a batch is just as replaceable-by-failure as a
     * single write and must never turn into a queue-worker crash loop.
     *
     * @param  list<array{menuKey: string, route: string}>  $menus
     */
    public static function impressions(array $menus): void
    {
        if ($menus === []) {
            return;
        }

        $occurredAt = CarbonImmutable::now();

        $rows = array_map(
            static fn (array $menu): array => [
                'menu_key' => $menu['menuKey'],
                'route' => $menu['route'],
                'interaction' => 'impression',
                'occurred_at' => $occurredAt,
            ],
            $menus,
        );

        try {
            MenuInteractionEvent::query()->insert($rows);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Record one click — "this menu was activated". Not currently called by
     * any part of this codebase (see the migration's own doc block for why
     * the click side is a named, real gap, not a silent omission); kept
     * here so a future batch that DOES have a real click signal does not
     * need a new write path, only a new call site.
     */
    public static function click(string $menuKey, string $route): void
    {
        self::record($menuKey, $route, 'click');
    }

    private static function record(string $menuKey, string $route, string $interaction): void
    {
        try {
            MenuInteractionEvent::create([
                'menu_key' => $menuKey,
                'route' => $route,
                'interaction' => $interaction,
                'occurred_at' => CarbonImmutable::now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
