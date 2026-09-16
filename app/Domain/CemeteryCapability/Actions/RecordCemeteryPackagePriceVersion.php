<?php

declare(strict_types=1);

namespace App\Domain\CemeteryCapability\Actions;

use App\Domain\CemeteryCapability\CemeteryPackageAuditActions;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\ServiceCatalog\Actions\RecordPriceVersion;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Platform\Audit\AuditSource;

/**
 * Records a grave package's firm, bookable price.
 *
 * ---------------------------------------------------------------------------
 * Why a package needs a firm price at all, when it already has a range
 * ---------------------------------------------------------------------------
 * `cemetery_packages` already carries `price_min`/`price_max`/`price_source`.
 * Those are the INDICATIVE RANGE the public directory shows, under the label
 * "Kisaran indikatif, Perlu konfirmasi" — a marketing figure, deliberately
 * hedged, and correct as such.
 *
 * This is a different thing: the single amount a customer is actually charged.
 * The two are not interchangeable and neither replaces the other. A range
 * cannot be charged, and a charge should not be presented as a range. The
 * range columns are untouched by this action.
 *
 * That distinction is the whole reason this exists. The plan at
 * `docs/superpowers/plans/2026-09-13-bayar-penuh-di-muka-online-saja.md`
 * establishes that the system cannot currently compute what to charge,
 * because the largest component of an order — the plot itself — has no firm
 * price anywhere. This is Stage 0, level 1 of closing that.
 *
 * ---------------------------------------------------------------------------
 * Zero migrations, and why that is not a shortcut
 * ---------------------------------------------------------------------------
 * `price_versions` is polymorphic and its own migration's doc block
 * anticipated this caller: "a package-priceable caller may create rows
 * directly the same way". `cemetery_packages.id` is a bigint, which is what
 * `priceable_id` already holds. So a package becomes priceable by using
 * `Concerns\HasVersionedPrice` — no schema change to a financial append-only
 * table, which is the safest possible shape for a change that touches money.
 *
 * Per-PLOT pricing (the second level the owner asked for) does NOT have this
 * property: `grave_plots.id` is a uuid and will not fit `priceable_id`'s
 * `unsignedBigInteger`. That needs a widening ALTER on `price_versions` and
 * is deliberately a separate, later stage with its own review.
 *
 * ---------------------------------------------------------------------------
 * Unpriced is a real state
 * ---------------------------------------------------------------------------
 * A package with no price version returns `null` from `currentPriceVersion()`,
 * and that is correct for every package in the catalogue today — all seven on
 * beta are unpriced. Callers must refuse to quote rather than treat missing as
 * zero; `ComposeQuoteLinesFromBookingDraft` already sets that precedent by
 * throwing `UnpricedBookingServiceException`.
 */
final readonly class RecordCemeteryPackagePriceVersion
{
    public function __construct(private RecordPriceVersion $recordPriceVersion) {}

    public function __invoke(
        CemeteryPackage $package,
        string $amount,
        int|string $actorReference,
        string $reason,
        string $currency = 'IDR',
        ?string $source = null,
        string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
    ): PriceVersion {
        return ($this->recordPriceVersion)(
            priceable: $package,
            amount: $amount,
            auditAction: CemeteryPackageAuditActions::PRICE_VERSION_RECORDED,
            subjectType: 'cemetery_package',
            actorReference: $actorReference,
            reason: $reason,
            currency: $currency,
            source: $source,
            actorRole: $actorRole,
            auditSource: $auditSource,
        );
    }
}
