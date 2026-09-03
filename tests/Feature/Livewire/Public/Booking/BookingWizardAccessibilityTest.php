<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Booking\BookingWizard;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Accessibility guarantees of the public booking wizard.
 *
 * The autosave tests here replace a single test
 * (`test_the_autosave_region_is_polite_and_never_a_toast_role`) that could
 * not fail. Three independent reasons, all now covered:
 *
 *  1. It drove the component with `->call('saveStep1', JAKARTA)` and then
 *     asserted on the resulting HTML. `saveStep1()` ends in
 *     `$this->redirect(...)`, and Livewire sets `skipRender` on redirect —
 *     so the HTML under assertion was the *initial idle mount*, never the
 *     `saved` state the test named. Proven empirically: after that call
 *     `->get('autosaveState') === 'saved'` while the rendered HTML contains
 *     none of the saved-state markup.
 *  2. `assertSeeHtml('aria-live="polite"')` is a page-wide substring search.
 *     It is satisfied by ANY polite live region anywhere in the render — a
 *     `<x-mk.alert live="polite">` for an unrelated failure, a future
 *     component — so it does not bind the assertion to the autosave region
 *     at all, and it cannot detect the region being present only when it
 *     already has a message (which is the failure mode that actually breaks
 *     screen-reader announcement).
 *  3. `assertDontSeeHtml('role="alertdialog"')` is unfalsifiable: no view,
 *     component or class in this repository can emit `role="alertdialog"`.
 *     It asserted the absence of something that never existed, while the
 *     roles a regression would realistically introduce (`role="alert"`, an
 *     assertive/auto-dismissing toast) went unchecked.
 *
 * The rewrite asserts the GUARANTEE, not the markup: a page-level live
 * region that is always present, always `polite`, never a toast, and that
 * the autosave message is rendered INSIDE it. Nothing here depends on CSS
 * classes, copy, icons, or element names, so the design lane can keep
 * reworking `wizard.blade.php` without these tests needing edits.
 */
final class BookingWizardAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Roles that turn a status region into an interrupting toast. `status`
     * and `log` are deliberately NOT here: `role="status"` paired with
     * `aria-live="polite"` is the canonical polite-region pattern and is
     * exactly what design-system.md §7.4 asks for. What must never appear
     * is an interrupting role.
     */
    private const TOAST_ROLES = ['alert', 'alertdialog', 'dialog', 'marquee', 'timer'];

    public function test_each_step_heading_has_a_unique_id_targeted_by_aria_labelledby(): void
    {
        // Heading ids carry a `booking-` prefix in the real markup
        // (`booking-step-N-heading`), not the bare `step-N-heading` this
        // test originally assumed. N is now the SCREEN/step number 1-4, the
        // merged DISCOVERY screen being 1.
        $component = Livewire::test(BookingWizard::class);

        $component->assertSeeHtml('aria-labelledby="booking-step-1-heading"');
        $component->assertSeeHtml('id="booking-step-1-heading"');
    }

    /**
     * The stepper must render the FOUR screen labels
     * (`BookingWizardScreen::labels()`), not the nine-step rail the
     * primitive still defaults to. Omitting `:labels` renders a journey this
     * wizard no longer has — nine dots for four real steps, five of which
     * can never become current.
     */
    public function test_the_stepper_renders_the_four_screen_labels_not_the_old_nine_step_labels(): void
    {
        $component = Livewire::test(BookingWizard::class);

        $component->assertSee('Cari & Pilih');
        $component->assertSee('Detail Pemesanan');
        $component->assertSee('Pembayaran');
        $component->assertSee('Konfirmasi');

        // Old individual step labels that no longer exist as steps. 'Jenis
        // Layanan' is also the DISCOVERY sub-section heading's wording, but
        // that section is not revealed until a TPU/TPS is chosen, so on a
        // fresh mount its absence really does prove the nine-dot rail is
        // gone.
        $component->assertDontSee('Jenis Layanan');
        $component->assertDontSee('Ringkasan Pesanan');
        $component->assertDontSee('Data Almarhum + Dokumen');
    }

    public function test_a_field_error_carries_role_alert(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep1', '', null, null, null, [])
            ->assertSeeHtml('role="alert"');
    }

    /**
     * The always-present half of the guarantee. A live region that is only
     * inserted into the DOM together with its message is frequently not
     * announced at all, because assistive technology never observed the
     * region becoming non-empty. So the region must be in the DOM in every
     * autosave state, including `idle`, where it has nothing to say.
     */
    public function test_a_page_level_live_region_exists_in_every_autosave_state(): void
    {
        foreach (['idle', 'saving', 'saved', 'failed'] as $state) {
            $regions = $this->pageLevelLiveRegions($this->renderInAutosaveState($state));

            $this->assertNotEmpty(
                $regions,
                "The booking wizard rendered no page-level live region while autosaveState was '{$state}'. "
                .'The autosave status region must be present in every state, not only when it has a message.'
            );
        }
    }

    /**
     * The polite half. Autosave must never interrupt: design-system.md §7.4
     * ("Autosave: aria-live='polite' region; never steals focus").
     */
    public function test_every_page_level_live_region_is_polite_in_every_autosave_state(): void
    {
        foreach (['idle', 'saving', 'saved', 'failed'] as $state) {
            foreach ($this->pageLevelLiveRegions($this->renderInAutosaveState($state)) as $region) {
                $this->assertSame(
                    'polite',
                    $region->getAttribute('aria-live'),
                    'A page-level live region in the booking wizard was announced as '
                    ."'{$region->getAttribute('aria-live')}' while autosaveState was '{$state}'. "
                    .'Autosave must never interrupt the user.'
                );
            }
        }
    }

    /**
     * The never-a-toast half. Covers both a toast role on the region itself
     * and an Alpine auto-dismiss, which is what actually makes a status
     * region behave like a toast.
     */
    public function test_the_autosave_region_is_never_a_toast(): void
    {
        foreach (['idle', 'saving', 'saved', 'failed'] as $state) {
            foreach ($this->pageLevelLiveRegions($this->renderInAutosaveState($state)) as $region) {
                $role = $region->getAttribute('role');

                $this->assertNotContains(
                    $role,
                    self::TOAST_ROLES,
                    "The booking wizard's page-level live region carried role=\"{$role}\" while autosaveState "
                    ."was '{$state}'. The autosave indicator is inline and must never be an interrupting toast."
                );

                $this->assertFalse(
                    $region->hasAttribute('x-show'),
                    "The booking wizard's page-level live region is conditionally shown/auto-dismissed "
                    ."(x-show) while autosaveState was '{$state}'. A status region that disappears on its "
                    .'own is a toast.'
                );
            }
        }
    }

    /**
     * The message has to land INSIDE the live region, otherwise the region
     * is an empty shell that satisfies every structural assertion above
     * while announcing nothing. Driven through the real failure path —
     * `saveStep1('')` raises a validation error and does NOT redirect, so
     * unlike the success path it genuinely re-renders.
     */
    public function test_a_failed_autosave_is_announced_inside_the_polite_region(): void
    {
        $component = Livewire::test(BookingWizard::class)->call('saveStep1', '', null, null, null, []);

        $this->assertSame('failed', $component->get('autosaveState'));

        $this->assertTrue(
            $this->someLiveRegionHasText($component->html()),
            'A failed autosave rendered no text inside any page-level polite live region. '
            .'The status must be announced by the region, not beside it.'
        );
    }

    public function test_a_saved_autosave_is_announced_inside_the_polite_region(): void
    {
        $html = $this->renderInAutosaveState('saved');

        $this->assertTrue(
            $this->someLiveRegionHasText($html),
            'A saved autosave rendered no text inside any page-level polite live region. '
            .'The status must be announced by the region, not beside it.'
        );
    }

    /**
     * A successful save is good news; it must never be shouted. Nothing in
     * the wizard may carry an assertive/interrupting role while the only
     * thing on screen is a successful autosave and there are no field
     * errors. This is what catches a "saved" toast being introduced as a
     * SIBLING of the polite region rather than inside it.
     */
    public function test_a_successful_autosave_introduces_no_assertive_role_anywhere(): void
    {
        $document = $this->parse($this->renderInAutosaveState('saved'));
        $xpath = new DOMXPath($document);

        foreach (['alert', 'alertdialog'] as $role) {
            $this->assertSame(
                0,
                $xpath->query("//*[@role='{$role}']")?->length ?? 0,
                "A successful autosave rendered role=\"{$role}\". A saved draft must be announced politely."
            );
        }

        $this->assertSame(
            0,
            $xpath->query("//*[@aria-live='assertive']")?->length ?? 0,
            'A successful autosave rendered an assertive live region.'
        );
    }

    /**
     * The region must survive step changes — it is the wizard's single
     * autosave channel, not a per-step decoration. DISCOVERY redirects on
     * success (which suppresses that response's render), so the assertion
     * runs on the FOLLOWING request, which renders step 2.
     */
    public function test_the_live_region_is_still_present_after_advancing_a_step(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $component = Livewire::test(BookingWizard::class)
            ->call(
                'saveStep1',
                LaunchCityCode::JAKARTA,
                $cemetery->id,
                null,
                BookingServiceType::NEW_GRAVE,
                [
                    ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                    ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
                ],
            );

        $this->assertSame(BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, $component->get('currentStep'));

        $onStepTwo = $component->set('autosaveState', 'saved');

        $this->assertNotEmpty(
            $this->pageLevelLiveRegions($onStepTwo->html()),
            'The autosave live region disappeared after the wizard advanced to screen 2.'
        );

        $this->assertTrue(
            $this->someLiveRegionHasText($onStepTwo->html()),
            'The saved status was not announced inside the live region on screen 2.'
        );
    }

    private function renderInAutosaveState(string $state): string
    {
        $component = Livewire::test(BookingWizard::class);

        if ($state !== 'idle') {
            $component = $component->set('autosaveState', $state);
        }

        return $this->assertRenderedSomething($component);
    }

    private function assertRenderedSomething(Testable $component): string
    {
        $html = $component->html();

        // Guard against the exact trap the previous version of this test
        // fell into: asserting on HTML that Livewire never re-rendered.
        $this->assertNotSame('', trim($html), 'The component rendered nothing.');

        return $html;
    }

    /**
     * Live regions that belong to the page rather than to one step's
     * content. Per-step markup lives inside `<section aria-labelledby=
     * "booking-step-N-heading">` blocks that are conditionally rendered;
     * the autosave region is deliberately outside them so it can persist
     * across steps. Selecting structurally (rather than by class or copy)
     * keeps these assertions stable while the view is restyled.
     *
     * @return list<DOMElement>
     */
    private function pageLevelLiveRegions(string $html): array
    {
        $xpath = new DOMXPath($this->parse($html));
        $nodes = $xpath->query('//*[@aria-live][not(ancestor::section)]');

        $regions = [];

        foreach ($nodes ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $regions[] = $node;
            }
        }

        return $regions;
    }

    private function someLiveRegionHasText(string $html): bool
    {
        foreach ($this->pageLevelLiveRegions($html) as $region) {
            if ($region->getAttribute('aria-live') === 'polite'
                && trim((string) preg_replace('/\s+/u', ' ', $region->textContent)) !== '') {
                return true;
            }
        }

        return false;
    }

    private function parse(string $html): DOMDocument
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>'
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }
}
