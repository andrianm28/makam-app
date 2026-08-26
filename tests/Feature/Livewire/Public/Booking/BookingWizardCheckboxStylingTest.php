<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A UI/UX audit (25 Aug 2026) found every native `<input type="checkbox">`
 * in the wizard (Step 4 service selection, Step 6 privacy consent) rendered
 * with the plain browser-default checked colour instead of the brand
 * `primary-600` (Earth brown) design-system.md §3.2 requires.
 *
 * Root cause (confirmed against `resources/css/*.css` before writing the
 * fix): this app ships no `@tailwindcss/forms` plugin and no
 * `appearance-none` reset anywhere, so the existing `text-primary-600`
 * class on a native checkbox never recolours its checked-state fill in any
 * evergreen browser — only the CSS `accent-color` property does. A real
 * visual regression test isn't practical in this environment (no browser
 * runner here — Playwright is CI-only, see `CLAUDE.md`), so this suite
 * asserts the actual fix mechanically: the rendered HTML carries the real
 * `accent-primary-600` utility (a token-derived Tailwind core utility, not
 * a hardcoded value) alongside the `--radius-xs` (`rounded-xs`) and 44px
 * touch-target (`min-h-11` on the wrapping row) classes design-system.md
 * §3.2 requires.
 */
final class BookingWizardCheckboxStylingTest extends TestCase
{
    use RefreshDatabase;

    private function draftAtStep4(): string
    {
        $draft = (new StartBookingDraft)();
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-a');

        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, ['cemetery_id' => $cemetery->id], 'idem-b');
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICE_TYPE, ['service_type' => 'NEW_GRAVE'], 'idem-c');

        return $draft->id;
    }

    /**
     * Extracts the class attribute of the checkbox `<input>` with the given
     * id, so the assertion is scoped to that one control rather than merely
     * proving the string exists SOMEWHERE on the page.
     */
    private function checkboxClassAttribute(string $html, string $inputId): string
    {
        $matched = preg_match(
            '/<input[^>]*id="'.preg_quote($inputId, '/').'"[^>]*class="([^"]*)"/s',
            $html,
            $matches,
        );

        // Class can precede id in attribute order, so try the reverse form
        // too before giving up.
        if ($matched !== 1) {
            $matched = preg_match(
                '/<input[^>]*class="([^"]*)"[^>]*id="'.preg_quote($inputId, '/').'"/s',
                $html,
                $matches,
            );
        }

        $this->assertSame(1, $matched, "Expected a <input id=\"{$inputId}\"> element with a class attribute in the rendered HTML.");

        return $matches[1];
    }

    public function test_step_4_mandatory_service_checkbox_has_the_real_brand_accent_color_and_radius(): void
    {
        $draftId = $this->draftAtStep4();

        $html = (string) Livewire::test(BookingWizard::class, ['draftId' => $draftId])->html();
        $class = $this->checkboxClassAttribute($html, 'service-DOCUMENT_PROCESSING');

        $this->assertStringContainsString('accent-primary-600', $class);
        $this->assertStringContainsString('rounded-xs', $class);
        $this->assertStringContainsString('size-5', $class);
    }

    public function test_step_4_additional_service_checkbox_has_the_real_brand_accent_color_and_radius(): void
    {
        $draftId = $this->draftAtStep4();

        $html = (string) Livewire::test(BookingWizard::class, ['draftId' => $draftId])->html();
        $class = $this->checkboxClassAttribute($html, 'service-AMBULANCE');

        $this->assertStringContainsString('accent-primary-600', $class);
        $this->assertStringContainsString('rounded-xs', $class);
        $this->assertStringContainsString('size-5', $class);
    }

    /**
     * design-system.md §3.2: "20 px box inside a 44 px clickable row." The
     * 20px box is `size-5` on the `<input>` (asserted above); the 44px
     * clickable ROW is the wrapping `<label>`'s `min-h-11` — the two must
     * not be conflated into one element, which is exactly the pre-fix
     * defect (Step 4's old markup put `touch-target` — a 44px min-size
     * utility — directly on the `<input>` itself, inflating the visible
     * checkbox to 44px square instead of sizing the row).
     */
    public function test_step_4_checkbox_row_meets_the_44px_touch_target_via_the_wrapping_label(): void
    {
        $draftId = $this->draftAtStep4();

        $html = (string) Livewire::test(BookingWizard::class, ['draftId' => $draftId])->html();

        $this->assertMatchesRegularExpression(
            '/<label\s+for="service-DOCUMENT_PROCESSING"\s+class="[^"]*min-h-11[^"]*"/s',
            $html,
        );
    }

    public function test_step_6_privacy_consent_checkbox_has_the_real_brand_accent_color_and_radius(): void
    {
        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->get('draftId');
        $this->assertIsString($draftId);

        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep2', $cemetery->id)
            ->call('saveStep3', 'NEW_GRAVE')
            ->call('continueFromStep4')
            ->call('goToStep', BookingWizardStep::CUSTOMER_DATA);

        $class = $this->checkboxClassAttribute((string) $component->html(), 'privacy-notice-accepted');

        $this->assertStringContainsString('accent-primary-600', $class);
        $this->assertStringContainsString('rounded-xs', $class);
        $this->assertStringContainsString('size-5', $class);
    }

    /**
     * The error-state override: `wizard.blade.php`'s privacy checkbox
     * swaps in `accent-danger-600` alongside `border-danger-600` once the
     * field is invalid, mirroring `field.blade.php`'s own error-state
     * checkbox recipe (both were fixed together, see that file's doc
     * comment).
     */
    public function test_step_6_privacy_consent_checkbox_switches_to_the_danger_accent_when_invalid(): void
    {
        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->get('draftId');
        $this->assertIsString($draftId);

        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep2', $cemetery->id)
            ->call('saveStep3', 'NEW_GRAVE')
            ->call('continueFromStep4')
            ->call('goToStep', BookingWizardStep::CUSTOMER_DATA)
            // Submit Step 6 with everything blank, including the unticked
            // privacy checkbox, so `privacy_notice_accepted` fails
            // validation and the error-state class branch renders.
            ->call('saveStep6');

        $component->assertHasErrors(['privacy_notice_accepted']);

        $class = $this->checkboxClassAttribute((string) $component->html(), 'privacy-notice-accepted');

        $this->assertStringContainsString('accent-danger-600', $class);
        $this->assertStringContainsString('border-danger-600', $class);
    }
}
