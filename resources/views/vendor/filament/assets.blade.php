{{--
    View override, not a vendor patch — see Laravel's own `resources/views/vendor/{package}`
    convention (`Illuminate\Support\ServiceProvider::loadViewsFrom()`, invoked here by
    `Spatie\LaravelPackageTools`'s `ProcessViews::bootPackageViews()`): a file at this exact path
    is resolved *before* the package's own `filament::assets` view, with no vendor/ file touched.

    Root-caused SEC-08/CSP follow-up (26 Aug 2026): Filament v5 has no first-class CSP nonce
    mechanism at all — confirmed by a repo-wide `grep -r nonce vendor/filament` (zero hits) and by
    Filament's own open, unresolved community discussions (filamentphp/filament#7032, #8329)
    asking for exactly this. Livewire's OWN injected `<style data-livewire-style>`/`<script>` tags
    already carry the nonce correctly (`vendor/livewire/livewire/src/Mechanisms/FrontendAssets/
    FrontendAssets.php` calls `Illuminate\Support\Facades\Vite::cspNonce()`, which resolves the
    same `Vite` singleton `App\Http\Middleware\ReportContentSecurityPolicy::handle()` seeds via
    `app(Vite::class)->useCspNonce()` before `$next($request)` runs) — this override applies that
    exact same working mechanism to Filament's own tags, which had no nonce of their own.

    `App\Http\Middleware\ReportContentSecurityPolicy`'s `style-src-elem`/`script-src` both carry a
    nonce with NO `'unsafe-inline'` fallback (nonce presence disables that fallback per spec), so
    any `<style>`/`<script>` tag without the matching nonce is blocked outright. This file's
    `<style>` block below defines Filament's `--fi-color-primary-*`/`--danger-*`/etc. CSS custom
    properties (via `AssetManager::renderStyles()` -> `@filamentStyles`, on EVERY panel page) —
    without it, every `bg-{color}`/`text-{color}` utility that reads those properties resolves to
    nothing, rendering primary-colored buttons (including the login submit button) as invisible
    white-on-transparent. That was the real, confirmed production regression this override fixes.

    Vendored from filament/filament v5.7.3's `vendor/filament/support/resources/views/
    assets.blade.php`, content otherwise byte-for-byte identical — only the two `nonce="..."`
    attributes below were added. MUST be re-diffed against the installed version's copy of this
    file on every `filament/support` upgrade (`composer.lock` "filament/filament" version), since
    Filament could add a new inline tag here (or elsewhere — see the two sibling overrides in
    `resources/views/vendor/filament-panels/`) that this override would not know to fix.
--}}
@if (isset($data))
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        window.filamentData = @js($data)
    </script>
@endif

@foreach ($assets as $asset)
    @if (! $asset->isLoadedOnRequest())
        {{ $asset->getHtml() }}
    @endif
@endforeach

<style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    :root {
        @foreach ($cssVariables ?? [] as $cssVariableName => $cssVariableValue) --{{ $cssVariableName }}:{{ $cssVariableValue }}; @endforeach
    }

    @foreach ($customColors ?? [] as $customColorName => $customColorShades) .fi-color-{{ $customColorName }} { @foreach ($customColorShades as $customColorShade) --color-{{ $customColorShade }}:var(--{{ $customColorName }}-{{ $customColorShade }}); @endforeach } @endforeach
</style>
