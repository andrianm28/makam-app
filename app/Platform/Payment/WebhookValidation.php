<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use App\Platform\Payment\Providers\SignatureMechanism;

/**
 * The outcome of `WebhookValidator::validate()`: the status to record on the
 * durable `provider_events` row, plus an internal-only explanation.
 *
 * `$detail` is composed exclusively from closed-list values and field NAMES.
 * It is written to `provider_events.rejection_detail` and to the audit `note`,
 * so it must never carry a payload value, an amount, an identifier, or any
 * part of a credential (`AGENTS.md` §Observability, AC14).
 */
final readonly class WebhookValidation
{
    private function __construct(
        public ProviderEventStatus $status,
        public ?string $detail = null,
        public ?SignatureMechanism $mechanism = null,
    ) {}

    public static function passed(SignatureMechanism $mechanism): self
    {
        return new self(ProviderEventStatus::Validated, mechanism: $mechanism);
    }

    public static function rejected(
        ProviderEventStatus $status,
        string $detail,
        ?SignatureMechanism $mechanism = null,
    ): self {
        return new self($status, $detail, $mechanism);
    }

    public function isValid(): bool
    {
        return $this->status === ProviderEventStatus::Validated;
    }
}
