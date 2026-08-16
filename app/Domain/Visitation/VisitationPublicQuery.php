<?php

declare(strict_types=1);

namespace App\Domain\Visitation;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Domain\Visitation\Models\VisitationBlackoutDate;
use App\Domain\Visitation\Models\VisitationDateCapacity;
use Carbon\CarbonImmutable;

/**
 * The public visitation reads — Task 1 of
 * `docs/superpowers/plans/2026-08-16-p4-memorial-qr-visitation.md`
 * (Lane 1 — Visitation). The public `/kunjungan` page's slot source
 * (Lane 2), mirroring `CemeteryPublicQuery`'s "every public read starts
 * here" role for the directory.
 *
 * The bookable-mode check is deliberately NOT here: the mode authority
 * is `PublicCapabilityProjection::forCemetery($cemetery)->visitationMode`
 * (the design spec §4.1 — server-side, never a front-end flag), and the
 * public page asks that projection first and this class second.
 *
 * ---------------------------------------------------------------------------
 * `bookableDates()` — one definition of "a date the public can pick"
 * ---------------------------------------------------------------------------
 * A date is bookable when (a) its weekday has operating hours in the
 * policy template, and (b) it is not on the policy's blackout list.
 * Blackout dates are EXCLUDED (not returned marked disabled) — they
 * carry a reason the family must see before attempting the date, which
 * is the Lane 2 page's disabled-date tooltip job; this query returns
 * only the pickable set, each with its remaining capacity from the
 * ledger. Dates with no ledger row have `booked_count` 0 — a full
 * (`capacity_left` 0) date stays in the result so the page can render
 * it honestly disabled rather than silently absent.
 *
 * Returns an ordered map keyed by `Y-m-d`, ascending.
 *
 * @return array<string, array{capacity: int, capacity_left: int}>
 */
final class VisitationPublicQuery
{
    public function policyFor(Cemetery $cemetery): ?CemeteryVisitationPolicy
    {
        return CemeteryVisitationPolicy::query()->where('cemetery_id', $cemetery->getKey())->first();
    }

    /**
     * @return array<string, array{capacity: int, capacity_left: int}>
     */
    public function bookableDates(Cemetery $cemetery, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $policy = $this->policyFor($cemetery);

        if (! $policy instanceof CemeteryVisitationPolicy) {
            return [];
        }

        $blackoutKeys = VisitationBlackoutDate::query()
            ->where('policy_id', $policy->getKey())
            ->whereBetween('date', [$from, $to])
            ->pluck('date')
            // `date`-cast columns read back as raw strings on SQLite
            // (`'Y-m-d H:i:s'`) and as `'Y-m-d'` on PostgreSQL; the
            // blackout-set keys are normalized to `Y-m-d` here so the
            // per-day `in_array()` check below is engine-agnostic.
            ->map(fn (mixed $date): string => CarbonImmutable::parse((string) $date)->toDateString())
            ->all();

        $ledger = VisitationDateCapacity::query()
            ->where('policy_id', $policy->getKey())
            ->whereBetween('date', [$from, $to])
            ->get()
            ->keyBy(fn (VisitationDateCapacity $row): string => $row->date->toDateString());

        $bookable = [];

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $key = $date->toDateString();

            if (! $policy->isVisitingDay($date) || in_array($key, $blackoutKeys, true)) {
                continue;
            }

            $booked = isset($ledger[$key]) ? $ledger[$key]->booked_count : 0;

            $bookable[$key] = [
                'capacity' => $policy->daily_capacity,
                'capacity_left' => max(0, $policy->daily_capacity - $booked),
            ];
        }

        return $bookable;
    }
}
