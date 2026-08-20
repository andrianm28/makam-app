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

        // Public-beta readiness (Lane D4): three admin/money-adjacent routes
        // outside the Filament panel's own middleware stack —
        // /admin/mfa/disable, the manual-payment-verification approval, and
        // payment reversals — previously carried no throttle at all, unlike
        // document-download/financial-export above. Same 5/minute-per-
        // actor+ip shape: none of these three is a high-frequency legitimate
        // action, so tight is correct, not merely convenient.
        RateLimiter::for('mfa-disable', static function (Request $request): Limit {
            $actorRef = $request->user()?->getAuthIdentifier() ?? 'guest';

            return Limit::perMinute(5)->by("{$actorRef}|{$request->ip()}");
        });

        RateLimiter::for('payment-manual-verification', static function (Request $request): Limit {
            $actorRef = $request->user()?->getAuthIdentifier() ?? 'guest';

            return Limit::perMinute(5)->by("{$actorRef}|{$request->ip()}");
        });

        RateLimiter::for('payment-reversal', static function (Request $request): Limit {
            $actorRef = $request->user()?->getAuthIdentifier() ?? 'guest';

            return Limit::perMinute(5)->by("{$actorRef}|{$request->ip()}");
        });

        // Public-beta readiness: every public journey (booking wizard,
        // marketplace checkout/cart, grave search, pre-need interest,
        // visitation booking) is a Livewire component, and Livewire's own
        // actions all funnel through its ONE shared `/livewire/update`
        // endpoint (vendor/livewire/livewire's `HandleRequests` registers
        // that route on the `web` middleware group) — there is no
        // per-action route to attach a named `throttle:` limiter to the
        // way `document-download`/`financial-export` do above. This limiter
        // is instead applied to the whole `web` group
        // (`bootstrap/app.php`), which covers both the initial page load
        // and every subsequent Livewire round trip for every public screen
        // in one place.
        //
        // Authenticated arm: `Limit::none()` was the original default here,
        // exempting every authenticated request from this limiter entirely.
        // That stopped being harmless the moment `/akun/daftar` (Task 2 of
        // the `/akun` account area,
        // `.superpowers/sdd/2026-08-20-akun-auth-foundation/task-2-brief.md`)
        // let a guest self-authenticate on a public route: "create a free
        // account" would otherwise have become a standing, unthrottled
        // bypass of the whole public rate limit. `Limit::perMinute(120)->by
        // ('user:'.$user->getAuthIdentifier())` keyed per authenticated
        // actor instead — 120/minute preserves this limiter's original
        // concern (a real customer's own multi-step booking wizard makes
        // many round trips) while closing the bypass with a generous but
        // finite ceiling.
        //
        // 60/minute per IP (guest arm) is deliberately generous: a real
        // visitor filling the 9-step booking wizard makes tens of Livewire
        // round trips (`wire:model` updates plus each `saveStepN` call)
        // across several minutes, and this must never be the reason a
        // genuine customer gets cut off mid-booking. It is tight enough to
        // blunt a scripted flood, which is the actual threat on an
        // unthrottled anonymous surface — not a precise capacity figure (no
        // load test has been run; `performance-and-capacity.md`'s profiles
        // remain unexecuted).
        RateLimiter::for('public-guest', static function (Request $request): Limit {
            $user = $request->user();

            if ($user !== null) {
                return Limit::perMinute(120)->by('user:'.$user->getAuthIdentifier());
            }

            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
