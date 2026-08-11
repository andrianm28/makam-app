<?php

declare(strict_types=1);

namespace App\Platform\Payment;

/**
 * The provider slugs this module stores in `payment_sessions.provider` and
 * `provider_events.provider`, in one place so no call site, migration, or test
 * spells one as a magic string.
 *
 * ---------------------------------------------------------------------------
 * On the spelling, which is genuinely inconsistent upstream
 * ---------------------------------------------------------------------------
 * Three spellings appear in the approved documents and they are NOT
 * interchangeable:
 *
 *   - The vendor and its API host are **SumoPod** (`api-pay-sandbox.sumopod.com`)
 *     — ADR-0033 §Decision. That is what this slug follows.
 *   - The ADR's *filename* is `0033-choose-sumpod-sandbox-for-dev-payment.md`
 *     ("sumpod"), and the plan's §File Structure repeats that spelling once
 *     when naming `PaymentServiceProvider`'s binding key. Both are typos of
 *     the vendor name; neither is a value any code compares against.
 *   - The credential environment variable is `SUMODOP_SANDBOX_API_KEY`
 *     ("SUMODOP"). ADR-0033 §Credential is explicit that this exact spelling
 *     is authoritative and is not to be "corrected" — the variable is already
 *     injected into the host env file under that name, so renaming it here
 *     would silently read an unset variable. `config/payment.php` therefore
 *     keeps it verbatim.
 *
 * Nothing is renamed by this class; it only fixes ONE slug for the database
 * so a later provider row cannot drift between the three spellings.
 */
final class PaymentProviders
{
    /**
     * ADR-0033's dev/staging provider. Production activation is a separate
     * decision (`G-PAY-01` stays closed there), so no production slug exists.
     */
    public const string SUMOPOD_SANDBOX = 'sumopod-sandbox';
}
