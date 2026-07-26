<?php

use App\Platform\Correlation\Providers\CorrelationServiceProvider;
use App\Platform\FeatureGate\Providers\FeatureGateServiceProvider;
use App\Platform\IdentityAccess\Providers\IdentityAccessServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    // AdminPanelProvider's own class-level comment documents exactly which
    // parts of its Filament 5 API usage are unverified (composer install
    // cannot run on this host — CI is the first real boot of this class
    // against the actual pinned filament/filament v5.7.3 package).
    AdminPanelProvider::class,
    // FeatureGateServiceProvider's own class-level comment documented this
    // exact missing line since the batch that wrote it (Sprint 3):
    // GateRegistrySource/FeatureGateResolver/ModeResolver bindings existed
    // but were unreachable dead code until now. Every FeatureGate test
    // before S4-T3 used an in-memory GateRegistrySource stub, bypassing the
    // container entirely — S4-T3's homepage was the first real HTTP
    // request in this codebase to call app(ModeResolver::class), which is
    // what actually surfaced this as a live 500
    // (BindingResolutionException: GateRegistrySource is not instantiable).
    FeatureGateServiceProvider::class,
    // Batch 3.1 (S3-T1) — binds IdentityAccessAdapter/ActorContextResolver
    // and registers the actor_sessions login/logout listeners. See that
    // provider's own class-level comment for why this registration line is
    // here despite bootstrap/providers.php not being in that batch's
    // literal owned-files list (same precedent as AdminPanelProvider above).
    IdentityAccessServiceProvider::class,
    // Batch 3.3 (S3-T10) — binds CorrelationContext (scoped()). Same
    // precedent as IdentityAccessServiceProvider above: this file is not
    // in that batch's literal owned-files list either, but the brief
    // explicitly authorizes this one additive line.
    CorrelationServiceProvider::class,
];
