<?php

declare(strict_types=1);

namespace App\Platform\Correlation\Providers;

use App\Platform\Correlation\CorrelationContext;
use Illuminate\Support\ServiceProvider;

/**
 * Wires this batch's `Correlation` binding.
 *
 * Registered in `bootstrap/providers.php` — same precedent
 * `IdentityAccessServiceProvider` already established (see that class's
 * own class-level comment): a new provider is dead code no consumer can
 * reach without an additive registration line there, so this batch's brief
 * explicitly authorizes exactly that one line, and no other change to that
 * shared file.
 */
final class CorrelationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped(), not singleton() — see CorrelationContext's class-level
        // doc block for why (Horizon queue workers are a real long-lived
        // -process risk in this codebase even though there is no Octane).
        // No factory closure needed: CorrelationContext has a zero-argument
        // constructor, so the container can build it via reflection.
        $this->app->scoped(CorrelationContext::class);
    }
}
