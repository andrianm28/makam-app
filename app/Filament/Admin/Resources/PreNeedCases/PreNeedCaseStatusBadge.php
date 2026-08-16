<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PreNeedCases;

use App\Domain\PreNeed\PreNeedCaseStatus;

/**
 * Status badge presentation for the pre-need case status column, filter,
 * and infolist entries — the single place this Resource maps a
 * `PreNeedCaseStatus` onto a Filament color and an Indonesian label
 * (design-system.md §3.7's "resolve status -> intent in ONE place" rule,
 * the same shape `BookingOrderStatusBadge` gives the order lifecycle).
 *
 * The pre-need CASE family is not yet a `StatusIntent::MAP` family:
 * `StatusIntent`'s own doc block explicitly parks pre-need statuses for a
 * design-system.md §3.7 update rather than inventing an intent mapping in
 * code. Until that table lands, this badge is this Resource's one local
 * presentation mapping — deliberately isolated in one class so the later
 * §3.7 adoption is a single-file swap, not a scatter.
 */
final class PreNeedCaseStatusBadge
{
    public static function color(PreNeedCaseStatus $status): string
    {
        return match ($status) {
            PreNeedCaseStatus::INTEREST => 'gray',
            PreNeedCaseStatus::PROPOSAL => 'info',
            PreNeedCaseStatus::RESERVED => 'info',
            PreNeedCaseStatus::QUOTED => 'info',
            PreNeedCaseStatus::AGREED => 'warning',
            PreNeedCaseStatus::SCHEDULED => 'warning',
            PreNeedCaseStatus::SETTLED => 'success',
            PreNeedCaseStatus::ACTIVATED => 'success',
        };
    }

    public static function label(PreNeedCaseStatus $status): string
    {
        return match ($status) {
            PreNeedCaseStatus::INTEREST => 'Minat',
            PreNeedCaseStatus::PROPOSAL => 'Proposal',
            PreNeedCaseStatus::RESERVED => 'Direservasi',
            PreNeedCaseStatus::QUOTED => 'Dikutip',
            PreNeedCaseStatus::AGREED => 'Disepakati',
            PreNeedCaseStatus::SCHEDULED => 'Terjadwal',
            PreNeedCaseStatus::SETTLED => 'Diselesaikan',
            PreNeedCaseStatus::ACTIVATED => 'Diaktifkan',
        };
    }
}
