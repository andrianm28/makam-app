<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate;

/**
 * Structural description of a closed gate's declared fallback behaviour —
 * design.md's Fallback contract: "Each gate declares its closed-state
 * behaviour ... Closing a gate switches behaviour; it never deletes a
 * route or a step."
 *
 * Deliberately carries NO copy/message text. The actual Indonesian banner
 * copy is product content owned by whoever renders
 * `<x-mk.gate-closed-banner>` / `<x-mk.gate-closed-page>` for a specific
 * screen (this batch's brief: "you do not need to implement every one of
 * the 17 gates' specific real-world fallback content ... build the generic
 * mechanism and demonstrate it with 1-2 concrete examples"). What this
 * value object DOES carry is exactly the structural decision design-
 * system.md §6.9 says must not be left to each call site to get wrong:
 * which alert `intent` applies, and whether the banner may ever be
 * dismissed.
 *
 * `$dismissible` follows §6.9 verbatim: "Dismissible only for informational
 * modes — never for one that changes how a user must pay [or receives]."
 * See `Modes\PaymentMode` and `Modes\PreNeedMode` for the two concrete
 * examples that set this `false`.
 */
final readonly class GateFallback
{
    public function __construct(
        /**
         * One of design-system.md §3.7/§3.8's alert intents. This class
         * does not validate against that list itself — §9.2 MUST #5
         * requires status → intent resolution to happen in exactly one
         * place (`app/Support/Design/StatusIntent.php`, `app/Support/
         * Design/**` is out of this batch's owned paths), so this value
         * object only carries whatever intent its caller already resolved,
         * the same way `<x-mk.alert>` itself only renders whatever intent
         * string it is handed.
         */
        public string $intent,
        public bool $dismissible,
    ) {}
}
