<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Public\Directory\Support;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Directory\Support\CemeteryPresenter;
use Tests\TestCase;

/**
 * `CemeteryPresenter::priceRange()` — pure formatting logic, no querying
 * (see that method's own doc block), so it is exercised here against a
 * plain in-memory, never-persisted `Cemetery` instance rather than a full
 * page render — faster and more precise for the boundary conditions (those
 * are covered separately, against a real published cemetery, in
 * `Tests\Feature\Livewire\Public\Directory\CemeteryDirectoryIndexRouteTest`).
 * Extends the Laravel-bootstrapped `Tests\TestCase` (not a bare PHPUnit
 * `TestCase`) so `Cemetery`'s `HasUuids`/cast machinery has the framework
 * container it expects, even though nothing here touches the database.
 */
final class CemeteryPresenterTest extends TestCase
{
    public function test_both_bounds_zero_renders_gratis(): void
    {
        $cemetery = new Cemetery;
        $cemetery->price_min = 0;
        $cemetery->price_max = 0;

        $this->assertSame('Gratis', CemeteryPresenter::priceRange($cemetery));
    }

    public function test_both_bounds_null_renders_null_not_gratis(): void
    {
        $cemetery = new Cemetery;
        $cemetery->price_min = null;
        $cemetery->price_max = null;

        $this->assertNull(CemeteryPresenter::priceRange($cemetery));
    }

    /**
     * A lopsided zero/non-zero pair is left alone rather than
     * reinterpreted as "free" — see `priceRange()`'s own doc block for why:
     * it is far more likely to be a data-entry mistake than a real free
     * offering, so it keeps the ordinary `Rp 0 - Rp X` rendering.
     */
    public function test_a_lopsided_zero_bound_does_not_render_gratis(): void
    {
        $cemetery = new Cemetery;
        $cemetery->price_min = 0;
        $cemetery->price_max = 5_000_000;

        $this->assertSame('Rp 0 - Rp 5.000.000', CemeteryPresenter::priceRange($cemetery));
    }

    public function test_an_ordinary_non_zero_range_is_unaffected(): void
    {
        $cemetery = new Cemetery;
        $cemetery->price_min = 4_000_000;
        $cemetery->price_max = 7_500_000;

        $this->assertSame('Rp 4.000.000 - Rp 7.500.000', CemeteryPresenter::priceRange($cemetery));
    }
}
