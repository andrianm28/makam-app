<?php

declare(strict_types=1);

namespace App\Platform\Payment\Providers;

/**
 * The result of one signature verification: what was concluded, and (only when
 * the conclusion is `Verified`) which mechanism concluded it.
 *
 * Carries no secret, no signature, no computed digest — nothing that could be
 * usefully logged by a caller that logs the whole result object.
 */
final readonly class SignatureVerification
{
    private function __construct(
        public SignatureOutcome $outcome,
        public ?SignatureMechanism $mechanism = null,
    ) {}

    public static function verified(SignatureMechanism $mechanism): self
    {
        return new self(SignatureOutcome::Verified, $mechanism);
    }

    public static function failed(SignatureOutcome $outcome): self
    {
        return new self($outcome);
    }

    public function isVerified(): bool
    {
        return $this->outcome === SignatureOutcome::Verified;
    }
}
