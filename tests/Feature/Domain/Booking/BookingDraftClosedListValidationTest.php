<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking;

use App\Domain\Booking\Models\BookingDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class BookingDraftClosedListValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_can_be_created_with_no_optional_fields_set(): void
    {
        $draft = BookingDraft::create([]);

        $this->assertNotNull($draft->id);
        $this->assertTrue(Str::isUuid($draft->id));
        $this->assertSame(1, $draft->current_step);
        $this->assertSame([], $draft->completed_steps);
        $this->assertSame([], $draft->selected_services);
        $this->assertSame(1, $draft->version);
    }

    public function test_an_unknown_city_code_is_rejected_on_save(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BookingDraft::create(['city_code' => 'SURABAYA']);
    }

    public function test_a_known_city_code_is_accepted(): void
    {
        $draft = BookingDraft::create(['city_code' => 'JAKARTA']);

        $this->assertSame('JAKARTA', $draft->city_code);
    }

    public function test_a_null_city_code_is_accepted(): void
    {
        $draft = BookingDraft::create(['city_code' => null]);

        $this->assertNull($draft->city_code);
    }

    public function test_an_unknown_service_type_is_rejected_on_save(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BookingDraft::create(['service_type' => 'CREMATION']);
    }

    public function test_a_known_service_type_is_accepted(): void
    {
        $draft = BookingDraft::create(['service_type' => 'URGENT_TODAY']);

        $this->assertSame('URGENT_TODAY', $draft->service_type);
    }

    /**
     * `id` doubles as this journey's anonymous resume token, so it must not
     * be mass-assignable: `HasUuids` generates an unguessable one, and no
     * caller in this codebase passes its own.
     */
    public function test_the_resume_token_id_is_not_mass_assignable(): void
    {
        $chosenId = '11111111-1111-4111-8111-111111111111';

        $draft = BookingDraft::create(['id' => $chosenId]);

        $this->assertNotSame($chosenId, $draft->id);
        $this->assertTrue(Str::isUuid($draft->id));
    }
}
