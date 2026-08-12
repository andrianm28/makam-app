<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Renewal;

use App\Domain\Renewal\RenewalJourneyStep;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `App\Domain\Renewal\RenewalJourneyStep` — Sprint 4 S4-T7,
 * `.kiro/specs/renewal-and-grave-registry` AC1: "THE SYSTEM SHALL implement
 * the public renewal flow as six visible steps: city, TPU/TPS, grave
 * search, fee, payment, and confirmation/invoice."
 *
 * This is a product contract, not an implementation detail. `AGENTS.md`
 * §Mandatory MVP UX forbids renaming, reordering, or hiding a documented
 * step, and design-system.md §9.2 MUST-NOT 9 says the same. These
 * assertions exist so that doing any of those three is a deliberate,
 * visible test change rather than silent drift.
 */
final class RenewalJourneyStepTest extends TestCase
{
    public function test_the_journey_has_exactly_six_visible_steps(): void
    {
        $this->assertSame(6, RenewalJourneyStep::count());
        $this->assertCount(6, RenewalJourneyStep::labels());
    }

    /**
     * Keys 1..6 contiguously. `<x-mk.stepper>` re-keys whatever it is given
     * to a contiguous 1..N sequence, so a gap here would not break
     * rendering — it would silently renumber a step, which is worse.
     */
    public function test_steps_are_keyed_one_through_six_in_order(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 6], array_keys(RenewalJourneyStep::labels()));
    }

    public function test_the_six_labels_match_ac1s_order(): void
    {
        $this->assertSame(
            ['Kota', 'TPU/TPS', 'Cari Makam', 'Biaya', 'Pembayaran', 'Konfirmasi'],
            array_values(RenewalJourneyStep::labels())
        );
    }

    /**
     * The named constants must agree with the positions above — the
     * components address steps by constant, the stepper renders by
     * position, and a mismatch would highlight the wrong dot.
     */
    public function test_the_named_constants_point_at_the_right_positions(): void
    {
        $this->assertSame('Kota', RenewalJourneyStep::label(RenewalJourneyStep::CITY));
        $this->assertSame('TPU/TPS', RenewalJourneyStep::label(RenewalJourneyStep::CEMETERY));
        $this->assertSame('Cari Makam', RenewalJourneyStep::label(RenewalJourneyStep::GRAVE_SEARCH));
        $this->assertSame('Biaya', RenewalJourneyStep::label(RenewalJourneyStep::FEE));
        $this->assertSame('Pembayaran', RenewalJourneyStep::label(RenewalJourneyStep::PAYMENT));
        $this->assertSame('Konfirmasi', RenewalJourneyStep::label(RenewalJourneyStep::CONFIRMATION));
    }

    /**
     * L8 Task 4 builds step 4 (fee, AC6/AC7) and Task 5 builds step 5
     * (payment, AC8). Step 6 (confirmation) is next.
     */
    public function test_the_first_five_steps_are_implemented(): void
    {
        $this->assertSame(RenewalJourneyStep::PAYMENT, RenewalJourneyStep::LAST_IMPLEMENTED);
    }

    public function test_a_step_outside_the_range_is_rejected(): void
    {
        $this->assertFalse(RenewalJourneyStep::isKnown(0));
        $this->assertFalse(RenewalJourneyStep::isKnown(7));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown renewal journey step [7]');

        RenewalJourneyStep::assertKnown(7);
    }

    /**
     * The renewal stepper must never accidentally render the BOOKING
     * journey's nine steps. `<x-mk.stepper>`'s `labels` default is those
     * nine, so passing an empty or wrong-length array here would silently
     * show a booking progress bar on a renewal screen.
     */
    public function test_the_renewal_labels_are_not_the_nine_booking_labels(): void
    {
        $labels = array_values(RenewalJourneyStep::labels());

        $this->assertNotContains('Jenis Layanan', $labels);
        $this->assertNotContains('Data Almarhum + Dokumen', $labels);
        $this->assertNotContains('Ringkasan', $labels);
    }
}
