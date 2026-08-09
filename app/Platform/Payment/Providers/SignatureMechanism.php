<?php

declare(strict_types=1);

namespace App\Platform\Payment\Providers;

/**
 * Which of ADR-0033's two verification mechanisms handled a delivery. Recorded
 * on `provider_events.signature_mechanism` so an operator can tell, per event,
 * whether the replay window was actually enforceable — only the Svix path
 * carries a provider timestamp. See `config/payment.php`
 * §`webhook.allow_shared_token`.
 */
enum SignatureMechanism: string
{
    case Svix = 'svix';
    case SharedToken = 'shared-token';
}
