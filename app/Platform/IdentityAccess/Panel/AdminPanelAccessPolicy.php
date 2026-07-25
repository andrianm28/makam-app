<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Panel;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Contracts\PanelAccessPolicy;

/**
 * Explicit access check for the `/admin` Filament panel (Batch 2.4;
 * `app/Providers/Filament/AdminPanelProvider.php`, `->authMiddleware([
 * Authenticate::class])`).
 *
 * `docs/security/authentication-and-mfa.md` §7 documents the intended rule
 * as "`/admin` platform admin/finance/support by permission" — this batch
 * cannot implement that literally: `ActorContext::$roles` is always empty
 * (see that class's doc block for why — no local roles table exists and
 * K1/K2 role mapping, AC12, is unresolved). Implementing a role check
 * against a field this batch knows is always empty would either lock every
 * user out or require silently inventing a fake role source — both wrong.
 *
 * What this class deliberately DOES do: be the one explicit, named,
 * testable place `/admin` access is decided, satisfying the actual
 * mechanism AC4 requires ("SHALL NOT grant record access on panel
 * membership alone") — i.e. it is a real predicate that a policy/test can
 * target, not Filament's implicit "any FilamentUser-implementing model may
 * enter" default. Today that predicate is "the actor is authenticated",
 * which is not weaker than Batch 2.4's existing `Authenticate::class`
 * middleware — it is the same boundary expressed as an explicit,
 * independently testable authorization decision instead of an implicit
 * framework default, which is what AC4 asks for. Tightening this to a real
 * role/scope check is S3-T2/T3 and Batch 3.2 Agent C's job, once
 * `ActorContext::$roles`/`$scopes` are actually populated.
 */
final class AdminPanelAccessPolicy implements PanelAccessPolicy
{
    public function allows(ActorContext $actor): bool
    {
        return $actor->isAuthenticated();
    }
}
