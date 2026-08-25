<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Visitation;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Domain\Visitation\Models\VisitationBlackoutDate;
use App\Domain\Visitation\Models\VisitationDateCapacity;
use App\Domain\Visitation\VisitationPublicQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Task 1 (Lane 1 — Visitation): the policy model's guards and
 * semantics — operating-hours shape (weekday allowlist + HH:MM),
 * `daily_capacity >= 1`, the visiting-day/blackout helpers, and the
 * public `bookableDates` projection built on them.
 *
 * Dates are fixed calendar dates with known weekdays (2026-08-16 is a
 * Sunday): 2026-08-17 Mon, 2026-08-19 Wed, 2026-08-20 Thu.
 */
final class VisitationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_an_unknown_weekday_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown weekday key [funday]');

        CemeteryVisitationPolicy::query()->create([
            'cemetery_id' => $this->cemetery()->getKey(),
            'operating_hours' => [
                'mon' => ['open' => '08:00', 'close' => '17:00'],
                'funday' => ['open' => '08:00', 'close' => '17:00'],
            ],
            'daily_capacity' => 10,
        ]);
    }

    public function test_rejects_a_malformed_open_time(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('operating_hours[mon].open must be HH:MM');

        CemeteryVisitationPolicy::query()->create($this->policyAttributes([
            'operating_hours' => ['mon' => ['open' => '25:00', 'close' => '17:00']],
        ]));
    }

    public function test_rejects_a_malformed_close_time(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('operating_hours[mon].close must be HH:MM');

        CemeteryVisitationPolicy::query()->create($this->policyAttributes([
            'operating_hours' => ['mon' => ['open' => '08:00', 'close' => '8:00']],
        ]));
    }

    public function test_rejects_a_non_null_weekday_entry_without_open_and_close(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CemeteryVisitationPolicy::query()->create($this->policyAttributes([
            'operating_hours' => ['mon' => ['open' => '08:00']],
        ]));
    }

    public function test_rejects_daily_capacity_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('daily capacity must be at least 1');

        CemeteryVisitationPolicy::query()->create($this->policyAttributes(['daily_capacity' => 0]));
    }

    public function test_accepts_a_closed_weekday_key_as_null(): void
    {
        $policy = CemeteryVisitationPolicy::query()->create($this->policyAttributes([
            'operating_hours' => ['mon' => null],
        ]));

        $this->assertFalse($policy->isVisitingDay(CarbonImmutable::parse('2026-08-17')));
    }

    public function test_is_visiting_day_and_time_helpers_follow_the_weekday_template(): void
    {
        $policy = CemeteryVisitationPolicy::query()->create($this->policyAttributes([
            'operating_hours' => [
                'mon' => null,
                'tue' => ['open' => '08:00', 'close' => '17:00'],
                'wed' => ['open' => '08:00', 'close' => '17:00'],
                'thu' => ['open' => '08:00', 'close' => '17:00'],
                'fri' => ['open' => '08:00', 'close' => '17:00'],
                'sat' => ['open' => '08:00', 'close' => '17:00'],
                'sun' => ['open' => '08:00', 'close' => '17:00'],
            ],
        ]));

        $monday = CarbonImmutable::parse('2026-08-17');
        $wednesday = CarbonImmutable::parse('2026-08-19');

        $this->assertTrue($policy->isVisitingDay($wednesday));
        $this->assertSame('2026-08-19 08:00:00', $policy->openTimeFor($wednesday)?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-19 17:00:00', $policy->closeTimeFor($wednesday)?->format('Y-m-d H:i:s'));
        $this->assertFalse($policy->isVisitingDay($monday));
        $this->assertNull($policy->openTimeFor($monday));
        $this->assertNull($policy->closeTimeFor($monday));
    }

    public function test_blackout_semantics_are_policy_scoped_and_carry_the_reason(): void
    {
        $policy = CemeteryVisitationPolicy::query()->create($this->policyAttributes());
        $other = CemeteryVisitationPolicy::query()->create($this->policyAttributes([
            'cemetery_id' => $this->cemetery()->getKey(),
        ]));

        VisitationBlackoutDate::query()->create([
            'policy_id' => $policy->getKey(),
            'date' => '2026-08-19',
            'reason' => 'Upacara peringatan',
        ]);

        $date = CarbonImmutable::parse('2026-08-19');

        $this->assertTrue($policy->isBlackout($date));
        $this->assertSame('Upacara peringatan', $policy->blackoutReasonFor($date));
        $this->assertFalse($policy->isBlackout(CarbonImmutable::parse('2026-08-20')));
        $this->assertNull($policy->blackoutReasonFor(CarbonImmutable::parse('2026-08-20')));
        $this->assertFalse($other->isBlackout($date));
    }

    public function test_blackout_reason_is_required_non_blank(): void
    {
        $policy = CemeteryVisitationPolicy::query()->create($this->policyAttributes());

        try {
            VisitationBlackoutDate::query()->create([
                'policy_id' => $policy->getKey(),
                'date' => '2026-08-19',
                'reason' => ' ',
            ]);
            $this->fail('A blank blackout reason must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('non-blank reason', $exception->getMessage());
        }
    }

    public function test_bookable_dates_excludes_blackouts_and_reports_capacity_left_from_the_ledger(): void
    {
        $cemetery = $this->cemetery();
        $policy = CemeteryVisitationPolicy::query()->create($this->policyAttributes([
            'cemetery_id' => $cemetery->getKey(),
            'daily_capacity' => 5,
        ]));

        VisitationBlackoutDate::query()->create([
            'policy_id' => $policy->getKey(),
            'date' => '2026-08-19',
            'reason' => 'Upacara peringatan',
        ]);

        VisitationDateCapacity::query()->create([
            'policy_id' => $policy->getKey(),
            'date' => '2026-08-20',
            'booked_count' => 2,
        ]);

        $query = new VisitationPublicQuery;
        $bookable = $query->bookableDates($cemetery, CarbonImmutable::parse('2026-08-17'), CarbonImmutable::parse('2026-08-22'));

        $this->assertArrayHasKey('2026-08-17', $bookable);
        $this->assertArrayHasKey('2026-08-18', $bookable);
        $this->assertArrayNotHasKey('2026-08-19', $bookable);
        $this->assertArrayHasKey('2026-08-20', $bookable);
        $this->assertSame(['capacity' => 5, 'capacity_left' => 3], $bookable['2026-08-20']);
        $this->assertSame(['capacity' => 5, 'capacity_left' => 5], $bookable['2026-08-17']);
    }

    public function test_bookable_dates_is_empty_for_a_cemetery_without_a_policy(): void
    {
        $this->assertSame([], (new VisitationPublicQuery)->bookableDates(
            $this->cemetery(),
            CarbonImmutable::parse('2026-08-17'),
            CarbonImmutable::parse('2026-08-22'),
        ));
    }

    private function cemetery(): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function policyAttributes(array $overrides = []): array
    {
        return array_merge([
            'cemetery_id' => $this->cemetery()->getKey(),
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
        ], $overrides);
    }
}
