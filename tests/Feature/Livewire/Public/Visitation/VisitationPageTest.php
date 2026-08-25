<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Visitation;

use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Visitation\Actions\RequestVisitation;
use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Domain\Visitation\Models\VisitationBlackoutDate;
use App\Domain\Visitation\Models\VisitationBooking;
use App\Domain\Visitation\VisitationAuditActions;
use App\Domain\Visitation\VisitationPublicQuery;
use App\Livewire\Public\Visitation\VisitationPage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Task 2 (Lane 2 — Visitation): the public `/kunjungan` page
 * (`App\Livewire\Public\Visitation\VisitationPage`), mode-aware per the
 * kiro visitation-booking design ("The visual difference must be
 * unmistakable"): INFORMATION_ONLY renders an hours banner and ZERO
 * bookable controls; BOOKABLE renders the request form (bookable dates,
 * blackout dates visibly disabled WITH their reason, capacity-aware,
 * `inputmode=numeric` count, `autocomplete=tel` contact), submit produces
 * the AC5 confirmation card (reference, instructions, change/cancel note,
 * fallback contact), and a repeated submission (AC7) returns the incumbent
 * confirmation — one row, never two. Unknown and unpublished slugs share
 * one indistinguishable 404.
 *
 * Dates are frozen at 2026-08-16 (a Sunday) so the 14-day booking window
 * is deterministic: 2026-08-17 (Mon) and 2026-08-19 (Wed) are the worked
 * examples, 2026-08-19 doubled as the blackout date.
 */
final class VisitationPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16 09:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_index_route_renders_a_cemetery_picker(): void
    {
        $published = $this->publishedCemetery();

        $this->get(route('kunjungan.index'))
            ->assertOk()
            ->assertSee($published->name)
            ->assertSee(route('kunjungan.cemetery', ['cemeterySlug' => $published->slug]));
    }

    public function test_an_information_only_cemetery_renders_the_hours_banner_and_no_form(): void
    {
        $cemetery = $this->publishedCemetery();
        $this->policy($cemetery);
        $this->setVisitationMode($cemetery, 'INFORMATION_ONLY');

        $this->get(route('kunjungan.cemetery', ['cemeterySlug' => $cemetery->slug]))
            ->assertOk()
            ->assertSee('belum dapat dipesan secara online', false)
            ->assertSee('Senin: 08.00–17.00', false)
            ->assertDontSee('Jumlah pengunjung')
            ->assertDontSee('Kirim Permintaan Kunjungan')
            ->assertDontSee('inputmode="numeric"');
    }

    public function test_an_information_only_cemetery_without_a_policy_renders_the_generic_hours_line(): void
    {
        $cemetery = $this->publishedCemetery();
        $this->setVisitationMode($cemetery, 'INFORMATION_ONLY');

        $this->get(route('kunjungan.cemetery', ['cemeterySlug' => $cemetery->slug]))
            ->assertOk()
            ->assertSee('Jam kunjungan berlaku', false)
            ->assertDontSee('Jumlah pengunjung');
    }

    public function test_a_none_mode_cemetery_renders_no_bookable_controls(): void
    {
        $cemetery = $this->publishedCemetery();
        $this->policy($cemetery);
        $this->setVisitationMode($cemetery, 'NONE');

        $this->get(route('kunjungan.cemetery', ['cemeterySlug' => $cemetery->slug]))
            ->assertOk()
            ->assertDontSee('Jumlah pengunjung')
            ->assertDontSee('Kirim Permintaan Kunjungan');
    }

    public function test_a_bookable_cemetery_renders_the_form_with_blackout_dates_disabled_and_their_reason(): void
    {
        $cemetery = $this->publishedCemetery();
        $policy = $this->policy($cemetery);
        $this->setVisitationMode($cemetery, 'BOOKABLE');

        VisitationBlackoutDate::query()->create([
            'policy_id' => $policy->id,
            'date' => '2026-08-19',
            'reason' => 'Upacara peringatan hari jadi',
        ]);

        $response = $this->get(route('kunjungan.cemetery', ['cemeterySlug' => $cemetery->slug]));

        $response->assertOk()
            ->assertSee('Jumlah pengunjung')
            ->assertSee('inputmode="numeric"', false)
            ->assertSee('autocomplete="tel"', false)
            ->assertSee('Kursi roda')
            ->assertSee('Pemandu')
            ->assertSee('Toilet')
            ->assertSee('Parkir')
            ->assertSee('Upacara peringatan hari jadi')
            ->assertSee('Senin, 17 Agustus 2026', false);

        $body = (string) $response->getContent();

        // The blackout date is a REAL, visible, disabled option carrying its
        // reason — not silently absent (kiro design tasks.md: "blackout dates
        // visibly disabled with a reason, not silently missing").
        $this->assertStringContainsString('value="2026-08-19"', $body);
        $this->assertStringContainsString('disabled', $body);
        $this->assertStringContainsString('Upacara peringatan hari jadi', $body);
    }

    public function test_a_bookable_cemetery_with_no_policy_renders_an_explained_empty_state(): void
    {
        $cemetery = $this->publishedCemetery();
        $this->setVisitationMode($cemetery, 'BOOKABLE');

        $this->get(route('kunjungan.cemetery', ['cemeterySlug' => $cemetery->slug]))
            ->assertOk()
            ->assertSee('Tanggal belum tersedia', false)
            ->assertDontSee('Kirim Permintaan Kunjungan');
    }

    public function test_submitting_the_form_creates_a_booking_and_shows_the_confirmation_card(): void
    {
        $cemetery = $this->publishedCemetery();
        $this->policy($cemetery);
        $this->setVisitationMode($cemetery, 'BOOKABLE');

        $component = Livewire::test(VisitationPage::class, ['cemeterySlug' => $cemetery->slug])
            ->set('visitDate', '2026-08-17')
            ->set('visitorCount', '2')
            ->set('contactPhone', '0812-3456-7890')
            ->set('contactEmail', 'family@example.com')
            ->set('accessibilityNeeds', 'Membutuhkan kursi roda')
            ->set('facilityRequests', ['kursi_roda', 'pemandu'])
            ->call('requestVisit')
            ->assertHasNoErrors();

        $reference = (string) $component->get('confirmationReference');

        $this->assertMatchesRegularExpression('/^VST-\d{4}-[A-Z0-9]{8}$/', $reference);

        $component->assertSee($reference)
            ->assertSee(route('bantuan.index'))
            ->assertSee('Menunggu konfirmasi', false);

        $booking = VisitationBooking::query()->where('reference', $reference)->sole();

        $this->assertSame('2026-08-17', $booking->visit_date->toDateString());
        $this->assertSame(2, $booking->visitor_count);
        $this->assertSame('0812-3456-7890', $booking->contact_phone);
        $this->assertSame('Membutuhkan kursi roda', $booking->accessibility_needs);
        $this->assertSame(['kursi_roda', 'pemandu'], $booking->facility_requests);
        $this->assertSame('requested', $booking->status);

        $this->assertDatabaseHas('audit_events', [
            'action' => VisitationAuditActions::VISITATION_REQUESTED,
            'subject_type' => 'visitation_booking',
            'subject_id' => (string) $booking->id,
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'visit.booking_requested.v1',
            'aggregate_type' => 'visitation_booking',
            'aggregate_id' => (string) $booking->id,
        ]);
    }

    public function test_a_repeated_submission_returns_the_incumbent_confirmation_and_never_a_second_row(): void
    {
        $cemetery = $this->publishedCemetery();
        $this->policy($cemetery);
        $this->setVisitationMode($cemetery, 'BOOKABLE');

        $first = Livewire::test(VisitationPage::class, ['cemeterySlug' => $cemetery->slug])
            ->set('visitDate', '2026-08-17')
            ->set('visitorCount', '2')
            ->set('contactPhone', '0812-3456-7890')
            ->call('requestVisit');

        $firstReference = (string) $first->get('confirmationReference');

        $second = Livewire::test(VisitationPage::class, ['cemeterySlug' => $cemetery->slug])
            ->set('visitDate', '2026-08-17')
            ->set('visitorCount', '2')
            ->set('contactPhone', '0812-3456-7890')
            ->call('requestVisit');

        $this->assertSame($firstReference, (string) $second->get('confirmationReference'));
        $this->assertSame(1, VisitationBooking::query()->count());
        $second->assertSee($firstReference);
    }

    /**
     * The whole-branch review fix: the capacity pre-check and the
     * authoritative action are two separate reads, and the date can fill
     * between them. The domain refusal must surface as the SAME inline
     * field error the pre-check would have shown — never a crash on the
     * Livewire error surface. The stale `bookableDates` map simulates the
     * TOCTOU window: the courtesy check passes, the action refuses.
     */
    public function test_a_date_that_fills_between_precheck_and_action_renders_an_inline_error_never_a_crash(): void
    {
        $cemetery = $this->publishedCemetery();
        $this->policy($cemetery);
        $this->setVisitationMode($cemetery, 'BOOKABLE');

        app(RequestVisitation::class)(
            $cemetery,
            '2026-08-17',
            10,
            '0812-0000-0000',
            null,
            null,
            [],
            'fixture-full-'.Str::random(8),
            'actor:fixture',
        );

        $this->app->instance(VisitationPublicQuery::class, new class
        {
            public function policyFor(Cemetery $cemetery): ?CemeteryVisitationPolicy
            {
                return CemeteryVisitationPolicy::query()->where('cemetery_id', $cemetery->getKey())->first();
            }

            public function bookableDates(Cemetery $cemetery, CarbonImmutable $from, CarbonImmutable $to): array
            {
                return ['2026-08-17' => ['capacity' => 10, 'capacity_left' => 10]];
            }
        });

        Livewire::test(VisitationPage::class, ['cemeterySlug' => $cemetery->slug])
            ->set('visitDate', '2026-08-17')
            ->set('visitorCount', '2')
            ->set('contactPhone', '0812-3456-7890')
            ->call('requestVisit')
            ->assertOk()
            ->assertHasErrors(['visitorCount' => 'Jumlah pengunjung melebihi sisa kuota pada tanggal tersebut.'])
            ->assertSet('confirmationReference', null);

        $this->assertSame(1, VisitationBooking::query()->count(), 'The refused submission must not create a second booking.');
    }

    public function test_an_unknown_slug_returns_404(): void
    {
        $this->get(route('kunjungan.cemetery', ['cemeterySlug' => 'tidak-ada-lokasi-ini']))
            ->assertNotFound();
    }

    public function test_an_unpublished_cemetery_returns_the_same_404_as_an_unknown_slug(): void
    {
        $draft = $this->cemetery(CemeteryPublicationStatus::DRAFT);

        $unknownStatus = $this->get(route('kunjungan.cemetery', ['cemeterySlug' => 'tidak-ada-lokasi-ini']))->status();
        $draftStatus = $this->get(route('kunjungan.cemetery', ['cemeterySlug' => $draft->slug]))->status();

        $this->assertSame(404, $unknownStatus);
        $this->assertSame($unknownStatus, $draftStatus);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function publishedCemetery(): Cemetery
    {
        return $this->cemetery(CemeteryPublicationStatus::PUBLISHED);
    }

    private function cemetery(string $publicationStatus): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => $publicationStatus,
            'name' => 'TPU Uji Kunjungan '.Str::lower(Str::random(6)),
            'slug' => 'tpu-uji-kunjungan-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function policy(Cemetery $cemetery, array $overrides = []): CemeteryVisitationPolicy
    {
        return CemeteryVisitationPolicy::query()->create(array_merge([
            'cemetery_id' => $cemetery->id,
            'operating_hours' => [
                'mon' => ['open' => '08:00', 'close' => '17:00'],
                'tue' => ['open' => '08:00', 'close' => '17:00'],
                'wed' => ['open' => '08:00', 'close' => '17:00'],
                'thu' => ['open' => '08:00', 'close' => '17:00'],
                'fri' => ['open' => '08:00', 'close' => '17:00'],
                'sat' => ['open' => '08:00', 'close' => '17:00'],
                'sun' => ['open' => '08:00', 'close' => '17:00'],
            ],
            'daily_capacity' => 10,
        ], $overrides));
    }

    private function setVisitationMode(Cemetery $cemetery, string $mode): void
    {
        CemeteryCapabilityProfile::query()->create(array_merge(
            CemeteryCapabilityProfile::safeDefaults(),
            [
                'cemetery_id' => $cemetery->id,
                'version_number' => 1,
                'visitation_mode' => $mode,
                'source' => 'test:fixture',
                'owner' => 'Test fixture',
                'evidence' => 'Test fixture capability activation.',
                'rollback_plan' => 'Revert the test row.',
                'effective_at' => CarbonImmutable::parse('2026-08-01 00:00:00'),
            ],
        ));
    }
}
