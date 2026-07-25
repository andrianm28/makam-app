<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess;

use App\Platform\IdentityAccess\Contracts\IdentityAccessAdapter;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * Resolves `ActorContext` exactly once per request/job and hands every
 * subsequent caller the same cached instance — requirements.md AC8:
 * "resolve actor context (identity, roles, scopes) once per request as the
 * single source consumers read."
 *
 * ---------------------------------------------------------------------------
 * How this avoids re-resolving per call, and how it avoids leaking across
 * requests (this batch's binding, in `Providers\IdentityAccessServiceProvider`)
 * ---------------------------------------------------------------------------
 * This class itself is a plain object with a nullable cache property
 * (`$resolved`) — `resolve()` only calls the adapter the first time it is
 * invoked on a given instance; every call after that returns the cached
 * value. That gives "resolve once" IF AND ONLY IF exactly one instance of
 * this class exists for the lifetime of one request/job.
 *
 * That single-instance-per-request guarantee is the container binding's
 * job, not this class's. It is bound with Laravel's `scoped()` binding
 * (`Container::scoped()`), not a bare `singleton()`:
 *
 * - A bare `singleton()` binding lives for the lifetime of the *container*,
 *   not the request. Without Octane, a fresh container is built per HTTP
 *   request (`public/index.php` boots a new `Application` every time), so a
 *   plain `singleton()` happens to behave correctly for the web path today
 *   — this repo runs no Octane (AGENTS.md: "Do not introduce Octane ...
 *   without an approved ADR"). But `singleton()` would silently be wrong
 *   the moment that constraint changes, which is exactly the "static that
 *   survives a request boundary is a real bug class" the batch brief warns
 *   about.
 * - The concrete, present-tense reason this repo cannot just rely on
 *   "no Octane" and call it safe: `docs/architecture/overview.md` §3 pins
 *   "Redis 8.2, Laravel Queue + Horizon" — Horizon's queue workers are
 *   long-running processes that boot the application ONCE and then process
 *   many jobs in the same process, exactly like Octane's request loop from
 *   this binding's point of view. A bare `singleton()` bound to
 *   `ActorContextResolver`/`ActorContext` would leak the first job's
 *   resolved actor into every later job handled by the same worker.
 * - `scoped()` bindings are exactly Laravel's primitive for this: the
 *   framework flushes them (`Container::forgetScopedInstances()`) between
 *   Octane requests AND between queue worker jobs, giving genuine
 *   per-request/per-job isolation regardless of process lifetime. That is
 *   why `IdentityAccessServiceProvider` uses `scoped()`, not `singleton()`,
 *   even though this repo currently has no Octane and no queued consumer of
 *   `ActorContext` yet.
 *
 * `forget()` exists as an explicit escape hatch for the one case the cache
 * cannot know about on its own: a future login controller calling
 * `Auth::login()` mid-request, after something earlier in the same request
 * already resolved a guest `ActorContext`. No such controller exists yet in
 * this batch's scope (`routes/web.php` has no login route; Filament's own
 * `/admin` login page is a full request-response round trip, so it does not
 * hit this mid-request-login-mutation case). Flagged here rather than
 * silently left for whoever builds that flow to discover the hard way.
 */
final class ActorContextResolver
{
    private ?ActorContext $resolved = null;

    public function __construct(
        private readonly IdentityAccessAdapter $adapter,
        private readonly AuthFactory $auth,
        private readonly string $guard = 'web',
    ) {}

    public function resolve(): ActorContext
    {
        if ($this->resolved === null) {
            $this->resolved = $this->adapter->resolveActorContext(
                $this->auth->guard($this->guard)->user()
            );
        }

        return $this->resolved;
    }

    /**
     * Discard the cached `ActorContext` so the next `resolve()` call
     * re-derives it from the guard. See the class-level note — intended for
     * a future login/logout flow to call immediately after mutating the
     * guard's authenticated user mid-request.
     */
    public function forget(): void
    {
        $this->resolved = null;
    }
}
