<?php

declare(strict_types=1);

namespace App\Domain\Faq\Contracts;

use App\Domain\Faq\Exceptions\FaqActionNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;

/**
 * The one place "may this actor manage FAQ content?" is decided.
 *
 * Deliberately an injected interface rather than a Laravel
 * `Illuminate\Auth\Access` policy class: this repository has no
 * `app/Policies/` directory, never calls `Gate::policy()` or
 * `$this->authorize()`, and expresses every existing authorization boundary
 * as a constructor-/container-resolved `…Authorizer` contract whose
 * implementation throws a module-local `…NotAuthorisedException`
 * (`App\Platform\FinancialLedger\Contracts\PayoutAuthorizer`,
 * `…\LedgerReadAuthorizer`, `…\ReconciliationAuthorizer`,
 * `…\VendorPayableAuthorizer`, and
 * `App\Platform\IdentityAccess\Contracts\PanelAccessPolicy`). Introducing a
 * Gate-backed policy here would create a second, parallel authorization
 * idiom for one module — the "two hand-maintained sources for one decision"
 * shape `AGENTS.md` §Documentation rejects, applied to authorization.
 *
 * ---------------------------------------------------------------------------
 * Two methods, because there are two enforcement layers with different jobs
 * ---------------------------------------------------------------------------
 * - `canManage()` answers a question. It is the predicate a UI layer reads to
 *   decide whether to RENDER and MOUNT a control (Filament
 *   `Action::authorize()`, `Resource::canViewAny()`), and it must never be
 *   the only check — a Livewire component method is directly addressable over
 *   the wire, so "the button was not rendered" is presentation, not
 *   enforcement.
 * - `authorizeManage()` enforces. It is called as the FIRST statement of every
 *   write path and throws, so the write cannot proceed even when the caller
 *   reached it without going through any rendered control.
 *
 * Both take the SERVER-RESOLVED `ActorContext` (`app(ActorContext::class)`,
 * a scoped binding). An implementation must never accept caller-supplied
 * identity, role, or permission data — the same rule
 * `Contracts\PayoutAuthorizer` states for its own vendor argument.
 */
interface FaqAuthorizer
{
    /**
     * `true` iff `$actor` may create, edit, publish, unpublish, or reorder
     * FAQ content. Never `true` for an unauthenticated actor.
     */
    public function canManage(ActorContext $actor): bool;

    /**
     * Same decision as `canManage()`, expressed as a guard.
     *
     * @throws FaqActionNotAuthorisedException when `canManage()` is `false`
     */
    public function authorizeManage(ActorContext $actor): void;
}
