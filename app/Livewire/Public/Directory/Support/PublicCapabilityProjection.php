<?php

declare(strict_types=1);

namespace App\Livewire\Public\Directory\Support;

use App\Domain\CemeteryCapability\Actions\ResolveCemeteryCapabilityProfile;
use App\Domain\CemeteryCapability\BookingMode;
use App\Domain\CemeteryCapability\MapMode;
use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryDirectory\Models\Cemetery;

/**
 * The AC12 public projection of a cemetery capability profile — Sprint 4
 * S4-T6, `.kiro/specs/cemetery-directory-and-availability` AC12 ("THE
 * SYSTEM SHALL render only active capabilities in the public UI") and its
 * negative criterion "No public exposure of restricted plot data."
 *
 * ---------------------------------------------------------------------------
 * An ALLOWLIST expressed as class structure, not as a filter step
 * ---------------------------------------------------------------------------
 * `CemeteryCapabilityProfile` carries SIX modes. `docs/contracts/
 * openapi.yaml`'s `PublicCapabilityProfile` schema exposes exactly FOUR:
 * `availability_mode`, `booking_mode`, `map_mode`, `visitation_mode`.
 * `registry_mode` and `certificate_mode` are deliberately absent from that
 * already-committed external contract, and this class is the boundary that
 * keeps them absent from the public UI too.
 *
 * The guarantee is STRUCTURAL rather than procedural: this class has no
 * property, accessor, or array key for `registry_mode`/`certificate_mode`
 * at all, and `$profile` is consumed inside `from()` and never retained.
 * So there is no code path — not a future `@foreach` over the model's
 * attributes, not a stray `{{ $profile->registry_mode }}`, not a
 * `->toArray()` on the Eloquent model — by which a restricted mode could
 * reach a Blade view that was handed one of these. A "filter the six down
 * to four" helper would leave the other two one property access away; this
 * does not. Same instinct as `App\Platform\FeatureGate`'s resolver having
 * no request-shaped dependency at all (see
 * `tests/Feature/FeatureGate/ClientSideTamperingCannotOpenAGateTest.php`'s
 * `ReflectionClass` walk, which this class's own test mirrors).
 *
 * Public Livewire components MUST hand Blade one of these, never a raw
 * `CemeteryCapabilityProfile`.
 *
 * ---------------------------------------------------------------------------
 * Why this lives under `App\Livewire\Public\Directory\Support\` and not in
 * `app/Domain/CemeteryCapability/`
 * ---------------------------------------------------------------------------
 * S4-T6's batch scope covers `app/Livewire/Public/Directory/**` and its
 * views; `app/Domain/CemeteryCapability/**` is owned elsewhere and was
 * explicitly to be consumed as-is, not extended. A public-UI projection is
 * genuinely a boundary concern (it is defined by `openapi.yaml`'s public
 * schema, not by the domain's own invariants), so this placement is
 * defensible on its own merits — but it is worth flagging that if a future
 * batch adds a public JSON API for `/pemakaman`, THAT serializer and this
 * class must not drift into two competing definitions of "the four public
 * modes". At that point the right move is to promote this class into
 * `app/Domain/CemeteryCapability/` and have both read it, rather than
 * copying the four names a second time (`AGENTS.md` §Documentation).
 */
final readonly class PublicCapabilityProjection
{
    /**
     * The four keys `docs/contracts/openapi.yaml`'s
     * `PublicCapabilityProfile` schema defines, in that schema's own order.
     * Transcribed from the contract, not invented here — the accompanying
     * test parses the YAML and asserts this constant still matches it, so
     * a contract change cannot silently diverge from this class.
     *
     * @var list<string>
     */
    public const array PUBLIC_KEYS = [
        'availability_mode',
        'booking_mode',
        'map_mode',
        'visitation_mode',
    ];

    private function __construct(
        public string $availabilityMode,
        public string $bookingMode,
        public string $mapMode,
        public string $visitationMode,
    ) {}

    /**
     * Resolve a cemetery's current capability profile and project it, in one
     * call — the ONLY way a public screen should obtain capability
     * information.
     *
     * This deliberately lives here and NOT on `App\Domain\CemeteryDirectory\
     * CemeteryPublicQuery` alongside every other public cemetery read. The
     * projection is a UI-boundary concern defined by `docs/contracts/
     * openapi.yaml`'s `PublicCapabilityProfile` schema rather than by the
     * domain's own invariants, so a domain query class returning one would
     * make `App\Domain\**` depend on `App\Livewire\**` — precisely the
     * inversion `AGENTS.md` §Architecture forbids, and the one the
     * S4-T6/S4-T7 query-class merge exists to eliminate. Keeping it here
     * costs one extra import at each call site and keeps the dependency
     * pointing the right way.
     *
     * AC4's safe-default fallback for a cemetery with no current profile is
     * `ResolveCemeteryCapabilityProfile`'s job, not this method's — so
     * "missing profile" is already handled before the projection happens,
     * and there is only ever one definition of "safe default" in the
     * codebase (`CemeteryCapabilityProfile::safeDefaults()`).
     */
    public static function forCemetery(Cemetery $cemetery): self
    {
        return self::from((new ResolveCemeteryCapabilityProfile)($cemetery));
    }

    /**
     * Projects a resolved profile down to its four public modes.
     *
     * `$profile` is expected to come from `App\Domain\CemeteryCapability\
     * Actions\ResolveCemeteryCapabilityProfile`, which already guarantees
     * AC4's safe defaults for a cemetery with no current profile row — this
     * class deliberately does not re-implement that fallback, so there is
     * only ever one definition of "safe default" in the codebase
     * (`CemeteryCapabilityProfile::safeDefaults()`).
     */
    public static function from(CemeteryCapabilityProfile $profile): self
    {
        return new self(
            availabilityMode: (string) $profile->availability_mode,
            bookingMode: (string) $profile->booking_mode,
            mapMode: (string) $profile->map_mode,
            visitationMode: (string) $profile->visitation_mode,
        );
    }

    /**
     * True when this cemetery publishes a block- or plot-level layout.
     *
     * design-system.md §3.7 forbids a view switching on an enum string, and
     * the directory views state that rule for themselves at the top of each
     * file. These named predicates are how they keep it: Blade asks a
     * question in domain language, and the one comparison against
     * `MapMode`'s closed list lives here. Adding
     * `@if ($capabilities->mapMode === '...')` to a view instead is the
     * regression this method exists to prevent.
     *
     * `LOCATION_ONLY` — the safe default, and the only value any seeded
     * cemetery carries today — returns false, which is what drives the
     * detail page's §6.4 "plot layout is not public for this cemetery"
     * explanation. That explanation is deliberate: silently rendering
     * nothing there would leave a family unable to tell "we do not publish
     * this" from "this cemetery has no plots".
     */
    public function hasPublicPlotMap(): bool
    {
        return in_array($this->mapMode, [MapMode::BLOCK_MAP, MapMode::PLOT_MAP], true);
    }

    /**
     * True when booking here means "submit a request, an operator confirms
     * out of band" — `AGENTS.md` §Domain and financial invariants makes
     * this the default ("Package/class confirmation is default"), and it is
     * the only mode any seeded cemetery carries today.
     *
     * Drives the detail page's §6.7 pending copy: what is being waited on,
     * who acts next.
     */
    public function isRequestConfirmationBooking(): bool
    {
        return $this->bookingMode === BookingMode::REQUEST_CONFIRMATION;
    }

    /**
     * The projection as the exact wire shape `openapi.yaml`'s
     * `PublicCapabilityProfile` describes — four keys, no more.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'availability_mode' => $this->availabilityMode,
            'booking_mode' => $this->bookingMode,
            'map_mode' => $this->mapMode,
            'visitation_mode' => $this->visitationMode,
        ];
    }
}
