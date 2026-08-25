<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate\Modes;

use App\Platform\FeatureGate\GateFallback;

/**
 * `G-MEM-01`'s mode value — `.kiro/specs/memorial-and-qr/requirements.md`
 * AC5's gate ("Public memorial/QR" — seeded `closed` in
 * `2026_07_26_120400_seed_feature_gate_registry.php`), following the
 * exact `fromGateOpen(bool)` + `fallback(): ?GateFallback` shape
 * `GraveSearchMode` established (and the plan's Task 3 brief fixes the
 * two case names verbatim).
 *
 * design.md's "Sequence — QR resolve, gate-checked": while the gate is
 * closed, `/m/{token}` renders the uniform "memorial tidak tersedia"
 * response and NO token lookup is attempted (a lookup that distinguished
 * valid from invalid tokens would leak which tokens exist).
 *
 * The closed-case fallback is `intent: 'info'`, dismissible — same
 * class as `GraveSearchMode::ManualAssistance` (closing this gate
 * changes a read path, not how a user pays or what they receive, so
 * §6.9's "never dismissible" class does not apply).
 */
enum MemorialMode: string
{
    /**
     * `G-MEM-01` open: QR resolution to the allowlisted public
     * projection is available.
     */
    case PublicMemorial = 'public_memorial';

    /**
     * `G-MEM-01` closed: the memorial/QR surface is unavailable to
     * visitors — the uniform not-visible response, no token lookup.
     * Family/admin surfaces are NOT gated by this mode (they run on
     * consent and role, respectively).
     */
    case Unavailable = 'unavailable';

    public static function fromGateOpen(bool $open): self
    {
        return $open ? self::PublicMemorial : self::Unavailable;
    }

    /**
     * `null` when the gate is open — nothing to fall back to. The
     * closed case is an informational, dismissible notice.
     */
    public function fallback(): ?GateFallback
    {
        return match ($this) {
            self::PublicMemorial => null,
            self::Unavailable => new GateFallback(intent: 'info', dismissible: true),
        };
    }
}
