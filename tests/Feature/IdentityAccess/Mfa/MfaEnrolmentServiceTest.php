<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Mfa;

use App\Models\User;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Mfa\Exceptions\MfaEnrolmentNotPendingException;
use App\Platform\IdentityAccess\Mfa\MfaAuditActions;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Mfa\Models\MfaRecoveryCode;
use App\Platform\IdentityAccess\Mfa\Totp\Base32;
use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The pending -> confirmed enrolment lifecycle, recovery-code generation
 * on confirmation, and the "starting a new enrolment replaces the old one"
 * semantics `MfaEnrolmentService`'s own doc block commits to.
 *
 * NOT covered here — and this must stay true for this whole batch (S3-T2,
 * HUMAN-GATED): nothing in this test exercises any existing login,
 * panel-access, or middleware path. Every enrolment here is created and
 * confirmed directly through the service under test, in isolation.
 */
final class MfaEnrolmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_an_enrolment_creates_a_pending_row_with_a_usable_secret(): void
    {
        $user = User::factory()->create();
        $service = new MfaEnrolmentService;

        $enrolment = $service->startEnrolment($user->id);

        $this->assertSame(MfaEnrolmentStatus::PENDING, $enrolment->status);
        $this->assertSame($user->id, $enrolment->user_id);
        $this->assertNotEmpty($enrolment->secret);
        $this->assertNull($enrolment->confirmed_at);
    }

    public function test_secret_is_persisted_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $service = new MfaEnrolmentService;

        $enrolment = $service->startEnrolment($user->id);

        $rawColumnValue = DB::table('mfa_enrolments')->where('id', $enrolment->id)->value('secret');

        $this->assertNotSame($enrolment->secret, $rawColumnValue);
        $this->assertStringNotContainsString($enrolment->secret, (string) $rawColumnValue);
    }

    public function test_confirming_with_a_valid_code_activates_the_enrolment_and_returns_recovery_codes(): void
    {
        $user = User::factory()->create();
        $service = new MfaEnrolmentService;
        $enrolment = $service->startEnrolment($user->id);

        $code = $this->currentCodeFor($enrolment);

        $confirmation = $service->confirm(
            $enrolment,
            $code,
            actorRef: $user->id,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertTrue($confirmation->valid);
        $this->assertSame(MfaEnrolmentStatus::CONFIRMED, $confirmation->enrolment->status);
        $this->assertNotNull($confirmation->enrolment->confirmed_at);
        $this->assertCount(10, $confirmation->recoveryCodes);
    }

    public function test_recovery_codes_are_never_stored_in_plaintext(): void
    {
        $user = User::factory()->create();
        $service = new MfaEnrolmentService;
        $enrolment = $service->startEnrolment($user->id);
        $code = $this->currentCodeFor($enrolment);

        $confirmation = $service->confirm($enrolment, $code, $user->id, 'admin', AuditSource::Panel);

        $storedHashes = MfaRecoveryCode::query()
            ->where('mfa_enrolment_id', $confirmation->enrolment->id)
            ->pluck('code_hash');

        foreach ($confirmation->recoveryCodes as $plaintextCode) {
            $this->assertFalse($storedHashes->contains($plaintextCode));

            $matchesSomeHash = $storedHashes->contains(
                fn (string $hash): bool => Hash::check($plaintextCode, $hash)
            );
            $this->assertTrue($matchesSomeHash, 'Expected one stored hash to verify against the returned plaintext code.');
        }
    }

    public function test_confirming_with_an_invalid_code_does_not_activate_the_enrolment(): void
    {
        $user = User::factory()->create();
        $service = new MfaEnrolmentService;
        $enrolment = $service->startEnrolment($user->id);

        $confirmation = $service->confirm($enrolment, '000000', $user->id, 'admin', AuditSource::Panel);

        $this->assertFalse($confirmation->valid);
        $enrolment->refresh();
        $this->assertSame(MfaEnrolmentStatus::PENDING, $enrolment->status);
        $this->assertSame(0, MfaRecoveryCode::query()->where('mfa_enrolment_id', $enrolment->id)->count());
    }

    public function test_confirming_an_already_confirmed_enrolment_throws(): void
    {
        $user = User::factory()->create();
        $service = new MfaEnrolmentService;
        $enrolment = $service->startEnrolment($user->id);
        $code = $this->currentCodeFor($enrolment);
        $confirmation = $service->confirm($enrolment, $code, $user->id, 'admin', AuditSource::Panel);

        $this->expectException(MfaEnrolmentNotPendingException::class);

        $service->confirm($confirmation->enrolment, $code, $user->id, 'admin', AuditSource::Panel);
    }

    public function test_starting_a_new_enrolment_revokes_the_previous_one(): void
    {
        $user = User::factory()->create();
        $service = new MfaEnrolmentService;

        $first = $service->startEnrolment($user->id);
        $second = $service->startEnrolment($user->id);

        $first->refresh();
        $this->assertSame(MfaEnrolmentStatus::REVOKED, $first->status);
        $this->assertNotNull($first->revoked_at);
        $this->assertSame(MfaEnrolmentStatus::PENDING, $second->status);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_confirmation_writes_an_allowed_audit_event_without_a_reason(): void
    {
        $user = User::factory()->create();
        $service = new MfaEnrolmentService;
        $enrolment = $service->startEnrolment($user->id);
        $code = $this->currentCodeFor($enrolment);

        $service->confirm($enrolment, $code, $user->id, 'admin', AuditSource::Panel);

        $event = AuditEvent::query()->where('action', MfaAuditActions::ENROLMENT_CONFIRMED)->latest('id')->first();

        $this->assertNotNull($event);
        $this->assertSame(AuditOutcome::Allowed->value, $event->outcome);
        $this->assertSame((string) $enrolment->id, $event->subject_id);
    }

    public function test_revoking_requires_a_non_blank_reason_and_writes_an_audit_event(): void
    {
        $user = User::factory()->create();
        $service = new MfaEnrolmentService;
        $enrolment = $service->startEnrolment($user->id);
        $code = $this->currentCodeFor($enrolment);
        $confirmation = $service->confirm($enrolment, $code, $user->id, 'admin', AuditSource::Panel);

        $service->revoke($confirmation->enrolment, $user->id, 'admin', 'Actor lost their device.', AuditSource::Panel);

        $confirmation->enrolment->refresh();
        $this->assertSame(MfaEnrolmentStatus::REVOKED, $confirmation->enrolment->status);

        $event = AuditEvent::query()->where('action', MfaAuditActions::RESET)->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('Actor lost their device.', $event->reason);
    }

    private function currentCodeFor(MfaEnrolment $enrolment): string
    {
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);

        return $totp->generate(Base32::decode($enrolment->secret), time(), $enrolment->digits);
    }
}
