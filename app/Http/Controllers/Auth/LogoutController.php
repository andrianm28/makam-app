<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Platform\IdentityAccess\ActorContextResolver;
use Illuminate\Http\RedirectResponse;

/**
 * `POST /keluar` — Task 1 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-auth-foundation/task-1-brief.md`).
 * Logs the `web` guard out, invalidates the session, and redirects to
 * `/masuk`.
 *
 * `ActorContextResolver::forget()` is called defensively here even though
 * nothing else in this request is expected to resolve `ActorContext` again
 * before the redirect: `auth()->logout()` is the same class of mid-request
 * guard mutation `LoginPage::login()`'s own doc block warns about, just in
 * the opposite direction (authenticated -> guest instead of guest ->
 * authenticated). Nothing currently reads `ActorContext` later in this
 * controller, so this is prevention against a future addition to this
 * method silently reading a stale authenticated context after logout, not
 * a fix for an observed bug today.
 */
final class LogoutController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        auth()->logout();

        session()->invalidate();
        session()->regenerateToken();

        app(ActorContextResolver::class)->forget();

        return redirect()->route('login');
    }
}
