<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\FeatureGate;

use App\Platform\FeatureGate\Exceptions\MissingActivationEvidenceException;
use App\Platform\FeatureGate\GateActivationRecorder;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * requirements.md Negative criteria: "No gate activation without recorded
 * evidence, owner, and date." — tasks.md: "Add tests: activation without
 * evidence is rejected." These specific checks run BEFORE
 * `GateActivationRecorder::record()` touches the database (see that
 * method), so this is a pure unit test — no `RefreshDatabase`, no gate row
 * needs to exist for these assertions to be meaningful. The success path
 * (which does need a real gate row) is covered by
 * `tests/Feature/FeatureGate/GateActivationRecorderTest.php`.
 */
final class GateActivationRecorderValidationTest extends TestCase
{
    public function test_blank_evidence_reference_is_rejected(): void
    {
        $this->expectException(MissingActivationEvidenceException::class);

        (new GateActivationRecorder)->record(
            gateId: 'G-PAY-01',
            actorReference: 1,
            toState: 'open',
            evidenceReference: '',
            reason: 'Merchant contract signed.',
        );
    }

    public function test_whitespace_only_evidence_reference_is_rejected(): void
    {
        $this->expectException(MissingActivationEvidenceException::class);

        (new GateActivationRecorder)->record(
            gateId: 'G-PAY-01',
            actorReference: 1,
            toState: 'open',
            evidenceReference: '   ',
            reason: 'Merchant contract signed.',
        );
    }

    public function test_blank_reason_is_rejected(): void
    {
        $this->expectException(MissingActivationEvidenceException::class);

        (new GateActivationRecorder)->record(
            gateId: 'G-PAY-01',
            actorReference: 1,
            toState: 'open',
            evidenceReference: 'DOC-1234',
            reason: '',
        );
    }

    public function test_invalid_target_state_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new GateActivationRecorder)->record(
            gateId: 'G-PAY-01',
            actorReference: 1,
            toState: 'sideways',
            evidenceReference: 'DOC-1234',
            reason: 'Merchant contract signed.',
        );
    }
}
