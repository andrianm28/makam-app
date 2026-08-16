<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\LaunchCityQuery;
use App\Domain\CemeteryDirectory\Models\LaunchCity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The `LaunchCity` table is the admin-extendable source for the five
 * canonical launch cities (`AGENTS.md` §Mandatory MVP UX) — the seam
 * `CemeteryPublicQuery::launchCities()` and `BookingDraft`'s city
 * validation read from.
 */
final class LaunchCityTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_cities_ordered_by_sort_order(): void
    {
        // `RefreshDatabase` runs every migration, including the
        // `seed_launch_cities` data migration, so the canonical rows are
        // already present — the fixture resets the table before asserting
        // on a custom set.
        LaunchCity::query()->delete();

        LaunchCity::query()->create(['code' => 'BEKASI', 'label' => 'Bekasi', 'sort_order' => 5]);
        LaunchCity::query()->create(['code' => 'JAKARTA', 'label' => 'Jakarta', 'sort_order' => 1]);
        LaunchCity::query()->create(['code' => 'BOGOR', 'label' => 'Bogor', 'sort_order' => 2, 'is_active' => false]);

        $cities = LaunchCityQuery::activeCities();

        $this->assertSame(['JAKARTA', 'BEKASI'], array_column($cities, 'code'));
    }

    public function test_is_known_reads_table_then_constants(): void
    {
        LaunchCity::query()->create(['code' => 'SUKABUMI', 'label' => 'Sukabumi']);

        $this->assertTrue(LaunchCityQuery::isKnown('SUKABUMI'));
        $this->assertTrue(LaunchCityQuery::isKnown('TANGERANG'));
        $this->assertFalse(LaunchCityQuery::isKnown('NONEXISTENT'));
    }

    /**
     * The five canonical rows ship with the table via the data migration —
     * CI and deploy never run `db:seed`, so the seeder class alone would
     * never materialize them in a real environment.
     */
    public function test_the_canonical_five_are_seeded_with_the_table(): void
    {
        $this->assertSame(
            LaunchCityCode::KNOWN_CODES,
            LaunchCity::query()->orderBy('sort_order')->pluck('code')->all(),
        );
    }

    /**
     * The constant fallback that keeps the public catalogue honest when the
     * table is emptied: `activeCities()` returns `[]` and
     * `CemeteryPublicQuery::launchCities()` serves the canonical five in
     * the same `list<array{code, label}>` shape.
     */
    public function test_launch_cities_fall_back_to_the_canonical_constants_when_the_table_is_empty(): void
    {
        LaunchCity::query()->delete();

        $this->assertSame([], LaunchCityQuery::activeCities());

        $cities = CemeteryPublicQuery::launchCities();

        $this->assertSame(LaunchCityCode::KNOWN_CODES, array_column($cities, 'code'));
        $this->assertSame(
            ['Jakarta', 'Bogor', 'Depok', 'Tangerang', 'Bekasi'],
            array_column($cities, 'label'),
        );
    }

    /**
     * The `activeCities()` degradation contract: a launch-city read that
     * cannot execute returns `[]` instead of throwing, so
     * `CemeteryPublicQuery::launchCities()` falls back to the canonical
     * five rather than 500ing a public render. On PostgreSQL the same
     * symptom appears as SQLSTATE 25P02 when a *caught* failure elsewhere
     * (e.g. the wizard degradation test's deliberately-dropped cemetery
     * tables) has already aborted the transaction; on SQLite a failed
     * statement does not abort the transaction, so the read target itself
     * is dropped to make the failure deterministic. Same reverse-dependency
     * drop list as `BookingWizardRouteTest::test_a_failed_cemetery_read_
     * degrades_honestly_instead_of_500ing` plus `launch_cities`.
     */
    public function test_active_cities_degrade_to_the_canonical_fallback_when_the_read_fails(): void
    {
        // On PostgreSQL a `DROP TABLE` of a parent is blocked by any
        // incoming FK constraint (2BP01) regardless of its ON DELETE
        // action, so the draft's own constraints go first — exactly as
        // `BookingWizardRouteTest`'s degradation test does.
        Schema::table('booking_drafts', function (Blueprint $table) {
            $table->dropForeign(['cemetery_id']);
            $table->dropForeign(['cemetery_package_id']);
        });

        // P4 visitation tables (16 Aug 2026) come first of all — the four
        // FK-reference `cemeteries`/`cemetery_visitation_policies`, and
        // PostgreSQL blocks `DROP TABLE` of a parent by ANY incoming FK
        // (2BP01), so `visitation_bookings` → `visitation_date_capacities`
        // → `visitation_blackout_dates` → `cemetery_visitation_policies`
        // must precede `cemeteries` below. P3 plot tables (16 Aug 2026)
        // come before their parents too — PostgreSQL blocks `DROP TABLE`
        // of a parent by ANY incoming FK (2BP01): `plot_reservations` →
        // `grave_plots` → `cemetery_blocks` must precede their parents
        // below.
        Schema::dropIfExists('visitation_bookings');
        Schema::dropIfExists('visitation_date_capacities');
        Schema::dropIfExists('visitation_blackout_dates');
        Schema::dropIfExists('cemetery_visitation_policies');
        // P3 plot tables (16 Aug 2026) come first — PostgreSQL blocks
        // `DROP TABLE` of a parent by ANY incoming FK (2BP01):
        // `plot_reservations` → `grave_plots` → `cemetery_blocks` must
        // precede their parents below.
        // P4 memorial tables (16 Aug 2026) come before the plot tables:
        // every memorial table FK-references `memorial_profiles`, which
        // FK-references `grave_records` (restrictOnDelete), so all seven
        // must precede `grave_records` below (2BP01).
        Schema::dropIfExists('abuse_reports');
        Schema::dropIfExists('moderation_cases');
        Schema::dropIfExists('memorial_qr_tokens');
        Schema::dropIfExists('memorial_media');
        Schema::dropIfExists('memorial_contents');
        Schema::dropIfExists('memorial_editors');
        Schema::dropIfExists('memorial_profiles');
        Schema::dropIfExists('plot_reservations');
        Schema::dropIfExists('grave_plots');
        Schema::dropIfExists('cemetery_blocks');
        Schema::dropIfExists('renewal_external_markings');
        Schema::dropIfExists('renewal_quotes');
        Schema::dropIfExists('renewals');
        Schema::dropIfExists('grave_records');
        Schema::dropIfExists('cemetery_packages');
        Schema::dropIfExists('cemetery_capability_profiles');
        Schema::dropIfExists('cemeteries');
        Schema::dropIfExists('launch_cities');

        $this->assertSame([], LaunchCityQuery::activeCities());

        $cities = CemeteryPublicQuery::launchCities();

        $this->assertSame(LaunchCityCode::KNOWN_CODES, array_column($cities, 'code'));
        $this->assertSame(
            ['Jakarta', 'Bogor', 'Depok', 'Tangerang', 'Bekasi'],
            array_column($cities, 'label'),
        );
    }
}
