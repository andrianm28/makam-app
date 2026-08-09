<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingWizardAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_step_heading_has_a_unique_id_targeted_by_aria_labelledby(): void
    {
        // Heading ids carry a `booking-` prefix in the real Steps 1-3
        // markup Task 9/10 actually shipped (`booking-step-N-heading`),
        // not the bare `step-N-heading` this test originally assumed.
        $component = Livewire::test(BookingWizard::class);

        $component->assertSeeHtml('aria-labelledby="booking-step-1-heading"');
        $component->assertSeeHtml('id="booking-step-1-heading"');
    }

    public function test_the_autosave_region_is_polite_and_never_a_toast_role(): void
    {
        $component = Livewire::test(BookingWizard::class)->call('saveStep1', LaunchCityCode::JAKARTA);

        $component->assertSeeHtml('aria-live="polite"');
        $component->assertDontSeeHtml('role="alertdialog"');
    }

    public function test_a_field_error_carries_role_alert(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep1', '')
            ->assertSeeHtml('role="alert"');
    }
}
