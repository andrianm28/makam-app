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

/**
 * ---------------------------------------------------------------------------
 * Why every assertion here re-reads the row instead of trusting a return value
 * ---------------------------------------------------------------------------
 * `SaveBookingDraftStep`'s replay branch returns THE VERY `BookingDraft`
 * INSTANCE it was passed. An earlier version of this file compared the two
 * returned objects — `assertSame($first->version, $second->version)` — which
 * compared an object's property to its own property and was therefore
 * trivially true whether the replay branch worked, was deleted, or wrote
 * twice. It proved object identity, not persistence.
 *
 * Every assertion below now reads the draft back out of the database with
 * `freshFromDatabase()` and asserts against THAT, so a regression in the
 * replay branch (a second write, a second version bump, a re-applied
 * payload) fails the test.
 */
final class SaveBookingDraftStepIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function freshFromDatabase(string $draftId): BookingDraft
    {
        return BookingDraft::query()->findOrFail($draftId);
    }

    private function jakartaCemeteryWithoutPackages(): Cemetery
    {
        return Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();
    }

    public function test_replaying_the_same_idempotency_key_does_not_bump_the_version_twice(): void
    {
        $draft = BookingDraft::create([]);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-replay-1');

        $afterFirst = $this->freshFromDatabase($draft->id);
        $this->assertSame(2, $afterFirst->version, 'A fresh draft starts at version 1; one applied save makes it 2.');

        (new SaveBookingDraftStep)($afterFirst, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-replay-1');

        $afterReplay = $this->freshFromDatabase($draft->id);
        $this->assertSame(2, $afterReplay->version, 'A replayed key must not write, and therefore must not bump the version.');
        $this->assertSame($afterFirst->updated_at?->toIso8601String(), $afterReplay->updated_at?->toIso8601String());
    }

    public function test_replaying_the_same_key_returns_the_same_persisted_state_even_with_a_different_payload(): void
    {
        // A retried network request replays the SAME key with the SAME
        // original payload in practice, but this proves the replay branch
        // trusts the key over the payload — the correct behaviour for
        // "was this exact call already applied", not "does this payload
        // match".
        $draft = BookingDraft::create([]);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-replay-2');

        $second = (new SaveBookingDraftStep)($this->freshFromDatabase($draft->id), BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::BOGOR], 'idem-replay-2');

        $this->assertSame('JAKARTA', $second->city_code, 'A replayed key must not re-apply a changed payload.');
        $this->assertSame('JAKARTA', $this->freshFromDatabase($draft->id)->city_code, 'And the change must not have reached the database either.');
    }

    public function test_a_new_idempotency_key_after_a_replay_applies_normally(): void
    {
        $draft = BookingDraft::create([]);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-replay-3');
        (new SaveBookingDraftStep)($this->freshFromDatabase($draft->id), BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-replay-3');

        $afterReplay = $this->freshFromDatabase($draft->id);

        (new SaveBookingDraftStep)($afterReplay, BookingWizardStep::CEMETERY, [
            'cemetery_id' => $this->jakartaCemeteryWithoutPackages()->id,
        ], 'idem-replay-4');

        $afterNext = $this->freshFromDatabase($draft->id);

        $this->assertSame($afterReplay->version + 1, $afterNext->version);
        $this->assertSame($this->jakartaCemeteryWithoutPackages()->id, $afterNext->cemetery_id);
    }

    public function test_saving_against_a_stale_expected_version_throws_a_conflict(): void
    {
        $draft = BookingDraft::create([]);
        $staleVersion = $draft->version;

        // Simulate a concurrent save from another tab bumping the version first.
        (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-conflict-1');

        try {
            (new SaveBookingDraftStep)($this->freshFromDatabase($draft->id), BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::BOGOR], 'idem-conflict-2', expectedVersion: $staleVersion);
            $this->fail('Expected BookingDraftVersionConflictException.');
        } catch (BookingDraftVersionConflictException) {
            // Expected.
        }

        $afterConflict = $this->freshFromDatabase($draft->id);

        $this->assertSame('JAKARTA', $afterConflict->city_code, 'A rejected conflicting save must persist nothing.');
        $this->assertSame(2, $afterConflict->version, 'A rejected conflicting save must not bump the version.');
    }

    public function test_saving_with_no_expected_version_never_conflicts(): void
    {
        $draft = BookingDraft::create([]);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-conflict-3');

        // No $expectedVersion given — must not throw even though the
        // in-memory $draft is now stale relative to what a fresh read
        // would show, since this overload is opt-in.
        (new SaveBookingDraftStep)($this->freshFromDatabase($draft->id), BookingWizardStep::CEMETERY, [
            'cemetery_id' => $this->jakartaCemeteryWithoutPackages()->id,
        ], 'idem-conflict-4');

        $this->assertNotNull($this->freshFromDatabase($draft->id)->cemetery_id);
    }

    public function test_a_matching_expected_version_applies_normally(): void
    {
        // The positive half of the conflict contract — without this, a
        // version check that rejected EVERYTHING would still pass the two
        // tests above.
        $draft = BookingDraft::create([]);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-conflict-5', expectedVersion: 1);

        $saved = $this->freshFromDatabase($draft->id);

        $this->assertSame('JAKARTA', $saved->city_code);
        $this->assertSame(2, $saved->version);
    }
}
