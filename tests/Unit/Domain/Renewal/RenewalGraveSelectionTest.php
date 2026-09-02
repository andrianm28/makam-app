<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Renewal;

use App\Domain\Renewal\RenewalGraveSelection;
use ReflectionClass;
use Tests\TestCase;

final class RenewalGraveSelectionTest extends TestCase
{
    /**
     * Structural, not behavioural — same instinct as
     * `GraveRegistryPublicQueryTest`'s reflection walk over
     * `GraveRecordProjection`. This class holds no instance state at all,
     * by design: it is never constructed, never a Livewire component's
     * public property, and never `#[Url]`-bound, so the grave id it carries
     * has nowhere to ride along into a Livewire client payload or a query
     * string. A property list assertion would only prove today's shape; an
     * empty-properties assertion proves the class has no state to leak,
     * full stop — the only channel is `Session`, checked below.
     */
    public function test_it_has_no_instance_properties_so_nothing_can_serialise_into_a_client_payload(): void
    {
        $this->assertSame([], (new ReflectionClass(RenewalGraveSelection::class))->getProperties());
    }

    public function test_the_remembered_id_is_reachable_only_through_the_server_side_session_store(): void
    {
        RenewalGraveSelection::remember('0198f000-0000-7000-8000-000000000003');

        $sessionKey = (new ReflectionClass(RenewalGraveSelection::class))->getConstant('SESSION_KEY');

        $this->assertSame('0198f000-0000-7000-8000-000000000003', session($sessionKey));
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
