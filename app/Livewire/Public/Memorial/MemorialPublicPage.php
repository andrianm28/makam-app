<?php

declare(strict_types=1);

namespace App\Livewire\Public\Memorial;

use App\Domain\Memorial\Actions\ResolveMemorialQr;
use App\Domain\Memorial\Exceptions\MemorialNotVisibleException;
use App\Platform\IdentityAccess\ActorContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * `/m/{token}` — the QR resolve page (`.kiro/specs/memorial-and-qr/
 * requirements.md` AC3/AC5 and design.md's "Sequence — QR resolve,
 * gate-checked"; the plan's Task 4 brief).
 *
 * ===========================================================================
 * THE ONE READ PATH, AND THE ONE UNIFORM FAILED STATE
 * ===========================================================================
 * Every render runs `ResolveMemorialQr` — the ONLY public read path for a
 * memorial (see that action's own doc block) — in try/catch. Every denial
 * case (gate closed, unknown/revoked token, privacy) throws the SAME
 * `MemorialNotVisibleException`, and this page renders the SAME uniform
 * "Memorial tidak tersedia" state for every one of them (AC5's negative
 * criterion: the response never reveals which case applied, and never
 * whether the memorial exists).
 *
 * Running the resolve on EVERY render is what "re-check the gate on
 * render" means: a projection that resolved while `G-MEM-01` was open
 * falls back to the uniform state the moment the gate closes (a closed
 * gate mid-session must not keep serving the projection). The resolve is
 * read-only and idempotent, so this is the cheapest correct re-check —
 * there is no cached "I was visible" state to trust past the gate.
 *
 * The exception message is LOG-ONLY, deliberately: the `becausePrivacy()`
 * variant embeds the privacy mode, and the review watch for this task
 * rules the `/m/{token}` render must never surface privacy details.
 * Nothing derived from the message reaches the view; the blade renders
 * the uniform state, and only the allowlist projection when it resolved.
 *
 * The projection is NOT a public property: Livewire hydrates public
 * properties on every request, and `MemorialPublicProjection` is a plain
 * readonly value object with no synthesizer — it is resolved fresh in
 * `render()` and handed to the view directly.
 */
final class MemorialPublicPage extends Component
{
    /**
     * The QR token from the route (`/m/{token}`) — opaque, never derived,
     * bound by name to the route parameter.
     */
    public string $token = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function render(): View
    {
        $projection = null;
        $visible = false;

        try {
            $projection = app(ResolveMemorialQr::class)($this->token, app(ActorContext::class));
            $visible = true;
        } catch (MemorialNotVisibleException $exception) {
            // Log-only, never rendered — see the class doc block. Debug
            // level: an expected, uniform denial, not an error condition.
            Log::debug('memorial.resolve_denied: '.$exception->getMessage());
        }

        return view('livewire.public.memorial.public-page', [
            'visible' => $visible,
            'projection' => $projection,
        ]);
    }
}
