<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PreNeed;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\PreNeed\Actions\RegisterPreNeedInterest;
use App\Domain\PreNeed\Actions\RequestPreNeedConsultation;
use App\Domain\PreNeed\Models\PreNeedConsultationRequest;
use App\Domain\PreNeed\PreNeedAuditActions;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 5 (`docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md`)
 * — `App\Domain\PreNeed\Actions\RequestPreNeedConsultation`, the ONLY
 * writer of `pre_need_consultation_requests`: the happy path (row +
 * `PRENEED_CONSULTATION_REQUESTED` audit in one transaction), the optional
 * interest linkage, and the `G-LEGAL-01` discipline — the consultation
 * flow is never gated.
 */
final class RequestPreNeedConsultationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_consultation_request_persists_and_audits(): void
    {
        $request = app(RequestPreNeedConsultation::class)(
            name: 'Ibu Siti',
            contact: '0812-3456-7890',
            message: 'Saya ingin konsultasi tentang pra-pesanan makam.',
        );

        self::assertInstanceOf(PreNeedConsultationRequest::class, $request);

        self::assertDatabaseHas('pre_need_consultation_requests', [
            'id' => $request->getKey(),
            'name' => 'Ibu Siti',
            'contact' => '0812-3456-7890',
            'message' => 'Saya ingin konsultasi tentang pra-pesanan makam.',
            'pre_need_interest_id' => null,
        ]);

        // The audit row commits with the row (Audit::wrap, AC4) and the
        // action is a customer self-service act, so it is NOT on
        // SensitiveActions and carries no reason.
        self::assertDatabaseHas('audit_events', [
            'action' => PreNeedAuditActions::PRENEED_CONSULTATION_REQUESTED,
            'subject_type' => 'pre_need_consultation_request',
            'subject_id' => $request->getKey(),
            'outcome' => 'allowed',
            'actor_role' => 'guest',
        ]);
    }

    public function test_a_consultation_request_can_be_linked_to_a_registered_interest(): void
    {
        $draft = BookingDraft::query()->create([
            'city_code' => LaunchCityCode::JAKARTA,
            'service_type' => BookingServiceType::PRE_NEED,
        ]);

        $interest = app(RegisterPreNeedInterest::class)($draft);

        $request = app(RequestPreNeedConsultation::class)(
            name: 'Bapak Udin',
            contact: '0821-1111-2222',
            message: 'Tindak lanjut minat saya.',
            preNeedInterestId: $interest->getKey(),
        );

        self::assertSame($interest->getKey(), $request->pre_need_interest_id);

        self::assertDatabaseHas('pre_need_consultation_requests', [
            'id' => $request->getKey(),
            'pre_need_interest_id' => $interest->getKey(),
        ]);
    }

    /**
     * `G-LEGAL-01` discipline: the consultation request is gate-INDEPENDENT
     * — it must persist identically whether the gate is closed or open,
     * because it creates no financial obligation either way. The registry
     * binding is swapped to prove the Action does not consult the gate at
     * all (the same "read server-side, never hardcoded" discipline the
     * interest registration asserts through its recorded `gate_mode`).
     */
    public function test_the_consultation_is_gate_independent_in_both_modes(): void
    {
        foreach ([false, true] as $open) {
            $this->bindGateRegistryWith('G-LEGAL-01', open: $open);

            $request = app(RequestPreNeedConsultation::class)(
                name: 'Nama',
                contact: '0812',
                message: 'Pesan konsultasi',
            );

            self::assertDatabaseHas('pre_need_consultation_requests', [
                'id' => $request->getKey(),
                'name' => 'Nama',
            ]);

            self::assertDatabaseHas('audit_events', [
                'action' => PreNeedAuditActions::PRENEED_CONSULTATION_REQUESTED,
                'subject_id' => $request->getKey(),
            ]);
        }
    }

    /**
     * Replaces the database-backed gate registry with an in-memory source,
     * the same stub shape `tests/Feature/OrderWorkflow/SubmitBookingDraftTest`
     * uses.
     */
    private function bindGateRegistryWith(string $gateId, bool $open): void
    {
        $states = [$gateId => GateState::fromRecord($gateId, open: $open)];

        $this->app->instance(GateRegistrySource::class, new class($states) implements GateRegistrySource
        {
            /**
             * @param  array<string, GateState>  $states
             */
            public function __construct(private readonly array $states) {}

            public function load(): GateRegistrySnapshot
            {
                return new GateRegistrySnapshot($this->states);
            }
        });
    }
}
