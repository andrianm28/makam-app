<?php

declare(strict_types=1);

namespace App\Livewire\Public\Directory;

use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Directory\Support\PublicCapabilityProjection;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Throwable;

/**
 * The public TPU/TPS detail page — Sprint 4 S4-T6,
 * `.kiro/specs/cemetery-directory-and-availability` AC3, AC4, AC5, AC6,
 * AC11, AC12. Screen PUB-011's "detail" state.
 *
 * Route: registered by `routes/web.php` under the name `cemeteries.show`
 * with a `{cemeterySlug}` path parameter (this batch does not own that
 * file). The slug — not the UUID — is the public identifier, matching
 * `/faq/{articleSlug}`'s established shape; `docs/contracts/openapi.yaml`
 * fixes `format: uuid` for the JSON API's `/cemeteries/{cemeteryId}` path,
 * which is a separate contract for a separate consumer and is not changed
 * by this choice.
 *
 * ---------------------------------------------------------------------------
 * 404 discipline
 * ---------------------------------------------------------------------------
 * `abort(404)` for both "no such cemetery" and "cemetery exists but is not
 * published". The two are indistinguishable from outside — a different
 * status or message would leak the existence of an unpublished record. Same
 * rule as `public-faq` AC6, and `CemeteryPublicQuery::
 * findPublishedBySlug()` is what makes it structural (it cannot report the
 * difference, because it never learns it).
 *
 * ---------------------------------------------------------------------------
 * AC12 — the projection is resolved HERE, and the view never sees a profile
 * ---------------------------------------------------------------------------
 * `render()` hands the view a `PublicCapabilityProjection`, never a
 * `CemeteryCapabilityProfile`. `registry_mode` and `certificate_mode` are
 * absent from `openapi.yaml`'s `PublicCapabilityProfile` schema and are
 * therefore absent from the projection class entirely — see that class's
 * doc block for why this is a structural guarantee rather than a filtering
 * step, and `PublicCapabilityProjectionTest` for the reflection walk that
 * asserts it stays one.
 */
final class CemeteryDetail extends Component
{
    public string $cemeterySlug = '';

    /**
     * §6.5 — capability resolution failed and AC4's safe defaults were
     * substituted. The page still renders every AC3 field; it just says
     * plainly that the availability picture is degraded rather than
     * presenting a fallback as if it were the operator's own answer.
     */
    public bool $capabilitiesDegraded = false;

    /**
     * §6.5 — the package/class list could not be read. Distinct from "this
     * cemetery has no packages configured", which is an ordinary empty
     * state (most seeded cemeteries are in it) and must not be reported as
     * a failure.
     */
    public bool $packagesUnavailable = false;

    public function mount(string $cemeterySlug): void
    {
        $this->cemeterySlug = $cemeterySlug;

        if (CemeteryPublicQuery::findPublishedBySlug($cemeterySlug) === null) {
            abort(404);
        }
    }

    public function render(): View
    {
        $cemetery = CemeteryPublicQuery::findPublishedBySlug($this->cemeterySlug);

        if ($cemetery === null) {
            // Re-checked on every render, not just in mount(): a cemetery
            // unpublished between the initial GET and a subsequent Livewire
            // request must stop being visible immediately, not at the next
            // full page load.
            abort(404);
        }

        $this->capabilitiesDegraded = false;
        $this->packagesUnavailable = false;

        try {
            $capabilities = PublicCapabilityProjection::forCemetery($cemetery);
        } catch (Throwable $e) {
            report($e);

            $this->capabilitiesDegraded = true;
            // tasks.md, PUB-011 detail: "capability resolution failure falls
            // back to safe defaults (AC4), not a blank page." The safe
            // defaults are read from the one place that defines them
            // (`CemeteryCapabilityProfile::safeDefaults()`) rather than
            // restated here, then projected through the same AC12 allowlist
            // as any other profile — a degraded page must not become a hole
            // in the projection.
            $capabilities = PublicCapabilityProjection::from(
                new CemeteryCapabilityProfile(CemeteryCapabilityProfile::safeDefaults())
            );
        }

        /** @var Collection<int, CemeteryPackage> $packages */
        $packages = new Collection;

        try {
            $packages = CemeteryPublicQuery::activePackages($cemetery);
        } catch (Throwable $e) {
            report($e);

            $this->packagesUnavailable = true;
        }

        return view('livewire.public.directory.detail', [
            'cemetery' => $cemetery,
            'capabilities' => $capabilities,
            'packages' => $packages,
        ])->layout('layouts.app', [
            'title' => $this->titleFor($cemetery),
            // See CemeteryDirectoryIndex::render()'s note — the directory is
            // not one of <x-mk.header>'s four nav keys.
            'active' => null,
        ]);
    }

    private function titleFor(Cemetery $cemetery): string
    {
        return $cemetery->name.' - Makam.co.id';
    }
}
