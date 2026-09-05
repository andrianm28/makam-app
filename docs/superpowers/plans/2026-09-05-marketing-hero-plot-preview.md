# Marketing Hero Plot-Availability Preview Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a new, read-only Livewire section under the homepage hero that samples real per-plot availability for a small, config-driven list of showcase cemeteries, reusing the booking wizard's badge/intent vocabulary without touching any mutating reservation code.

**Architecture:** A new `App\Livewire\Public\Home\PlotAvailabilityPreview` component queries `CemeteryPublicQuery` + `CemeteryBlock` for a config-driven list of cemetery slugs, caches the result for 60 seconds via the `Cache` facade, and renders a new Blade view reusing `<x-mk.badge>` + `StatusIntent::{intent,icon,label}()`. One `<livewire:public.home.plot-availability-preview />` line is added to `home-page.blade.php` between the hero and the four service cards.

**Tech Stack:** Laravel 13, Livewire 4, `App\Domain\CemeteryDirectory\CemeteryPublicQuery`, `App\Domain\PlotInventory\Models\CemeteryBlock`, `App\Support\Design\StatusIntent`, Laravel's `Cache` facade (database store).

**Spec:** docs/superpowers/specs/2026-09-05-marketing-hero-plot-preview-design.md

## Global Constraints

- declare(strict_types=1) on every new PHP file.
- All new visual values must come from resources/css/tokens.css — never hardcode a color/spacing value (CLAUDE.md rule #4), checked by ci/verify-docs.sh.
- vendor/bin/pint --test and vendor/bin/phpstan analyse must stay clean.
- bash ci/verify-docs.sh must stay clean.
- Real Postgres/Redis for any test touching the database — never SQLite.
- The new component must never invoke any plot-reservation mutating action (this is a read-only surface for anonymous visitors).
- `App\Livewire\Public\Home\PlotAvailabilityPreview` must declare no public method other than `render()` (enforced by a reflection test in Task 3) — there must be no `wire:click`/`wire:model` target anywhere on this component, now or later, without that test failing first.

---

### Task 1: `PlotAvailabilityPreview` component, config, and view — core rendering

**Files:**
- Create: `config/marketing.php`
- Create: `app/Livewire/Public/Home/PlotAvailabilityPreview.php`
- Create: `resources/views/livewire/public/home/plot-availability-preview.blade.php`
- Test: `tests/Feature/Livewire/Public/Home/PlotAvailabilityPreviewTest.php`

**Interfaces:**
- Consumes: `App\Domain\CemeteryDirectory\CemeteryPublicQuery::findPublishedBySlug(string $slug): ?Cemetery`, `App\Domain\CemeteryDirectory\PlotTrackingMode::GRANULAR`, `App\Domain\PlotInventory\Models\CemeteryBlock`, `App\Support\Design\StatusIntent::{intent,icon,label}(string $status, ?string $family = null): string`, `App\Support\Design\StatusIntent::FAMILY_PLOT_STATE`, `config('marketing.homepage_plot_preview_cemetery_slugs')`.
- Produces: `App\Livewire\Public\Home\PlotAvailabilityPreview` (Livewire component, tag `<livewire:public.home.plot-availability-preview />`), consumed by Task 4.

- [ ] **Step 1: Write the failing component test — hidden by default against real seed data**
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Home;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Livewire\Public\Home\PlotAvailabilityPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class PlotAvailabilityPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeCemetery(string $slug, string $trackingMode): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba',
            'slug' => $slug,
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => $trackingMode,
        ]);
    }

    public function test_renders_nothing_when_no_configured_cemetery_is_granular(): void
    {
        $this->makeCemetery('tpu-aggregate-only', PlotTrackingMode::AGGREGATE);

        config(['marketing.homepage_plot_preview_cemetery_slugs' => ['tpu-aggregate-only']]);

        Livewire::test(PlotAvailabilityPreview::class)
            ->assertDontSee('Lihat Contoh Ketersediaan Plot');
    }

    public function test_renders_the_badge_grid_for_a_granular_cemetery_with_plots(): void
    {
        $cemetery = $this->makeCemetery('tpu-granular-showcase', PlotTrackingMode::GRANULAR);

        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 2,
        ]);

        GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '001',
            'plot_state' => PlotState::AVAILABLE,
        ]);

        GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '002',
            'plot_state' => PlotState::RESERVED,
        ]);

        config(['marketing.homepage_plot_preview_cemetery_slugs' => ['tpu-granular-showcase']]);

        Livewire::test(PlotAvailabilityPreview::class)
            ->assertSee('TPU Uji Coba')
            ->assertSee('BLOK-A')
            ->assertSee('001')
            ->assertSee('Tersedia')
            ->assertSee('002')
            ->assertSee('Dipesan');
    }

    public function test_skips_a_configured_slug_that_does_not_resolve(): void
    {
        config(['marketing.homepage_plot_preview_cemetery_slugs' => [
            'tpu-'.Str::lower(Str::random(10)),
        ]]);

        Livewire::test(PlotAvailabilityPreview::class)
            ->assertDontSee('Lihat Contoh Ketersediaan Plot');
    }
}
```
- [ ] **Step 2: Run test to verify it fails**
Run: `php artisan test --filter=PlotAvailabilityPreviewTest`
Expected: FAIL with "Class \"App\Livewire\Public\Home\PlotAvailabilityPreview\" not found"
- [ ] **Step 3: Create the config file**
```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Homepage plot-availability preview — showcase cemeteries
|--------------------------------------------------------------------------
|
| Slugs of the cemeteries App\Livewire\Public\Home\PlotAvailabilityPreview
| samples for the homepage's live-availability proof point
| (docs/superpowers/specs/2026-09-05-marketing-hero-plot-preview-design.md).
| A cemetery here that is not published, or not yet flipped to
| App\Domain\CemeteryDirectory\PlotTrackingMode::GRANULAR via
| App\Domain\CemeteryDirectory\Actions\SetCemeteryPlotTrackingMode, is
| silently skipped by the component — this list is a wishlist of curated
| candidates, not a guarantee every entry renders.
|
| Config, not a class constant, so which cemeteries are showcased can
| change without a deploy-and-decide cycle — the same reasoning
| config/plot-reservation.php's draft_hold_ttl_minutes gives for the same
| choice.
|
| Default: the two real, published cemeteries with real photography
| already backed in via 2026_08_24_100000_backfill_photo_and_maps_url_for_
| real_cemeteries.php. Neither is granular-tier as of this writing — the
| section renders its honest empty state against today's real database
| until an operator provisions block/plot data for one of them.
|
*/
return [
    'homepage_plot_preview_cemetery_slugs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'HOMEPAGE_PLOT_PREVIEW_CEMETERY_SLUGS',
            'tpu-petamburan,tpu-karet-bivak',
        )),
    ))),
];
```
- [ ] **Step 4: Create the component**
```php
<?php

declare(strict_types=1);

namespace App\Livewire\Public\Home;

use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Throwable;

/**
 * Read-only homepage section — docs/superpowers/specs/2026-09-05-marketing-
 * hero-plot-preview-design.md. Samples real per-plot availability for a
 * small, config-driven list of showcase cemeteries, reusing the Step 2
 * booking-wizard plot picker's badge/intent vocabulary
 * (BookingWizard::pickerBlocks()) without touching any mutating
 * reservation action. Declares no public method other than render() —
 * see this spec's §6/§9: there must be no wire:click/wire:model target on
 * this component, ever.
 */
final class PlotAvailabilityPreview extends Component
{
    private const int MAX_CEMETERIES = 2;

    private const int MAX_BLOCKS_PER_CEMETERY = 2;

    private const int MAX_PLOTS_PER_BLOCK = 12;

    public function render(): View
    {
        $slugs = array_slice(
            (array) config('marketing.homepage_plot_preview_cemetery_slugs'),
            0,
            self::MAX_CEMETERIES,
        );

        $showcase = new Collection;
        $unavailable = false;

        if ($slugs !== []) {
            try {
                $showcase = $this->buildShowcase($slugs);
            } catch (Throwable $e) {
                report($e);
                $unavailable = true;
            }
        }

        return view('livewire.public.home.plot-availability-preview', [
            'showcase' => $showcase,
            'unavailable' => $unavailable,
        ]);
    }

    /**
     * @param  list<string>  $slugs
     * @return Collection<int, array{cemetery: \App\Domain\CemeteryDirectory\Models\Cemetery, blocks: \Illuminate\Database\Eloquent\Collection<int, CemeteryBlock>}>
     */
    private function buildShowcase(array $slugs): Collection
    {
        $result = new Collection;

        foreach ($slugs as $slug) {
            $cemetery = CemeteryPublicQuery::findPublishedBySlug($slug);

            if ($cemetery === null || $cemetery->plot_tracking_mode !== PlotTrackingMode::GRANULAR) {
                continue;
            }

            $blocks = CemeteryBlock::query()
                ->where('cemetery_id', $cemetery->getKey())
                ->with(['plots' => fn ($query) => $query->orderBy('slot')->limit(self::MAX_PLOTS_PER_BLOCK)])
                ->orderBy('code')
                ->limit(self::MAX_BLOCKS_PER_CEMETERY)
                ->get();

            if ($blocks->isEmpty()) {
                continue;
            }

            $result->push(['cemetery' => $cemetery, 'blocks' => $blocks]);
        }

        return $result;
    }
}
```
- [ ] **Step 5: Create the view**
```blade
<div>
    @unless ($unavailable || $showcase->isEmpty())
        <section aria-labelledby="plot-preview-heading" class="mx-auto max-w-content px-4 py-5 md:px-6 lg:px-8 lg:py-8">
            <h2 id="plot-preview-heading" class="mb-2 text-center text-2xl font-semibold text-neutral-900">
                Lihat Contoh Ketersediaan Plot
            </h2>
            <p class="mx-auto mb-6 max-w-prose text-center text-base text-neutral-600">
                Data plot di bawah ini nyata dan diperbarui secara berkala, bukan ilustrasi — sebagian kecil dari
                TPU/TPS kami yang sudah memiliki data plot rinci.
            </p>
            <div class="grid gap-y-8">
                @foreach ($showcase as $entry)
                    @php [$cemetery, $blocks] = [$entry['cemetery'], $entry['blocks']]; @endphp
                    <div wire:key="plot-preview-cemetery-{{ $cemetery->id }}">
                        <h3 class="mb-3 text-lg font-semibold text-neutral-900">{{ $cemetery->name }}</h3>
                        <div class="grid gap-y-4">
                            @foreach ($blocks as $block)
                                <div wire:key="plot-preview-block-{{ $block->id }}">
                                    <p class="mb-2 text-sm font-medium text-neutral-900">{{ $block->code }} &mdash; {{ $block->name }}</p>
                                    <ul class="flex flex-wrap gap-2" aria-label="Plot di {{ $block->code }}">
                                        @foreach ($block->plots as $plot)
                                            <li wire:key="plot-preview-plot-{{ $plot->id }}" class="inline-flex items-center gap-1 rounded-md border border-neutral-200 px-2 py-1 text-sm text-neutral-700">
                                                {{ $plot->slot }}
                                                <x-mk.badge
                                                    intent="{{ \App\Support\Design\StatusIntent::intent($plot->plot_state, \App\Support\Design\StatusIntent::FAMILY_PLOT_STATE) }}"
                                                    :icon="\App\Support\Design\StatusIntent::icon($plot->plot_state, \App\Support\Design\StatusIntent::FAMILY_PLOT_STATE)"
                                                    size="sm"
                                                >
                                                    {{ \App\Support\Design\StatusIntent::label($plot->plot_state, \App\Support\Design\StatusIntent::FAMILY_PLOT_STATE) }}
                                                </x-mk.badge>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="mt-6 text-center text-sm text-neutral-600">
                <a href="{{ route('cemeteries.index') }}" class="font-medium text-primary-700 underline underline-offset-2">
                    Lihat semua TPU &amp; TPS
                </a>
            </p>
        </section>
    @endunless
</div>
```
- [ ] **Step 6: Run test to verify it passes**
Run: `php artisan test --filter=PlotAvailabilityPreviewTest`
Expected: PASS (3 tests, all assertions green)
- [ ] **Step 7: Run Pint and PHPStan**
Run: `vendor/bin/pint --test app/Livewire/Public/Home/PlotAvailabilityPreview.php config/marketing.php tests/Feature/Livewire/Public/Home/PlotAvailabilityPreviewTest.php`
Expected: no style violations
Run: `vendor/bin/phpstan analyse app/Livewire/Public/Home/PlotAvailabilityPreview.php`
Expected: no errors
- [ ] **Step 8: Commit**
```bash
git add config/marketing.php app/Livewire/Public/Home/PlotAvailabilityPreview.php resources/views/livewire/public/home/plot-availability-preview.blade.php tests/Feature/Livewire/Public/Home/PlotAvailabilityPreviewTest.php
git commit -m "feat(homepage): add read-only plot-availability preview component"
```

---

### Task 2: Cache the read for the homepage's weight budget

**Files:**
- Modify: `app/Livewire/Public/Home/PlotAvailabilityPreview.php`
- Test: `tests/Feature/Livewire/Public/Home/PlotAvailabilityPreviewTest.php`

**Interfaces:**
- Consumes: `Illuminate\Support\Facades\Cache::remember(string $key, int $ttl, Closure $callback): mixed`, `PlotAvailabilityPreview::buildShowcase()` (Task 1, unchanged signature).
- Produces: no new public interface — `render()`'s external behaviour is unchanged, only its query cost.

- [ ] **Step 1: Write the failing caching test**
```php
    public function test_a_second_render_within_the_cache_ttl_does_not_re_query(): void
    {
        $cemetery = $this->makeCemetery('tpu-granular-cache-check', PlotTrackingMode::GRANULAR);

        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 1,
        ]);

        GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '001',
            'plot_state' => PlotState::AVAILABLE,
        ]);

        config(['marketing.homepage_plot_preview_cemetery_slugs' => ['tpu-granular-cache-check']]);

        Livewire::test(PlotAvailabilityPreview::class)->assertSee('BLOK-A');

        \Illuminate\Support\Facades\DB::enableQueryLog();

        Livewire::test(PlotAvailabilityPreview::class)->assertSee('BLOK-A');

        $queries = \Illuminate\Support\Facades\DB::getQueryLog();

        $this->assertEmpty(
            array_filter($queries, static fn (array $q): bool => str_contains($q['query'], 'cemetery_blocks')),
            'Expected the second render within the cache TTL to read from cache, not re-query cemetery_blocks.',
        );
    }

    public function test_changing_the_configured_slug_list_bypasses_the_stale_cache_key(): void
    {
        $first = $this->makeCemetery('tpu-cache-key-a', PlotTrackingMode::GRANULAR);
        CemeteryBlock::query()->create([
            'cemetery_id' => $first->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 1,
        ])->plots()->create(['slot' => '001', 'plot_state' => PlotState::AVAILABLE]);

        $second = $this->makeCemetery('tpu-cache-key-b', PlotTrackingMode::GRANULAR);
        CemeteryBlock::query()->create([
            'cemetery_id' => $second->getKey(),
            'code' => 'BLOK-B',
            'name' => 'Blok B',
            'capacity' => 1,
        ])->plots()->create(['slot' => '001', 'plot_state' => PlotState::AVAILABLE]);

        config(['marketing.homepage_plot_preview_cemetery_slugs' => ['tpu-cache-key-a']]);
        Livewire::test(PlotAvailabilityPreview::class)->assertSee('BLOK-A')->assertDontSee('BLOK-B');

        config(['marketing.homepage_plot_preview_cemetery_slugs' => ['tpu-cache-key-b']]);
        Livewire::test(PlotAvailabilityPreview::class)->assertSee('BLOK-B')->assertDontSee('BLOK-A');
    }
```
- [ ] **Step 2: Run test to verify it fails**
Run: `php artisan test --filter=PlotAvailabilityPreviewTest::test_a_second_render_within_the_cache_ttl_does_not_re_query`
Expected: FAIL — the query log still contains a `cemetery_blocks` query on the second render, because `render()` calls `buildShowcase()` directly with no cache layer yet
- [ ] **Step 3: Wrap the read in `Cache::remember`**
```php
use Illuminate\Support\Facades\Cache;

// Inside render(), replacing the direct `$this->buildShowcase($slugs)` call:
        if ($slugs !== []) {
            try {
                $cacheKey = 'homepage:plot-availability-preview:'.md5(implode(',', $slugs));

                $showcase = Cache::remember(
                    $cacheKey,
                    self::CACHE_TTL_SECONDS,
                    fn (): Collection => $this->buildShowcase($slugs),
                );
            } catch (Throwable $e) {
                report($e);
                $unavailable = true;
            }
        }
```
Add the constant alongside the other three:
```php
    private const int CACHE_TTL_SECONDS = 60;
```
- [ ] **Step 4: Run test to verify it passes**
Run: `php artisan test --filter=PlotAvailabilityPreviewTest`
Expected: PASS (5 tests total — Task 1's 3 plus this task's 2)
- [ ] **Step 5: Run Pint and PHPStan**
Run: `vendor/bin/pint --test app/Livewire/Public/Home/PlotAvailabilityPreview.php`
Expected: no style violations
Run: `vendor/bin/phpstan analyse app/Livewire/Public/Home/PlotAvailabilityPreview.php`
Expected: no errors
- [ ] **Step 6: Commit**
```bash
git add app/Livewire/Public/Home/PlotAvailabilityPreview.php tests/Feature/Livewire/Public/Home/PlotAvailabilityPreviewTest.php
git commit -m "perf(homepage): cache the plot-availability preview read for 60s"
```

---

### Task 3: Mutation-safety guardrail test

**Files:**
- Test: `tests/Feature/Livewire/Public/Home/PlotAvailabilityPreviewNeverMutatesTest.php`

**Interfaces:**
- Consumes: `App\Livewire\Public\Home\PlotAvailabilityPreview` (Tasks 1–2, unchanged).
- Produces: nothing new — this task adds no production code, only an executable proof of the Global Constraint that this component exposes no mutating surface.

- [ ] **Step 1: Write the failing (well, currently-vacuous-until-asserted) guardrail test**
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Home;

use App\Livewire\Public\Home\PlotAvailabilityPreview;
use Livewire\Component;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class PlotAvailabilityPreviewNeverMutatesTest extends TestCase
{
    /**
     * docs/superpowers/specs/2026-09-05-marketing-hero-plot-preview-design.md
     * §7/§9 — this component is read-only by construction: it must declare
     * no public method beyond render(), so no wire:click/wire:model binding
     * can ever be added to call into a mutating action without this test
     * failing first. Inherited Livewire framework methods (mount, boot,
     * updated, etc. declared on the base Component class) are excluded —
     * only methods DECLARED on PlotAvailabilityPreview itself count.
     */
    public function test_declares_no_public_method_other_than_render(): void
    {
        $ownClass = new ReflectionClass(PlotAvailabilityPreview::class);
        $baseClass = new ReflectionClass(Component::class);

        $baseMethodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $baseClass->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $declaredPublicMethods = array_filter(
            $ownClass->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === PlotAvailabilityPreview::class
                && ! in_array($method->getName(), $baseMethodNames, true),
        );

        $names = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $declaredPublicMethods);

        $this->assertSame(
            ['render'],
            $names,
            'PlotAvailabilityPreview must declare no public method other than render() — '.
            'any additional public method is a potential wire:click/wire:model target on a read-only surface.',
        );
    }
}
```
- [ ] **Step 2: Run test to verify it passes against the real class from Tasks 1–2**
Run: `php artisan test --filter=PlotAvailabilityPreviewNeverMutatesTest`
Expected: PASS — `PlotAvailabilityPreview` declares only `render()`, matching the assertion
- [ ] **Step 3: Run Pint**
Run: `vendor/bin/pint --test tests/Feature/Livewire/Public/Home/PlotAvailabilityPreviewNeverMutatesTest.php`
Expected: no style violations
- [ ] **Step 4: Commit**
```bash
git add tests/Feature/Livewire/Public/Home/PlotAvailabilityPreviewNeverMutatesTest.php
git commit -m "test(homepage): assert the plot-availability preview exposes no mutating method"
```

---

### Task 4: Wire the section into the homepage

**Files:**
- Modify: `resources/views/livewire/public/home-page.blade.php:173-175`
- Test: `tests/Feature/Livewire/Public/HomePageRouteTest.php`

**Interfaces:**
- Consumes: `<livewire:public.home.plot-availability-preview />` (Task 1's component, tag-resolved by Livewire's default class-to-tag convention — same convention already proven by `<livewire:platform.notification.in-app-notification-list />`).
- Produces: nothing new — this is the plan's final integration point.

- [ ] **Step 1: Write the failing homepage-placement test**
```php
    public function test_plot_availability_preview_is_absent_by_default_against_real_seed_data(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        $response->assertDontSeeText('Lihat Contoh Ketersediaan Plot');
    }

    public function test_plot_availability_preview_renders_between_the_hero_and_the_service_cards_when_data_exists(): void
    {
        $cemetery = \App\Domain\CemeteryDirectory\Models\Cemetery::query()->create([
            'type' => \App\Domain\CemeteryDirectory\CemeteryType::TPU,
            'publication_status' => \App\Domain\CemeteryDirectory\CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Pratinjau Homepage',
            'slug' => 'tpu-pratinjau-homepage',
            'city' => \App\Domain\CemeteryDirectory\LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => \App\Domain\CemeteryDirectory\PlotTrackingMode::GRANULAR,
        ]);

        $block = \App\Domain\PlotInventory\Models\CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 1,
        ]);

        $block->plots()->create(['slot' => '001', 'plot_state' => \App\Domain\PlotInventory\PlotState::AVAILABLE]);

        config(['marketing.homepage_plot_preview_cemetery_slugs' => ['tpu-pratinjau-homepage']]);

        $response = $this->get('/');
        $response->assertOk();

        $body = $response->getContent();
        $this->assertNotFalse($body);

        $heroEnd = strpos($body, 'Pesan Makam');
        $servicesHeading = strpos($body, 'id="services-heading"');
        $previewHeading = strpos($body, 'id="plot-preview-heading"');

        $this->assertNotFalse($heroEnd);
        $this->assertNotFalse($servicesHeading);
        $this->assertNotFalse($previewHeading);
        $this->assertGreaterThan($heroEnd, $previewHeading, 'Preview section must render after the hero.');
        $this->assertLessThan($servicesHeading, $previewHeading, 'Preview section must render before the service cards.');
    }
```
- [ ] **Step 2: Run test to verify it fails**
Run: `php artisan test --filter=HomePageRouteTest::test_plot_availability_preview_renders_between_the_hero_and_the_service_cards_when_data_exists`
Expected: FAIL — `plot-preview-heading` never appears because the component is not yet mounted anywhere in `home-page.blade.php`
- [ ] **Step 3: Insert the component into the homepage view**
```blade
    </x-mk.hero>

    {{-- Plot-availability preview — NEW section, not a tenth entry in IA §3's normative nine-section
         list (same non-renumbering precedent as "Kehangatan Keluarga" below, added 26 Aug 2026 —
         see that section's own comment and this file's top doc block). Sits directly under the
         hero, ahead of the four service cards, per
         docs/superpowers/specs/2026-09-05-marketing-hero-plot-preview-design.md. Renders nothing
         when no showcase cemetery has real per-plot data yet or the read fails (component's own
         try/catch) — never a broken or empty-looking box on the highest-traffic page. --}}
    <livewire:public.home.plot-availability-preview />

    {{-- Section 3: four service cards — AC1's exact order, from
```
(Replace the two-line gap between the existing `</x-mk.hero>` closing tag and the existing `{{-- Section 3: ...` comment with the block above — no other line in the file changes.)
- [ ] **Step 4: Run test to verify it passes**
Run: `php artisan test --filter=HomePageRouteTest`
Expected: PASS — all existing `HomePageRouteTest` assertions plus both new tests green
- [ ] **Step 5: Run the full new-file suite once more end to end**
Run: `php artisan test --filter=PlotAvailabilityPreview`
Expected: PASS (Tasks 1–3's tests, unaffected by this task's view-only change)
- [ ] **Step 6: Run Pint and the docs gate**
Run: `vendor/bin/pint --test resources/views/livewire/public/home-page.blade.php tests/Feature/Livewire/Public/HomePageRouteTest.php`
Expected: no style violations
Run: `bash ci/verify-docs.sh`
Expected: all twelve gates pass, including the hardcoded-design-value and Tailwind-arbitrary-value scans against the two new Blade files from Task 1
- [ ] **Step 7: Commit**
```bash
git add resources/views/livewire/public/home-page.blade.php tests/Feature/Livewire/Public/HomePageRouteTest.php
git commit -m "feat(homepage): mount the plot-availability preview between the hero and service cards"
```
