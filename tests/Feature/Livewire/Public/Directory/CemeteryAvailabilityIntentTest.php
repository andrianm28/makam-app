<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Directory;

use App\Domain\CemeteryCapability\AvailabilityMode;
use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Livewire\Public\Directory\Support\CemeteryAvailabilityIntent;
use App\Support\Design\StatusIntent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AC5 ("present default availability as indicative/package-class and
 * visibly labeled `Perlu konfirmasi`"), AC6, AC7, and the negative
 * criterion "No guaranteed availability from indicative data" — Sprint 4
 * S4-T6.
 *
 * No `RefreshDatabase`: this is a pure mapping table, the same shape as
 * `tests/Unit/.../StatusIntentTest.php`. It lives under `tests/Feature/`
 * only to sit beside the other Directory tests.
 *
 * The load-bearing assertion in this file is
 * `test_no_indicative_mode_can_ever_produce_a_success_intent`. Everything
 * else describes the table; that one enforces the rule the table exists
 * for.
 */
final class CemeteryAvailabilityIntentTest extends TestCase
{
    public function test_default_availability_mode_renders_neutral_and_perlu_konfirmasi(): void
    {
        $resolved = CemeteryAvailabilityIntent::forCemetery(AvailabilityMode::DEFAULT);

        $this->assertSame(StatusIntent::INTENT_NEUTRAL, $resolved['intent']);
        $this->assertSame('Perlu konfirmasi', $resolved['label']);
    }

    /**
     * AC5's "indicative/package-class" names one shared presentation, not
     * two treatments — `AvailabilityMode`'s own doc block makes the same
     * reading.
     */
    public function test_package_class_mode_renders_the_same_neutral_perlu_konfirmasi_as_indicative(): void
    {
        $this->assertSame(
            CemeteryAvailabilityIntent::forCemetery(AvailabilityMode::INDICATIVE),
            CemeteryAvailabilityIntent::forCemetery(AvailabilityMode::PACKAGE_CLASS),
        );
    }

    public function test_specific_plot_mode_renders_info_not_success(): void
    {
        $resolved = CemeteryAvailabilityIntent::forCemetery(AvailabilityMode::SPECIFIC_PLOT);

        $this->assertSame(StatusIntent::INTENT_INFO, $resolved['intent']);
        $this->assertNotSame(StatusIntent::INTENT_SUCCESS, $resolved['intent']);
    }

    /**
     * An unrecognised mode must degrade to the most cautious honest state,
     * never throw and never guess upward. The failure mode of guessing
     * wrong here is telling a family a grave is available.
     */
    public function test_an_unrecognised_availability_mode_degrades_to_perlu_konfirmasi(): void
    {
        $resolved = CemeteryAvailabilityIntent::forCemetery('NOT_A_REAL_MODE');

        $this->assertSame(StatusIntent::INTENT_NEUTRAL, $resolved['intent']);
        $this->assertSame('Perlu konfirmasi', $resolved['label']);
    }

    /**
     * THE rule. Every package status, under every mode AC7 has not gated
     * behind an authoritative registry, must resolve to something other
     * than `success` — design-system.md §2.3, and requirements.md's
     * negative criteria.
     */
    #[DataProvider('indicativeModeAndPackageStatusProvider')]
    public function test_no_indicative_mode_can_ever_produce_a_success_intent(string $mode, string $status): void
    {
        $resolved = CemeteryAvailabilityIntent::forPackage($mode, $status);

        $this->assertNotSame(
            StatusIntent::INTENT_SUCCESS,
            $resolved['intent'],
            "Package status [{$status}] under availability mode [{$mode}] rendered as success, which is a false promise.",
        );
        $this->assertSame(StatusIntent::INTENT_NEUTRAL, $resolved['intent']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function indicativeModeAndPackageStatusProvider(): array
    {
        $cases = [];

        foreach ([AvailabilityMode::INDICATIVE, AvailabilityMode::PACKAGE_CLASS] as $mode) {
            foreach (CemeteryPackageAvailabilityStatus::KNOWN_STATUSES as $status) {
                $cases["{$mode} + {$status}"] = [$mode, $status];
            }
        }

        return $cases;
    }

    /**
     * `tasks.md`: "Unavailable (capacity/closed) → `neutral` — Not an
     * error." A full cemetery is a fact, not a fault; `danger` would be
     * wrong under every mode.
     */
    public function test_unavailable_is_neutral_and_never_danger_under_any_mode(): void
    {
        foreach (AvailabilityMode::KNOWN_MODES as $mode) {
            $resolved = CemeteryAvailabilityIntent::forPackage(
                $mode,
                CemeteryPackageAvailabilityStatus::UNAVAILABLE,
            );

            $this->assertSame(StatusIntent::INTENT_NEUTRAL, $resolved['intent']);
            $this->assertNotSame(StatusIntent::INTENT_DANGER, $resolved['intent']);
        }
    }

    /**
     * The one branch where `success` is permitted — and it is reachable
     * only through `SPECIFIC_PLOT`, which AC7 gates behind an authoritative
     * registry, freshness evidence, and a reservation contract. No seeded
     * cemetery activates it, so this asserts a branch that is deliberately
     * unreachable on today's data. That is the point: it documents the
     * exact and only precondition under which the platform is allowed to
     * make a confirmed-availability claim.
     */
    public function test_success_is_reachable_only_under_the_ac7_gated_authoritative_mode(): void
    {
        $resolved = CemeteryAvailabilityIntent::forPackage(
            AvailabilityMode::SPECIFIC_PLOT,
            CemeteryPackageAvailabilityStatus::AVAILABLE,
        );

        $this->assertSame(StatusIntent::INTENT_SUCCESS, $resolved['intent']);
    }

    /**
     * The intent VOCABULARY still has a single source of truth even though
     * this particular table lives outside `StatusIntent` — see
     * `CemeteryAvailabilityIntent`'s doc block for why it is a scoped local
     * mapper rather than a new `StatusIntent` family.
     */
    public function test_every_intent_this_resolver_can_emit_is_a_canonical_status_intent(): void
    {
        $emitted = [];

        foreach (AvailabilityMode::KNOWN_MODES as $mode) {
            $emitted[] = CemeteryAvailabilityIntent::forCemetery($mode)['intent'];

            foreach (CemeteryPackageAvailabilityStatus::KNOWN_STATUSES as $status) {
                $emitted[] = CemeteryAvailabilityIntent::forPackage($mode, $status)['intent'];
            }
        }

        foreach (array_unique($emitted) as $intent) {
            $this->assertContains($intent, StatusIntent::INTENTS);
        }
    }

    /**
     * Finding N-15: a missing icon component throws
     * `InvalidArgumentException` at render and has broken CI once. Every
     * icon this resolver can name must exist on disk.
     */
    public function test_every_icon_this_resolver_can_emit_exists_as_a_blade_component(): void
    {
        $icons = [];

        foreach (AvailabilityMode::KNOWN_MODES as $mode) {
            $icons[] = CemeteryAvailabilityIntent::forCemetery($mode)['icon'];

            foreach (CemeteryPackageAvailabilityStatus::KNOWN_STATUSES as $status) {
                $icons[] = CemeteryAvailabilityIntent::forPackage($mode, $status)['icon'];
            }
        }

        foreach (array_unique($icons) as $icon) {
            $this->assertFileExists(
                resource_path("views/components/icon/{$icon}.blade.php"),
                "Icon component [{$icon}] does not exist and would throw at render (finding N-15).",
            );
        }
    }

    public function test_only_the_ac7_gated_mode_is_treated_as_non_indicative(): void
    {
        $this->assertTrue(CemeteryAvailabilityIntent::isIndicative(AvailabilityMode::INDICATIVE));
        $this->assertTrue(CemeteryAvailabilityIntent::isIndicative(AvailabilityMode::PACKAGE_CLASS));
        $this->assertFalse(CemeteryAvailabilityIntent::isIndicative(AvailabilityMode::SPECIFIC_PLOT));
    }
}
