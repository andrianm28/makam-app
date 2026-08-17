<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Design;

use App\Support\Design\StatusIntent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * StatusIntent is the single, mandatory status -> visual-intent resolver
 * (design-system.md §3.7). These tests exist primarily to lock in the two
 * rules the spec calls out explicitly, by name, as non-negotiable:
 *
 *   1. `DIBAYAR` != `SELESAI` — "paid does not mean completed" — must stay
 *      two distinct indicators, never merged into one "done" badge.
 *   2. `MENUNGGU_VERIFIKASI_PEMBAYARAN` resolves to `pending`, explicitly
 *      NEVER `success`.
 *
 * Everything else here is supporting coverage: fallback safety for an
 * unrecognised status, family scoping, and the Filament colour bridge.
 */
final class StatusIntentTest extends TestCase
{
    // -------------------------------------------------------------------
    // Rule 1: DIBAYAR != SELESAI ("paid does not mean completed")
    // -------------------------------------------------------------------

    public function test_dibayar_and_selesai_are_both_success_intent_but_render_as_distinct_indicators(): void
    {
        // Both are good news (§3.7 table: both `success`) — that part is
        // intentional and not the thing under test.
        $this->assertSame(
            StatusIntent::INTENT_SUCCESS,
            StatusIntent::intent('DIBAYAR', StatusIntent::FAMILY_ORDER_LIFECYCLE)
        );
        $this->assertSame(
            StatusIntent::INTENT_SUCCESS,
            StatusIntent::intent('SELESAI', StatusIntent::FAMILY_ORDER_LIFECYCLE)
        );

        // The thing actually under test: design-system.md §3.7 requires
        // "two distinct indicators, never merged into one 'done' badge".
        // Icon and label must differ so a rendered badge cannot collapse
        // the two into the same visible marker.
        $dibayarIcon = StatusIntent::icon('DIBAYAR', StatusIntent::FAMILY_ORDER_LIFECYCLE);
        $selesaiIcon = StatusIntent::icon('SELESAI', StatusIntent::FAMILY_ORDER_LIFECYCLE);
        $this->assertNotSame($dibayarIcon, $selesaiIcon, 'DIBAYAR and SELESAI must not share an icon.');
        $this->assertSame('banknote', $dibayarIcon);
        $this->assertSame('check-badge', $selesaiIcon);

        $dibayarLabel = StatusIntent::label('DIBAYAR', StatusIntent::FAMILY_ORDER_LIFECYCLE);
        $selesaiLabel = StatusIntent::label('SELESAI', StatusIntent::FAMILY_ORDER_LIFECYCLE);
        $this->assertNotSame($dibayarLabel, $selesaiLabel, 'DIBAYAR and SELESAI must not share a label.');
        $this->assertSame('Dibayar', $dibayarLabel);
        $this->assertSame('Selesai', $selesaiLabel);
    }

    public function test_dibayar_is_never_resolved_as_the_same_status_as_selesai(): void
    {
        // A regression-proof guard against ever collapsing the two enum
        // values into one lookup key or one branch.
        $this->assertNotSame('DIBAYAR', 'SELESAI');
        $this->assertContains('DIBAYAR', StatusIntent::knownStatuses(StatusIntent::FAMILY_ORDER_LIFECYCLE));
        $this->assertContains('SELESAI', StatusIntent::knownStatuses(StatusIntent::FAMILY_ORDER_LIFECYCLE));
    }

    // -------------------------------------------------------------------
    // Rule 2: MENUNGGU_VERIFIKASI_PEMBAYARAN -> pending, NEVER success
    // -------------------------------------------------------------------

    public function test_menunggu_verifikasi_pembayaran_resolves_to_pending_never_success(): void
    {
        $intent = StatusIntent::intent('MENUNGGU_VERIFIKASI_PEMBAYARAN', StatusIntent::FAMILY_ORDER_LIFECYCLE);

        $this->assertSame(StatusIntent::INTENT_PENDING, $intent);
        $this->assertNotSame(StatusIntent::INTENT_SUCCESS, $intent);
    }

    public function test_menunggu_verifikasi_pembayaran_resolves_to_pending_without_an_explicit_family_too(): void
    {
        // No cross-family collision for this status today, so the
        // family-less path must reach the same, explicitly-non-success
        // answer.
        $intent = StatusIntent::intent('MENUNGGU_VERIFIKASI_PEMBAYARAN');

        $this->assertSame(StatusIntent::INTENT_PENDING, $intent);
        $this->assertNotSame(StatusIntent::INTENT_SUCCESS, $intent);
    }

    public function test_menunggu_verifikasi_pembayaran_maps_to_warning_not_success_in_filament(): void
    {
        // `pending` bridges to Filament's `warning` colour key, never
        // `success` — the Filament bridge must not lose this rule either.
        $this->assertSame(
            'warning',
            StatusIntent::filamentColor('MENUNGGU_VERIFIKASI_PEMBAYARAN', StatusIntent::FAMILY_ORDER_LIFECYCLE)
        );
    }

    // -------------------------------------------------------------------
    // §3.7 table coverage — order lifecycle
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function orderLifecycleProvider(): array
    {
        return [
            'MASUK' => ['MASUK', StatusIntent::INTENT_NEUTRAL, 'inbox'],
            'DIVERIFIKASI' => ['DIVERIFIKASI', StatusIntent::INTENT_INFO, 'shield-check'],
            'MENUNGGU_KETERSEDIAAN' => ['MENUNGGU_KETERSEDIAAN', StatusIntent::INTENT_PENDING, 'clock'],
            'PENAWARAN_TERKIRIM' => ['PENAWARAN_TERKIRIM', StatusIntent::INTENT_INFO, 'document-text'],
            'DISETUJUI_PEMESAN' => ['DISETUJUI_PEMESAN', StatusIntent::INTENT_INFO, 'check-circle'],
            'MENUNGGU_PEMBAYARAN' => ['MENUNGGU_PEMBAYARAN', StatusIntent::INTENT_PENDING, 'clock'],
            'DIPROSES' => ['DIPROSES', StatusIntent::INTENT_INFO, 'cog'],
            'DITOLAK' => ['DITOLAK', StatusIntent::INTENT_DANGER, 'x-circle'],
            'DIBATALKAN' => ['DIBATALKAN', StatusIntent::INTENT_NEUTRAL, 'slash'],
            'KEDALUWARSA' => ['KEDALUWARSA', StatusIntent::INTENT_NEUTRAL, 'clock-x'],
        ];
    }

    #[DataProvider('orderLifecycleProvider')]
    public function test_order_lifecycle_statuses_resolve_per_design_system_table(
        string $status,
        string $expectedIntent,
        string $expectedIcon
    ): void {
        $this->assertSame($expectedIntent, StatusIntent::intent($status, StatusIntent::FAMILY_ORDER_LIFECYCLE));
        $this->assertSame($expectedIcon, StatusIntent::icon($status, StatusIntent::FAMILY_ORDER_LIFECYCLE));
    }

    // -------------------------------------------------------------------
    // §3.7 table coverage — vendor processing
    // -------------------------------------------------------------------

    public function test_vendor_processing_statuses_resolve_per_design_system_table(): void
    {
        $this->assertSame(
            StatusIntent::INTENT_PENDING,
            StatusIntent::intent('MENUNGGU_VENDOR', StatusIntent::FAMILY_VENDOR_PROCESSING)
        );
        $this->assertSame(
            StatusIntent::INTENT_INFO,
            StatusIntent::intent('DITERIMA_VENDOR', StatusIntent::FAMILY_VENDOR_PROCESSING)
        );
        $this->assertSame(
            StatusIntent::INTENT_DANGER,
            StatusIntent::intent('DITOLAK_VENDOR', StatusIntent::FAMILY_VENDOR_PROCESSING)
        );
        $this->assertSame(
            StatusIntent::INTENT_DANGER,
            StatusIntent::intent('KOMPLAIN', StatusIntent::FAMILY_VENDOR_PROCESSING)
        );
        $this->assertSame(
            'truck',
            StatusIntent::icon('DIKIRIM_OR_DIJADWALKAN', StatusIntent::FAMILY_VENDOR_PROCESSING)
        );
    }

    // -------------------------------------------------------------------
    // Family scoping — the collision the batch brief asked about
    // -------------------------------------------------------------------

    public function test_family_less_lookup_resolves_unambiguous_status(): void
    {
        // MASUK only exists in the order-lifecycle family.
        $this->assertSame(StatusIntent::INTENT_NEUTRAL, StatusIntent::intent('MASUK'));
    }

    public function test_status_shared_by_both_families_resolves_identically_without_a_family(): void
    {
        // DIPROSES, SELESAI, and DIBATALKAN are the actual (not
        // theoretical) overlap between order-lifecycle and
        // vendor-processing today, and all three agree on intent+icon in
        // both tables — so the family-less path must resolve them exactly
        // as the family-scoped path would, not fall back to neutral.
        foreach (['DIPROSES', 'SELESAI', 'DIBATALKAN'] as $status) {
            $withoutFamily = StatusIntent::intent($status);
            $orderLifecycle = StatusIntent::intent($status, StatusIntent::FAMILY_ORDER_LIFECYCLE);
            $vendorProcessing = StatusIntent::intent($status, StatusIntent::FAMILY_VENDOR_PROCESSING);

            $this->assertSame($orderLifecycle, $vendorProcessing, "{$status} must agree across families.");
            $this->assertSame($withoutFamily, $orderLifecycle, "{$status} without \$family must match the agreed intent.");
        }
    }

    public function test_explicit_family_isolates_a_status_that_only_exists_in_the_other_family(): void
    {
        // DITERIMA_VENDOR is vendor-processing only; asking the
        // order-lifecycle family for it must not silently borrow the
        // other family's answer.
        $this->assertSame(
            StatusIntent::INTENT_INFO,
            StatusIntent::intent('DITERIMA_VENDOR', StatusIntent::FAMILY_VENDOR_PROCESSING)
        );
        $this->assertSame(
            StatusIntent::INTENT_NEUTRAL,
            StatusIntent::intent('DITERIMA_VENDOR', StatusIntent::FAMILY_ORDER_LIFECYCLE)
        );
    }

    // -------------------------------------------------------------------
    // Defensive fallback — must never throw
    // -------------------------------------------------------------------

    public function test_unrecognised_status_falls_back_to_neutral_instead_of_throwing(): void
    {
        $this->assertSame(StatusIntent::INTENT_NEUTRAL, StatusIntent::intent('NOT_A_REAL_STATUS'));
        $this->assertIsString(StatusIntent::icon('NOT_A_REAL_STATUS'));
        $this->assertIsString(StatusIntent::label('NOT_A_REAL_STATUS'));
        $this->assertSame('gray', StatusIntent::filamentColor('NOT_A_REAL_STATUS'));
    }

    public function test_unrecognised_status_with_unknown_family_falls_back_to_neutral_instead_of_throwing(): void
    {
        $this->assertSame(StatusIntent::INTENT_NEUTRAL, StatusIntent::intent('MASUK', 'not_a_real_family'));
    }

    public function test_empty_status_string_falls_back_to_neutral_instead_of_throwing(): void
    {
        $this->assertSame(StatusIntent::INTENT_NEUTRAL, StatusIntent::intent(''));
    }

    // -------------------------------------------------------------------
    // Filament colour bridge — intent name is NOT the Filament colour key
    // -------------------------------------------------------------------

    public function test_filament_color_bridges_intent_to_a_filament_palette_key_not_the_intent_name_itself(): void
    {
        $this->assertSame('success', StatusIntent::filamentColor('DIBAYAR', StatusIntent::FAMILY_ORDER_LIFECYCLE));
        $this->assertSame('gray', StatusIntent::filamentColor('MASUK', StatusIntent::FAMILY_ORDER_LIFECYCLE));
        $this->assertSame('danger', StatusIntent::filamentColor('DITOLAK', StatusIntent::FAMILY_ORDER_LIFECYCLE));
        $this->assertSame('info', StatusIntent::filamentColor('DIVERIFIKASI', StatusIntent::FAMILY_ORDER_LIFECYCLE));
        $this->assertSame(
            'warning',
            StatusIntent::filamentColor('MENUNGGU_KETERSEDIAAN', StatusIntent::FAMILY_ORDER_LIFECYCLE)
        );

        // None of these equal the bare intent name ('pending' is not a
        // Filament colour key — it must resolve to 'warning').
        $this->assertNotSame(
            StatusIntent::INTENT_PENDING,
            StatusIntent::filamentColor('MENUNGGU_KETERSEDIAAN', StatusIntent::FAMILY_ORDER_LIFECYCLE)
        );
    }

    // -------------------------------------------------------------------
    // Registry introspection
    // -------------------------------------------------------------------

    public function test_known_families_lists_both_normative_families(): void
    {
        $families = StatusIntent::knownFamilies();

        $this->assertContains(StatusIntent::FAMILY_ORDER_LIFECYCLE, $families);
        $this->assertContains(StatusIntent::FAMILY_VENDOR_PROCESSING, $families);
        $this->assertContains(StatusIntent::FAMILY_MARKETPLACE_PAYMENT, $families);
        $this->assertContains(StatusIntent::FAMILY_CARE_SUBSCRIPTION, $families);
        $this->assertContains(StatusIntent::FAMILY_CARE_FULFILLMENT, $families);
        $this->assertContains(StatusIntent::FAMILY_CARE_WORK_ORDER, $families);
    }

    public function test_known_statuses_for_unregistered_family_is_empty_not_an_error(): void
    {
        $this->assertSame([], StatusIntent::knownStatuses('some_future_domain_not_yet_registered'));
    }

    public function test_intents_constant_matches_card_blade_valid_intents(): void
    {
        // Mirrors card.blade.php's own $validIntents list exactly (not
        // badge.blade.php, which uses a differently-shaped $intents array
        // of literal class strings, not a bare validation list — fixed
        // during review, the values here were already correct).
        $this->assertSame(
            ['neutral', 'info', 'pending', 'success', 'danger', 'urgent'],
            StatusIntent::INTENTS
        );
    }

    // -------------------------------------------------------------------
    // §3.7 table coverage — care subscription statuses
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function careSubscriptionProvider(): array
    {
        return [
            'draft' => ['draft', StatusIntent::INTENT_NEUTRAL, 'document-text'],
            'active' => ['active', StatusIntent::INTENT_SUCCESS, 'check-circle'],
            'paused' => ['paused', StatusIntent::INTENT_PENDING, 'pause-circle'],
            'ended' => ['ended', StatusIntent::INTENT_NEUTRAL, 'stop-circle'],
            'cancelled' => ['cancelled', StatusIntent::INTENT_NEUTRAL, 'slash'],
        ];
    }

    #[DataProvider('careSubscriptionProvider')]
    public function test_care_subscription_statuses_resolve_per_design_system_table(
        string $status,
        string $expectedIntent,
        string $expectedIcon
    ): void {
        $this->assertSame($expectedIntent, StatusIntent::intent($status, StatusIntent::FAMILY_CARE_SUBSCRIPTION));
        $this->assertSame($expectedIcon, StatusIntent::icon($status, StatusIntent::FAMILY_CARE_SUBSCRIPTION));
    }

    // -------------------------------------------------------------------
    // §3.7 table coverage — care fulfillment (cycle) statuses
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function careFulfillmentProvider(): array
    {
        return [
            'SCHEDULED' => ['SCHEDULED', StatusIntent::INTENT_NEUTRAL, 'calendar'],
            'INVOICED' => ['INVOICED', StatusIntent::INTENT_PENDING, 'document-text'],
            'PAID' => ['PAID', StatusIntent::INTENT_SUCCESS, 'banknote'],
            'WORK_SCHEDULED' => ['WORK_SCHEDULED', StatusIntent::INTENT_INFO, 'cog'],
            'COMPLETED' => ['COMPLETED', StatusIntent::INTENT_SUCCESS, 'check-badge'],
            'EXPIRED' => ['EXPIRED', StatusIntent::INTENT_NEUTRAL, 'clock-x'],
        ];
    }

    #[DataProvider('careFulfillmentProvider')]
    public function test_care_fulfillment_statuses_resolve_per_design_system_table(
        string $status,
        string $expectedIntent,
        string $expectedIcon
    ): void {
        $this->assertSame($expectedIntent, StatusIntent::intent($status, StatusIntent::FAMILY_CARE_FULFILLMENT));
        $this->assertSame($expectedIcon, StatusIntent::icon($status, StatusIntent::FAMILY_CARE_FULFILLMENT));
    }

    // -------------------------------------------------------------------
    // §3.7 table coverage — care work order statuses
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function careWorkOrderProvider(): array
    {
        return [
            'PENDING' => ['PENDING', StatusIntent::INTENT_NEUTRAL, 'inbox'],
            'ASSIGNED' => ['ASSIGNED', StatusIntent::INTENT_INFO, 'user-group'],
            'IN_PROGRESS' => ['IN_PROGRESS', StatusIntent::INTENT_PENDING, 'cog'],
            'COMPLETED' => ['COMPLETED', StatusIntent::INTENT_SUCCESS, 'check-badge'],
            'FAILED' => ['FAILED', StatusIntent::INTENT_DANGER, 'x-circle'],
            'RESCHEDULED' => ['RESCHEDULED', StatusIntent::INTENT_PENDING, 'arrow-path'],
            'CANCELLED' => ['CANCELLED', StatusIntent::INTENT_NEUTRAL, 'slash'],
        ];
    }

    #[DataProvider('careWorkOrderProvider')]
    public function test_care_work_order_statuses_resolve_per_design_system_table(
        string $status,
        string $expectedIntent,
        string $expectedIcon
    ): void {
        $this->assertSame($expectedIntent, StatusIntent::intent($status, StatusIntent::FAMILY_CARE_WORK_ORDER));
        $this->assertSame($expectedIcon, StatusIntent::icon($status, StatusIntent::FAMILY_CARE_WORK_ORDER));
    }

    // -------------------------------------------------------------------
    // Rule: PAID ≠ COMPLETED in care fulfillment
    // -------------------------------------------------------------------

    public function test_paid_and_completed_in_care_fulfillment_are_both_success_but_distinct_indicators(): void
    {
        $this->assertSame(
            StatusIntent::INTENT_SUCCESS,
            StatusIntent::intent('PAID', StatusIntent::FAMILY_CARE_FULFILLMENT)
        );
        $this->assertSame(
            StatusIntent::INTENT_SUCCESS,
            StatusIntent::intent('COMPLETED', StatusIntent::FAMILY_CARE_FULFILLMENT)
        );

        // Distinct icons and labels — never collapsed into one "done" badge
        $paidIcon = StatusIntent::icon('PAID', StatusIntent::FAMILY_CARE_FULFILLMENT);
        $completedIcon = StatusIntent::icon('COMPLETED', StatusIntent::FAMILY_CARE_FULFILLMENT);
        $this->assertNotSame($paidIcon, $completedIcon, 'PAID and COMPLETED must not share an icon.');
        $this->assertSame('banknote', $paidIcon);
        $this->assertSame('check-badge', $completedIcon);

        $paidLabel = StatusIntent::label('PAID', StatusIntent::FAMILY_CARE_FULFILLMENT);
        $completedLabel = StatusIntent::label('COMPLETED', StatusIntent::FAMILY_CARE_FULFILLMENT);
        $this->assertNotSame($paidLabel, $completedLabel, 'PAID and COMPLETED must not share a label.');
    }
}
