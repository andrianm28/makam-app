<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('document-download', static function (Request $request): Limit {
            $actorRef = $request->user()?->getAuthIdentifier() ?? 'guest';

            return Limit::perMinute(5)->by("{$actorRef}|{$request->ip()}");
        });

        // A bulk financial export returns a whole period's account totals, so
        // it is rate-limited at least as tightly as a single document
        // download. Keyed by actor AND ip for the same reason that one is:
        // neither alone is a stable identifier for an authenticated download.
        RateLimiter::for('financial-export', static function (Request $request): Limit {
            $actorRef = $request->user()?->getAuthIdentifier() ?? 'guest';

            return Limit::perMinute(5)->by("{$actorRef}|{$request->ip()}");
        });
    }
}
