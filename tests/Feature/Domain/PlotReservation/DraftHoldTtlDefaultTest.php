<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotReservation;

use Tests\TestCase;

/**
 * The shipped default for `plot-reservation.draft_hold_ttl_minutes`.
 *
 * `HoldPlotForDraftTest` already proves the Action HONOURS whatever the
 * config says (it sets the value to 7 and asserts the stamp). Nothing
 * proved what the config actually SHIPS as, and that is the number every
 * real customer gets — an environment that never sets
 * `PLOT_DRAFT_HOLD_TTL_MINUTES` runs on this value, and dev and beta both
 * do exactly that.
 *
 * Why a test rather than trusting the file: the value was 15 for the flow
 * where an operator confirmed availability BEFORE the customer paid, so
 * the hold only had to outlive a form. Once payment moved in front of
 * confirmation, 15 became a number that silently loses plots — and a
 * silent regression back to it would look like a one-character diff in a
 * config file nobody re-reads.
 *
 * The second assertion is the one that carries the reasoning. The hold is
 * not an arbitrary preference; it is a promise that has to survive a round
 * trip to a hosted checkout the customer does not control. Measured on dev
 * 14 Sep 2026, every `payment_sessions` row the provider has ever created
 * carries `expires_at = created_at + 24h`. A hold shorter than a plausible
 * payment attempt is the oversell bug; this pins the floor under it.
 */
final class DraftHoldTtlDefaultTest extends TestCase
{
    /**
     * The window a real e-wallet attempt needs: open the hosted link,
     * switch to a banking or wallet app, mistype a PIN, retry. Fifteen
     * minutes does not cover that; this is the floor below which the
     * default must not silently drift back.
     */
    private const int MINIMUM_VIABLE_PAYMENT_WINDOW_MINUTES = 45;

    public function test_shipped_default_hold_is_sixty_minutes(): void
    {
        $this->assertSame(
            60,
            (int) config('plot-reservation.draft_hold_ttl_minutes'),
            'The shipped draft-hold default changed. This is the value every '
            .'environment that does not set PLOT_DRAFT_HOLD_TTL_MINUTES runs '
            .'on, including dev and beta.',
        );
    }

    public function test_shipped_default_outlives_a_real_payment_attempt(): void
    {
        $this->assertGreaterThanOrEqual(
            self::MINIMUM_VIABLE_PAYMENT_WINDOW_MINUTES,
            (int) config('plot-reservation.draft_hold_ttl_minutes'),
            'The draft hold is now shorter than a plausible hosted-checkout '
            .'payment attempt. A customer who opens the payment link, switches '
            .'to a wallet app and comes back loses the plot while the payment '
            .'still succeeds — the oversell case the pay-first plan names.',
        );
    }
}
