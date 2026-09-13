<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Renewal;

use App\Domain\Renewal\RenewalGraveSelection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

final class RenewalGraveSelectionTest extends TestCase
{
    /**
     * The class doc block's claim is that the selected grave id has nowhere
     * to ride along except the server-side session — no instance property, no
     * static cache, nothing a Livewire payload or a query string could pick
     * up. Flushing the session and asking again is what proves it: if the id
     * were held anywhere belonging to the CLASS, `current()` would still
     * answer after its only documented storage was emptied.
     *
     * An empty-properties reflection assertion proved something narrower —
     * that no *instance* property exists — and would have missed a static
     * cache, which is the shape a "let's avoid a session read per call"
     * optimisation would most plausibly take.
     */
    public function test_the_id_survives_nowhere_but_the_session_so_nothing_can_carry_it_into_a_client_payload(): void
    {
        RenewalGraveSelection::remember('0198f000-0000-7000-8000-000000000003');

        $this->assertSame('0198f000-0000-7000-8000-000000000003', RenewalGraveSelection::current());

        // The class itself is untouched; only its documented storage is
        // emptied.
        Session::flush();

        $this->assertNull(
            RenewalGraveSelection::current(),
            'The grave id outlived the session store — the class is holding state of its own.'
        );
    }

    /**
     * The session is a *server-side* store keyed to the visitor's cookie, so
     * the id must be in the session bag under some key rather than travelling
     * as a rendered value. Asserted against the bag's contents rather than
     * against the private `SESSION_KEY` constant, so this stays true if the
     * key is renamed — what matters is that the value is in the session and
     * that the class can find it again, not what it is filed under.
     */
    public function test_the_remembered_id_is_reachable_only_through_the_server_side_session_store(): void
    {
        RenewalGraveSelection::remember('0198f000-0000-7000-8000-000000000003');

        $this->assertContains(
            '0198f000-0000-7000-8000-000000000003',
            Arr::flatten(Session::all()),
            'The remembered grave id is not in the server-side session bag.'
        );
    }

    public function test_it_round_trips_a_remembered_grave_id(): void
    {
        RenewalGraveSelection::remember('0198f000-0000-7000-8000-000000000001');

        $this->assertSame('0198f000-0000-7000-8000-000000000001', RenewalGraveSelection::current());
    }

    public function test_nothing_remembered_reads_as_null(): void
    {
        $this->assertNull(RenewalGraveSelection::current());
    }

    public function test_forgetting_clears_it(): void
    {
        RenewalGraveSelection::remember('0198f000-0000-7000-8000-000000000002');
        RenewalGraveSelection::forget();

        $this->assertNull(RenewalGraveSelection::current());
    }
}
