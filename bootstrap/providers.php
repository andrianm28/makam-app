<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    // AdminPanelProvider's own class-level comment documents exactly which
    // parts of its Filament 5 API usage are unverified (composer install
    // cannot run on this host — CI is the first real boot of this class
    // against the actual pinned filament/filament v5.7.3 package).
    AdminPanelProvider::class,
];
