<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `BookingWizard::currentScreen()` — the consolidation's whole screen
 * boundary. Verified as a pure function directly (mutating the raw PHP
 * instance's `$currentStep`, bypassing Livewire's `#[Locked]`-enforced
 * request cycle, which only guards the client-facing update path — not
 * plain PHP property access on the object this test already holds) and via
 * the real save path for the steps that path can reach.
 */
final class BookingWizardScreenBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_step_maps_to_its_documented_screen(): void
    {
        $wizard = Livewire::test(BookingWizard::class)->instance();

        $expectations = [
            BookingWizardStep::LOCATION => 1,
            BookingWizardStep::CEMETERY => 1,
            BookingWizardStep::SERVICE_TYPE => 1,
            BookingWizardStep::SERVICES => 1,
            BookingWizardStep::SUMMARY => 2,
            BookingWizardStep::CUSTOMER_DATA => 2,
            BookingWizardStep::DECEASED_DATA => 2,
            BookingWizardStep::PAYMENT => 3,
            BookingWizardStep::CONFIRMATION => 4,
        ];

        foreach ($expectations as $step => $expectedScreen) {
            $wizard->currentStep = $step;

            $this->assertSame(
                $expectedScreen,
                $wizard->currentScreen(),
                "Step [{$step}] should map to screen [{$expectedScreen}]."
            );
        }
    }

    /**
     * The real save path (`saveStep1()`) advances `$currentStep` exactly the
     * way `SaveBookingDraftStep` always has — this test proves
     * `currentScreen()` tracks that real transition, not just a
     * hand-set property.
     */
    public function test_completing_step_1_keeps_the_wizard_on_screen_1(): void
    {
        $component = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA);

        $this->assertSame(BookingWizardStep::CEMETERY, $component->get('currentStep'));
        $this->assertSame(1, $component->instance()->currentScreen());
    }
}
