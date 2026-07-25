<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate\Providers;

use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\EloquentGateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateActivationRecorder;
use App\Platform\FeatureGate\ModeResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Wires this batch's `FeatureGate` bindings.
 *
 * *** NOT YET REGISTERED IN `bootstrap/providers.php` — see this batch's
 * final report. *** `bootstrap/providers.php` is not in this batch's owned
 * paths, and (unlike Batch 3.1's `IdentityAccessServiceProvider`, which had
 * a real, safely-appendable copy of that file available) this worktree does
 * not contain the shared checkout's actual `bootstrap/providers.php`
 * content — writing one from scratch here would silently drop every other
 * provider already registered there (`AppServiceProvider`,
 * `AdminPanelProvider`, `IdentityAccessServiceProvider`) when this batch's
 * changes are integrated. The one line needed, once that file is available
 * to edit safely, is:
 *
 *   \App\Platform\FeatureGate\Providers\FeatureGateServiceProvider::class,
 *
 * Until that line is added, this entire file — and every binding below —
 * is dead code no consumer can reach, exactly the same risk
 * `IdentityAccessServiceProvider`'s own doc block flags for itself.
 */
final class FeatureGateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GateRegistrySource::class, function ($app): EloquentGateRegistrySource {
            return new EloquentGateRegistrySource(
                environment: $app->make('config')->get('app.env'),
            );
        });

        // scoped(), not singleton() — see FeatureGateResolver's class-level
        // doc block. A stale gate snapshot leaking across Horizon jobs is a
        // materially worse bug than a stale ActorContext, because it can
        // serve a payment gate's cached "open" state to work queued after
        // that gate was closed.
        $this->app->scoped(FeatureGateResolver::class, function ($app): FeatureGateResolver {
            return new FeatureGateResolver(
                $app->make(GateRegistrySource::class),
            );
        });

        $this->app->scoped(ModeResolver::class, function ($app): ModeResolver {
            return new ModeResolver(
                $app->make(FeatureGateResolver::class),
            );
        });

        // GateActivationRecorder holds no per-request state of its own (no
        // cached snapshot, no mutable property) — every method call reads
        // and writes the database fresh inside its own transaction — so an
        // ordinary container binding is correct here; `scoped()`'s request-
        // boundary reset has nothing to protect against for this class.
        $this->app->bind(GateActivationRecorder::class, GateActivationRecorder::class);
    }
}
