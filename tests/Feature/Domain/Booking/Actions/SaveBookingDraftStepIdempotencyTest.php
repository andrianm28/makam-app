<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingDraftVersionConflictException;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SaveBookingDraftStepIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_replaying_the_same_idempotency_key_does_not_bump_the_version_twice(): void
    {
        $draft = BookingDraft::create([]);

        $first = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-replay-1');
        $second = (new SaveBookingDraftStep)($first, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-replay-1');

        $this->assertSame($first->version, $second->version);
    }

    public function test_replaying_the_same_key_returns_the_same_persisted_state_even_with_a_different_payload(): void
    {
        // A retried network request replays the SAME key with the SAME
        // original payload in practice, but this proves the replay branch
        // trusts the key over the payload — the correct behaviour for
        // "was this exact call already applied", not "does this payload
        // match".
        $draft = BookingDraft::create([]);

        $first = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-replay-2');
        $second = (new SaveBookingDraftStep)($first, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::BOGOR], 'idem-replay-2');

        $this->assertSame('JAKARTA', $second->city_code, 'A replayed key must not re-apply a changed payload.');
    }

    public function test_a_new_idempotency_key_after_a_replay_applies_normally(): void
    {
        $draft = BookingDraft::create([]);

        $first = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-replay-3');
        $replay = (new SaveBookingDraftStep)($first, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-replay-3');
        $next = (new SaveBookingDraftStep)($replay, BookingWizardStep::CEMETERY, ['cemetery_id' => Cemetery::query()->where('city', LaunchCityCode::JAKARTA)->where('publication_status', 'published')->whereDoesntHave('packages')->firstOrFail()->id], 'idem-replay-4');

        $this->assertSame($replay->version + 1, $next->version);
    }

    public function test_saving_against_a_stale_expected_version_throws_a_conflict(): void
    {
        $draft = BookingDraft::create([]);
        $staleVersion = $draft->version;

        // Simulate a concurrent save from another tab bumping the version first.
        (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-conflict-1');

        $this->expectException(BookingDraftVersionConflictException::class);

        (new SaveBookingDraftStep)($draft->fresh(), BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::BOGOR], 'idem-conflict-2', expectedVersion: $staleVersion);
    }

    public function test_saving_with_no_expected_version_never_conflicts(): void
    {
        $draft = BookingDraft::create([]);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-conflict-3');

        // No $expectedVersion given — must not throw even though the
        // in-memory $draft is now stale relative to what a fresh read
        // would show, since this overload is opt-in.
        $saved = (new SaveBookingDraftStep)($draft->fresh(), BookingWizardStep::CEMETERY, [
            'cemetery_id' => Cemetery::query()->where('city', LaunchCityCode::JAKARTA)->where('publication_status', 'published')->whereDoesntHave('packages')->firstOrFail()->id,
        ], 'idem-conflict-4');

        $this->assertNotNull($saved->cemetery_id);
    }
}
