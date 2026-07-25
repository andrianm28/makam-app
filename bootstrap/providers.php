<?php

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
    // Batch 3.1 (S3-T1) — binds IdentityAccessAdapter/ActorContextResolver
    // and registers the actor_sessions login/logout listeners. See that
    // provider's own class-level comment for why this registration line is
    // here despite bootstrap/providers.php not being in that batch's
    // literal owned-files list (same precedent as AdminPanelProvider above).
    IdentityAccessServiceProvider::class,
];
