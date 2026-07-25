<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa;

use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;

/**
 * Outcome of `MfaEnrolmentService::confirm()`. `$recoveryCodes` carries the
 * PLAINTEXT recovery codes exactly once — generated fresh by `confirm()`,
 * never persisted anywhere in this form (`Models\MfaRecoveryCode` only ever
 * stores `code_hash`). The caller (a future enrolment-confirmation
 * controller this batch does not build) is responsible for displaying
 * these to the actor a single time and never logging or persisting this
 * value object itself.
 */
final class MfaEnrolmentConfirmation
{
    /**
     * @param  list<string>  $recoveryCodes
     */
    private function __construct(
        public readonly bool $valid,
        public readonly ?MfaEnrolment $enrolment,
        public readonly array $recoveryCodes,
    ) {}

    /**
     * @param  list<string>  $recoveryCodes
     */
    public static function success(MfaEnrolment $enrolment, array $recoveryCodes): self
    {
        return new self(valid: true, enrolment: $enrolment, recoveryCodes: $recoveryCodes);
    }

    public static function failure(): self
    {
        return new self(valid: false, enrolment: null, recoveryCodes: []);
    }
}
