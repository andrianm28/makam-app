<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Admin;

use App\Filament\Admin\Resources\CemeteryVisitationPolicies\Tables\CemeteryVisitationPoliciesTable;
use Tests\TestCase;

/**
 * `CemeteryVisitationPoliciesTable::hoursSummary()` — Batch 2F
 * (COORD-05/STAT-03): the uniform-hours branch read `$uniformPair[0]`/`[1]`
 * instead of the associative `['open']`/`['close']` keys `$uniformPair` is
 * actually built with, so every cemetery with the same hours on all seven
 * days rendered as "Setiap hari –" (both times silently blank). No prior
 * test asserted the "Setiap hari" string — confirmed by grep before this
 * fix landed.
 */
final class CemeteryVisitationPoliciesTableTest extends TestCase
{
    public function test_uniform_hours_across_all_seven_days_render_as_a_single_setiap_hari_line(): void
    {
        $hours = [];

        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $key) {
            $hours[$key] = ['open' => '08:00', 'close' => '17:00'];
        }

        $this->assertSame(
            'Setiap hari 08.00–17.00',
            CemeteryVisitationPoliciesTable::hoursSummary($hours),
        );
    }

    public function test_non_uniform_hours_render_a_day_by_day_line_per_weekday(): void
    {
        $hours = [
            'mon' => null,
            'tue' => ['open' => '08:00', 'close' => '17:00'],
            'wed' => ['open' => '08:00', 'close' => '17:00'],
            'thu' => ['open' => '08:00', 'close' => '17:00'],
            'fri' => ['open' => '08:00', 'close' => '17:00'],
            'sat' => ['open' => '09:00', 'close' => '15:00'],
            'sun' => null,
        ];

        $summary = CemeteryVisitationPoliciesTable::hoursSummary($hours);

        $this->assertStringContainsString('Senin: tutup', $summary);
        $this->assertStringContainsString('Selasa: 08.00–17.00', $summary);
        $this->assertStringContainsString('Sabtu: 09.00–15.00', $summary);
        $this->assertStringNotContainsString('Setiap hari', $summary);
    }

    public function test_a_non_array_state_renders_the_not_yet_set_placeholder(): void
    {
        $this->assertSame('Belum diatur', CemeteryVisitationPoliciesTable::hoursSummary(null));
    }
}
