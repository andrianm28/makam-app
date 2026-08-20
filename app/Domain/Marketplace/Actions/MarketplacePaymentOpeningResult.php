<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Actions;

use InvalidArgumentException;

/**
 * The result of one `GuardMarketplacePaymentOpening` evaluation.
 *
 * Mirrors `App\Domain\Renewal\Actions\RenewalPaymentOpeningResult`'s shape —
 * deny-by-default, a non-blank reason on every denial — but simpler: unlike
 * renewal's anonymous journey, marketplace checkout has no "eligible but
 * gate closed => manual coordination" state to distinguish, because
 * `Livewire\Public\Marketplace\Checkout` already renders the manual-transfer
 * path unconditionally regardless of the gate (see that component's class
 * doc block). A closed gate is therefore a plain denial here, not a third
 * state.
 */
final readonly class MarketplacePaymentOpeningResult
{
    private function __construct(
        private bool $allowed,
        private ?string $denialReason,
    ) {}

    public static function allowed(): self
    {
        return new self(allowed: true, denialReason: null);
    }

    /**
     * @throws InvalidArgumentException when `$denialReason` is blank
     */
    public static function denied(string $denialReason): self
    {
        if (trim($denialReason) === '') {
            throw new InvalidArgumentException('A denial must carry a non-blank reason.');
        }

        return new self(allowed: false, denialReason: $denialReason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function isDenied(): bool
    {
        return ! $this->allowed;
    }

    /**
     * @throws InvalidArgumentException on an allowed result — it has no
     *                                  denial reason to report.
     */
    public function denialReason(): string
    {
        if ($this->denialReason === null) {
            throw new InvalidArgumentException('An allowed result carries no denial reason.');
        }

        return $this->denialReason;
    }
}
