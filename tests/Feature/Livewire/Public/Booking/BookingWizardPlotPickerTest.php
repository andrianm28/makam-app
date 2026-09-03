<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingWizardPlotPickerTest extends TestCase
{
    use RefreshDatabase;

    private function makeCemetery(
        string $trackingMode,
        string $publicationStatus = CemeteryPublicationStatus::PUBLISHED,
        string $city = LaunchCityCode::JAKARTA,
    ): Cemetery {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => $publicationStatus,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => $city,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => $trackingMode,
        ]);
    }

    private function makePlotIn(Cemetery $cemetery): GravePlot
    {
        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 1,
        ]);

        return GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '001',
            'plot_state' => PlotState::AVAILABLE,
        ]);
    }

    /**
     * Same technique `BookingWizardDegradedReadsTest::
     * makeServiceCatalogUnreadable()` established for the services
     * catalogue: drop the one FK pointing at the table `pickerBlocks()`'s
     * query reaches, then drop the table itself, so the read genuinely
     * fails at the database rather than merely returning nothing.
     */
    private function makeCemeteryBlocksUnreadable(): void
    {
        Schema::table('grave_plots', function (Blueprint $table): void {
            $table->dropForeign(['block_id']);
        });
        Schema::dropIfExists('cemetery_blocks');
    }

    /**
     * A draft that exists but has NOTHING from DISCOVERY persisted onto it
     * yet — the real state the picker now operates in.
     *
     * This used to drive the component through the old Step 1 (`saveStep1(
     * $cityCode)`, the bare city chooser). Under the DISCOVERY merge there is
     * no such half-step to drive: `saveStep1()` now completes the WHOLE of
     * DISCOVERY in one call and advances the draft past it, which is the
     * opposite of the state these tests need. So the draft is created through
     * the same Action `holdPlotForDiscovery()` itself now uses lazily
     * (`StartBookingDraft`), which also issues the session binding — exactly
     * what a real first plot-pick does.
     */
    private function draftIdAtDiscovery(): string
    {
        return (new StartBookingDraft)(null)->id;
    }

    /**
     * The JS expressions `$this->js()` queued during the last round-trip.
     * Livewire surfaces them as the `xjs` effect
     * (`SupportJsEvaluation::dehydrate()`), each entry carrying an
     * `expression` key.
     *
     * @return list<string>
     */
    private function evaluatedJsFrom(Testable $component): array
    {
        $xjs = $component->effects['xjs'] ?? [];

        if (! is_array($xjs)) {
            return [];
        }

        return array_values(array_map(
            static fn (array $entry): string => (string) ($entry['expression'] ?? ''),
            $xjs,
        ));
    }

    /**
     * @return list<array{code: string, quantity: int}>
     */
    private function basicServiceSelections(): array
    {
        return [
            ['code' => ServiceCode::DOCUMENT_PROCESSING, 'quantity' => 1],
            ['code' => ServiceCode::GRAVE_DIGGING, 'quantity' => 1],
        ];
    }

    public function test_the_picker_renders_for_a_granular_cemetery_at_discovery(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draftId = $this->draftIdAtDiscovery();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openPickerFor', $cemetery->id)
            ->assertSee('BLOK-A')
            ->assertSee('001');
    }

    public function test_the_picker_never_renders_for_an_aggregate_cemetery(): void
    {
        $this->makeCemetery(PlotTrackingMode::AGGREGATE);
        $draftId = $this->draftIdAtDiscovery();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertDontSee('Lihat Peta Plot');
    }

    /**
     * `pickerBlocks()` previously had no guard around its own query — a
     * genuine DB failure (found in an audit of the shipped Phase E work,
     * 3 Sep 2026) would throw straight to a 500 instead of degrading, the
     * one query in this file `render()`'s own reads were already careful
     * about. Real data exists for this cemetery (`makePlotIn()`), so
     * `assertDontSee('Belum ada plot terdaftar')` proves this is genuinely
     * the FAILURE state, not the pre-existing empty state reusing the same
     * render path.
     */
    public function test_a_failed_picker_blocks_read_degrades_honestly_instead_of_500ing(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $this->makePlotIn($cemetery);
        $draftId = $this->draftIdAtDiscovery();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);

        $this->makeCemeteryBlocksUnreadable();

        $component->call('openPickerFor', $cemetery->id)
            ->assertOk()
            ->assertSee('Peta plot sedang tidak dapat dimuat')
            ->assertDontSee('Belum ada plot terdaftar');

        $this->assertTrue($component->instance()->pickerBlocksUnavailable);
    }

    /**
     * The behavioural heart of the plot-hold restructure. The hold is still
     * taken IMMEDIATELY at plot-pick time (a contended plot is the thing
     * worth winning), but it no longer drags a `SaveBookingDraftStep` call
     * along with it: DISCOVERY is not complete until service type and
     * services are chosen too, so nothing DISCOVERY-shaped is persisted here.
     */
    public function test_holding_a_plot_persists_the_hold_without_advancing_the_draft(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $plot = $this->makePlotIn($cemetery);
        $draftId = $this->draftIdAtDiscovery();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openPickerFor', $cemetery->id)
            ->call('holdPlotForDiscovery', $cemetery->id, null, (string) $plot->getKey())
            ->assertHasNoErrors();

        $draft = BookingDraft::query()->findOrFail($draftId);
        $hold = PlotReservation::activeForDraft($draft);

        $this->assertNotNull($hold);
        $this->assertSame($plot->getKey(), $hold->plot_id);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);

        // The draft has NOT moved on — DISCOVERY is still in progress.
        $this->assertNull($draft->cemetery_id);
        $this->assertSame(BookingWizardStep::DISCOVERY, $draft->current_step);
        $this->assertSame([], $draft->completed_steps);
    }

    /**
     * `holdPlotForDiscovery()` is now the FIRST thing in the journey that
     * needs a real `booking_drafts` row (a hold's own foreign key requires
     * one), because the merged `saveStep1()` no longer runs until service
     * type and services are also chosen. So it creates the draft itself —
     * without persisting any DISCOVERY field and without redirecting.
     */
    public function test_holding_a_plot_does_not_immediately_persist_the_draft(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $plot = $this->makePlotIn($cemetery);

        $component = Livewire::test(BookingWizard::class)
            ->call('openPickerFor', $cemetery->id)
            ->call('holdPlotForDiscovery', $cemetery->id, null, (string) $plot->getKey());

        // The hold exists, and a BookingDraft row now exists too — that row
        // is required for the hold's own FK — but nothing DISCOVERY-shaped
        // was persisted onto it yet.
        $this->assertDatabaseHas('plot_reservations', ['plot_id' => $plot->getKey()]);
        $this->assertIsString($component->get('draftId'));

        // DISCOVERY is not complete until service type + services are also
        // chosen, so the draft's cemetery_id is still unset.
        $this->assertDatabaseMissing('booking_drafts', ['cemetery_id' => $cemetery->id]);
    }

    /**
     * Whole-branch review I3a. `openPickerFor()` is a public Livewire
     * method, so `pickerCemeteryId` is client-chosen input: an anonymous
     * visitor holding an unpublished cemetery's real UUID must still learn
     * nothing about its blocks or plots.
     */
    public function test_the_picker_never_exposes_an_unpublished_cemetery(): void
    {
        $unpublished = $this->makeCemetery(
            PlotTrackingMode::GRANULAR,
            publicationStatus: CemeteryPublicationStatus::DRAFT,
        );
        $this->makePlotIn($unpublished);
        $draftId = $this->draftIdAtDiscovery();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openPickerFor', $unpublished->id);

        $this->assertFalse($component->instance()->pickerAppliesTo($unpublished->id));
        $this->assertCount(0, $component->instance()->pickerBlocks());
        $component->assertDontSee('BLOK-A')->assertDontSee('001');
    }

    /**
     * The counterpart to the release-on-failure behaviour this restructure
     * deliberately DROPPED.
     *
     * The old flow held the plot and immediately saved the cemetery, so a
     * rejected save meant the hold had been taken for a step that never
     * happened and was rolled back. Under the merge, four sub-choices share
     * ONE save/validate unit — so a mistake in a completely unrelated field
     * (here, an empty city) must not cost the customer a perfectly valid plot
     * pick they already made. The scheduled TTL sweep
     * (`plot-reservation:expire-stale-draft-holds`) is the safety net for a
     * genuinely abandoned attempt; this failure path is not abandonment.
     */
    public function test_a_failed_discovery_save_does_not_release_the_hold(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $plot = $this->makePlotIn($cemetery);

        Livewire::test(BookingWizard::class)
            ->call('openPickerFor', $cemetery->id)
            ->call('holdPlotForDiscovery', $cemetery->id, null, (string) $plot->getKey())
            // Empty city_code — fails validateLocation(), entirely unrelated
            // to the plot the customer already picked.
            ->call('saveStep1', '', $cemetery->id, null, BookingServiceType::NEW_GRAVE, $this->basicServiceSelections())
            ->assertHasErrors(['city_code']);

        $this->assertDatabaseHas('plot_reservations', [
            'plot_id' => $plot->getKey(),
            'state' => PlotReservationState::HELD,
        ]);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
    }

    /**
     * A hold that PREDATES the component call (returned by
     * `HoldPlotForDraft` as the incumbent rather than created fresh) also
     * survives a later failed DISCOVERY save. Same outcome as the test above
     * — but reached through the incumbent fast path, which is the branch the
     * dropped `wasRecentlyCreated` check used to discriminate on.
     */
    public function test_a_pre_existing_hold_survives_a_failed_discovery_save(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $plot = $this->makePlotIn($cemetery);
        $draftId = $this->draftIdAtDiscovery();
        $draft = BookingDraft::query()->findOrFail($draftId);

        $existing = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('holdPlotForDiscovery', $cemetery->id, null, (string) $plot->getKey())
            ->call('saveStep1', '', $cemetery->id, null, BookingServiceType::NEW_GRAVE, $this->basicServiceSelections())
            ->assertHasErrors(['city_code'])
            ->assertSet('autosaveState', 'failed');

        $still = PlotReservation::activeForDraft($draft->fresh());
        $this->assertNotNull($still);
        $this->assertSame($existing->getKey(), $still->getKey());
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
    }

    /**
     * The cross-request correctness this whole restructure turns on: a hold
     * taken in ONE Livewire round-trip, and the DISCOVERY save attempted in a
     * LATER one, must land on the SAME draft.
     *
     * If `holdPlotForDiscovery()`'s lazily-created draft did not carry a
     * session binding forward, the later `saveStep1()` would resolve nothing,
     * fall back to `StartBookingDraft` a second time, and persist DISCOVERY
     * onto a BRAND-NEW draft — orphaning the held plot on the first one,
     * where nothing would ever convert or release it before the TTL. Asserting
     * the draft COUNT is what actually catches that; asserting only that the
     * hold still exists would pass vacuously.
     */
    public function test_a_held_plot_stays_attached_to_the_draft_a_later_discovery_save_writes(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $plot = $this->makePlotIn($cemetery);

        $component = Livewire::test(BookingWizard::class)
            ->call('openPickerFor', $cemetery->id)
            ->call('holdPlotForDiscovery', $cemetery->id, null, (string) $plot->getKey());

        $draftId = $component->get('draftId');
        $this->assertIsString($draftId);

        $component
            ->call('saveStep1', LaunchCityCode::JAKARTA, $cemetery->id, null, BookingServiceType::NEW_GRAVE, $this->basicServiceSelections())
            ->assertHasNoErrors();

        $this->assertSame(1, BookingDraft::query()->count());

        $draft = BookingDraft::query()->findOrFail($draftId);
        $this->assertSame($cemetery->id, $draft->cemetery_id);
        $this->assertSame(BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, $draft->current_step);

        $hold = PlotReservation::activeForDraft($draft);
        $this->assertNotNull($hold);
        $this->assertSame($plot->getKey(), $hold->plot_id);
    }

    /**
     * Task-review finding I1. The nine-step flow guaranteed the draft id was
     * in the browser URL before the picker was ever reachable, because the
     * old `saveStep1($cityCode)` created the draft and redirected to
     * `pemesanan-makam.draft`. Under the merge the draft is created lazily by
     * `holdPlotForDiscovery()` and there is deliberately no redirect (it would
     * remount the component and wipe the customer's staged selections) — so
     * something else has to put the id in the address bar.
     *
     * Without it, a reload is unrecoverable: `BookingDraftBinding` keys its
     * session secret BY draft id (`Session::put(KEY.'.'.$draft->id, ...)`),
     * so an id the browser no longer remembers cannot be enumerated back out
     * of the session. The customer would then create a SECOND draft, fail to
     * re-hold their own plot (`PlotNotAvailableException`), and be told the
     * plot "was just taken by another visitor" while it stayed squatted under
     * the orphaned first draft until the TTL sweep.
     */
    public function test_holding_a_plot_puts_the_resumable_draft_url_in_the_address_bar(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $plot = $this->makePlotIn($cemetery);

        $component = Livewire::test(BookingWizard::class)
            ->call('openPickerFor', $cemetery->id)
            ->call('holdPlotForDiscovery', $cemetery->id, null, (string) $plot->getKey());

        $draftId = $component->get('draftId');
        $this->assertIsString($draftId);

        $expectedUrl = route('pemesanan-makam.draft', ['draftId' => $draftId]);
        $scripts = $this->evaluatedJsFrom($component);

        $this->assertNotSame([], $scripts, 'The hold must push the resumable URL to the browser.');
        $this->assertStringContainsString('history.replaceState', implode("\n", $scripts));
        $this->assertStringContainsString($expectedUrl, implode("\n", $scripts));
    }

    /**
     * The end-to-end proof of the same finding: a REAL page load between the
     * plot hold and the DISCOVERY save (a fresh component instance with no
     * carried-over Livewire state, which is what F5 / back-navigation
     * produces) must recover the hold rather than orphan it.
     */
    public function test_a_reload_between_the_hold_and_the_discovery_save_recovers_the_hold(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $plot = $this->makePlotIn($cemetery);

        $draftId = Livewire::test(BookingWizard::class)
            ->call('openPickerFor', $cemetery->id)
            ->call('holdPlotForDiscovery', $cemetery->id, null, (string) $plot->getKey())
            ->get('draftId');

        $this->assertIsString($draftId);

        // The reload. Nothing from the previous component instance survives
        // except what the URL carries.
        $reloaded = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);

        $reloaded->assertSet('draftId', $draftId)
            // The picker reopens on the live hold, and the cemetery the
            // customer already committed to is restored — even though the
            // draft has NO cemetery_id yet (DISCOVERY is still unsaved).
            ->assertSet('pickerCemeteryId', $cemetery->id)
            ->assertSet('cemeteryId', $cemetery->id)
            ->assertSee('Ditahan');

        // Re-picking the same plot is the incumbent fast path, NOT
        // "this plot was just taken by another visitor".
        $reloaded->call('holdPlotForDiscovery', $cemetery->id, null, (string) $plot->getKey())
            ->assertHasNoErrors();

        // The decisive assertion: still ONE draft, so the hold was never
        // orphaned onto an abandoned row.
        $this->assertSame(1, BookingDraft::query()->count());
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
    }

    /**
     * A city can hold BOTH granular cemeteries (plot picker) and aggregate
     * ones (a plain "Pilih" button), and the picker renders from its own
     * state as a sibling of the TPU/TPS list. So choosing an aggregate
     * cemetery after picking a plot in a granular one has to CLOSE that
     * picker: otherwise the customer sees the other cemetery's grid, and its
     * "plot ditahan" alert, sitting live under a selection that is no longer
     * theirs — and one click in that stale grid silently flips the selection
     * back.
     */
    public function test_choosing_another_cemetery_closes_a_picker_left_open_on_the_previous_one(): void
    {
        $granular = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $plot = $this->makePlotIn($granular);
        $aggregate = $this->makeCemetery(PlotTrackingMode::AGGREGATE);

        $component = Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->call('openPickerFor', $granular->id)
            ->call('holdPlotForDiscovery', $granular->id, null, (string) $plot->getKey())
            ->assertSet('cemeteryId', $granular->id)
            ->assertSet('pickerCemeteryId', $granular->id);

        $component->call('selectCemetery', $aggregate->id)
            ->assertSet('cemeteryId', $aggregate->id)
            ->assertSet('pickerCemeteryId', null)
            ->assertSet('pickerCemeteryPackageId', null)
            // No stale grid, and no hold alert for a cemetery the customer
            // has just moved away from.
            ->assertDontSee('BLOK-A')
            ->assertDontSee('Plot ditahan sementara');
    }

    /**
     * While a picker IS open, its heading names the cemetery — the selection
     * it can change is otherwise unlabelled, which is what made a stale
     * picker hard to notice in the first place.
     */
    public function test_the_picker_heading_names_the_cemetery_whose_plots_are_shown(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $this->makePlotIn($cemetery);

        Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->call('openPickerFor', $cemetery->id)
            ->assertSee('Pilih plot di '.$cemetery->name);
    }

    /**
     * The other half of that recovery, which the test above does NOT cover:
     * the CITY has to come back too.
     *
     * `city_code` is as unwritten as `cemetery_id` mid-DISCOVERY, and the
     * TPU/TPS section reveals on `$city !== ''`, so without this the customer
     * comes back to a screen offering only the city buttons — and clicking
     * their city then runs `selectCity()`'s cross-city cascade (`''` is not
     * the real code, so the early return does not save them), which clears
     * `cemeteryId` and `pickerCemeteryId` and destroys exactly the state the
     * reload recovered. The evidence for the city is the same evidence as for
     * the cemetery: the live hold.
     */
    public function test_a_reload_with_a_live_hold_restores_the_city_so_the_screen_reveals(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR, city: LaunchCityCode::BOGOR);
        $plot = $this->makePlotIn($cemetery);

        $draftId = Livewire::test(BookingWizard::class)
            ->call('openPickerFor', $cemetery->id)
            ->call('holdPlotForDiscovery', $cemetery->id, null, (string) $plot->getKey())
            ->get('draftId');

        $this->assertIsString($draftId);
        $this->assertNull(
            BookingDraft::query()->findOrFail($draftId)->city_code,
            'Fixture assumption: a hold alone persists no city_code — that is what makes the restore necessary.',
        );

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('city', LaunchCityCode::BOGOR)
            // ...and the restored city really does reveal the section, which
            // is the point of restoring it.
            ->assertSee('Pilih TPU/TPS')
            ->assertSee($cemetery->name);
    }

    /**
     * And the recovered state survives the customer re-clicking the city they
     * are already on — `selectCity()`'s early return is what protects the
     * hold here, and it only fires because the city was restored above.
     */
    public function test_re_clicking_the_restored_city_does_not_wipe_the_recovered_hold_state(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR, city: LaunchCityCode::BOGOR);
        $plot = $this->makePlotIn($cemetery);

        $draftId = Livewire::test(BookingWizard::class)
            ->call('openPickerFor', $cemetery->id)
            ->call('holdPlotForDiscovery', $cemetery->id, null, (string) $plot->getKey())
            ->get('draftId');

        Livewire::test(BookingWizard::class, ['draftId' => (string) $draftId])
            ->call('selectCity', LaunchCityCode::BOGOR)
            ->assertSet('cemeteryId', $cemetery->id)
            ->assertSet('pickerCemeteryId', $cemetery->id);
    }

    /**
     * And the journey still completes after that reload — the recovered draft
     * is the one DISCOVERY writes to.
     */
    public function test_discovery_completes_normally_after_a_reload_mid_selection(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $plot = $this->makePlotIn($cemetery);

        $draftId = Livewire::test(BookingWizard::class)
            ->call('openPickerFor', $cemetery->id)
            ->call('holdPlotForDiscovery', $cemetery->id, null, (string) $plot->getKey())
            ->get('draftId');

        $this->assertIsString($draftId);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('city', LaunchCityCode::JAKARTA)
            ->set('serviceType', BookingServiceType::NEW_GRAVE)
            ->set('stagedServiceCodes', ServiceCode::BASIC_CODES)
            ->call('continueFromDiscovery')
            ->assertHasNoErrors();

        $this->assertSame(1, BookingDraft::query()->count());

        $draft = BookingDraft::query()->findOrFail($draftId);
        $this->assertSame($cemetery->id, $draft->cemetery_id);
        $this->assertSame(BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, $draft->current_step);

        $hold = PlotReservation::activeForDraft($draft);
        $this->assertNotNull($hold);
        $this->assertSame($plot->getKey(), $hold->plot_id);
    }

    public function test_save_step1_persists_all_five_discovery_fields_in_one_call(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);

        $component = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA, $cemetery->id, null, BookingServiceType::NEW_GRAVE, $this->basicServiceSelections());

        $component->assertHasNoErrors();

        $draft = BookingDraft::query()->latest()->first();
        $this->assertNotNull($draft);
        $this->assertSame(LaunchCityCode::JAKARTA, $draft->city_code);
        $this->assertSame($cemetery->id, $draft->cemetery_id);
        $this->assertSame(BookingServiceType::NEW_GRAVE, $draft->service_type);
        $this->assertSame(BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, $draft->current_step);
        $this->assertSame([BookingWizardStep::DISCOVERY], $draft->completed_steps);
    }

    /**
     * `continueFromDiscovery()` is the Blade "Lanjutkan" trigger: it reads the
     * component's own selection state and builds the
     * `list<array{code, quantity}>` shape `saveStep1()` needs, which a
     * client-side `wire:click` expression cannot construct.
     */
    public function test_continue_from_discovery_saves_the_components_own_selection_state(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);

        Livewire::test(BookingWizard::class)
            ->set('city', LaunchCityCode::JAKARTA)
            ->set('cemeteryId', $cemetery->id)
            ->set('serviceType', BookingServiceType::NEW_GRAVE)
            ->set('stagedServiceCodes', ServiceCode::BASIC_CODES)
            ->call('continueFromDiscovery')
            ->assertHasNoErrors();

        $draft = BookingDraft::query()->latest()->first();
        $this->assertNotNull($draft);
        $this->assertSame($cemetery->id, $draft->cemetery_id);
        $this->assertSame(
            ServiceCode::BASIC_CODES,
            array_column($draft->selected_services, 'code'),
        );
    }

    /**
     * "Lanjutkan" is a public Livewire method, so it is reachable directly by
     * any client regardless of what Blade renders. With nothing selected it
     * must produce ordinary inline field errors, never a `TypeError` 500 from
     * a null being handed to a non-nullable parameter.
     */
    public function test_continue_from_discovery_with_nothing_selected_reports_field_errors(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('continueFromDiscovery')
            ->assertHasErrors(['city_code']);

        $this->assertSame(0, BookingDraft::query()->whereNotNull('city_code')->count());
    }

    /**
     * `selectCity()` closes the picker but deliberately leaves the hold in
     * the old cemetery to its TTL. Opening a picker on a granular cemetery in
     * the NEW city must not raise that hold's "Plot ditahan sementara" alert
     * over a grid of plots none of which are held — the alert belongs to the
     * picker it renders inside. Backend counterpart:
     * `SubmitBookingDraftConvertsPlotHoldTest::
     * test_a_hold_in_a_different_cemetery_than_the_draft_is_not_converted()`.
     */
    public function test_a_hold_in_another_cemetery_does_not_raise_the_alert_over_this_picker(): void
    {
        $jakarta = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $heldPlot = $this->makePlotIn($jakarta);

        $bogor = $this->makeCemetery(PlotTrackingMode::GRANULAR, city: LaunchCityCode::BOGOR);
        $this->makePlotIn($bogor);

        $component = Livewire::test(BookingWizard::class)
            ->call('selectCity', LaunchCityCode::JAKARTA)
            ->call('openPickerFor', $jakarta->id)
            ->call('holdPlotForDiscovery', $jakarta->id, null, (string) $heldPlot->getKey())
            ->assertSee('Plot ditahan sementara');

        $component->call('selectCity', LaunchCityCode::BOGOR)
            ->assertSet('pickerCemeteryId', null)
            ->call('openPickerFor', $bogor->id)
            ->assertSet('pickerCemeteryId', $bogor->id)
            ->assertDontSee('Plot ditahan sementara');

        // The hold itself is untouched — only the alert is scoped.
        $this->assertSame(
            1,
            PlotReservation::query()->where('plot_id', $heldPlot->getKey())->count(),
            'Scoping the alert must not release the hold; its TTL still owns that.',
        );
    }

    public function test_resuming_the_wizard_shows_the_already_held_plot(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draftId = $this->draftIdAtDiscovery();
        $draft = BookingDraft::query()->findOrFail($draftId);
        // The draft must actually have saved this cemetery for mount()'s
        // resume branch to know which cemetery's picker to reopen.
        $draft->forceFill(['cemetery_id' => $cemetery->id])->saveQuietly();
        app(HoldPlotForDraft::class)($plot, $draft, "booking_draft:{$draft->getKey()}");

        // A FRESH mount — no explicit openPickerFor() call — must reopen
        // the picker and show the live hold on its own.
        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSee('Ditahan');
    }
}
