<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Actions;

use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCatalogAuditActions;
use App\Platform\Audit\AuditSource;

/**
 * Records a new current price for a `service_definitions` row — the ONLY
 * way this module versions a service's price. `service-catalog.md`
 * "Catalog rules": "Price is versioned and snapshot into quote/order."
 *
 * Closes out whichever `price_versions` row is currently current for this
 * service (`superseded_at` set to now) and inserts the new one as the new
 * current version (`superseded_at` still `null`), inside one transaction —
 * a service never has more than one row with `superseded_at IS NULL` at a
 * time.
 *
 * `$amount` is accepted as a numeric string (not `float`), the same reason
 * `decimal` is the column type: floating-point currency arithmetic is a
 * known source of silent rounding bugs (Rupiah amounts here have no
 * fractional subunit in practice, but the column/parameter still avoid
 * float to stay correct if that ever changes).
 *
 * The amount guard is a SHAPE assertion (`/^\d{1,10}(\.\d{1,2})?$/`), not
 * `is_numeric()`. Corrected 09 Aug 2026 by the ServiceCatalog Superpowers
 * retrofit (F7): `is_numeric()` admitted four classes of value the
 * `decimal(12,2)` column cannot hold as written, and every one of them
 * changed a MONEY value with no error and no trace — the audit `metadata`
 * below carries only a sentence, never the amount. `'100.999'` was accepted
 * and silently rounded by the database to `101.00`; `' 5000'` (leading
 * whitespace) and `'1e9'` (exponent notation) were accepted; and
 * `'99999999999.99'` (11 integer digits) reached the column and raised a raw
 * `QueryException` rather than a domain error. The shape check closes all
 * four in one guard and keeps the value a `string` end to end — no `float`
 * appears anywhere on this write path (`AGENTS.md` §Domain and financial
 * invariants).
 *
 * Every catalogue code already carries a v1 price:
 * `2026_07_26_220000_seed_service_definition_dummy_operational_data.php`
 * seeds one DEV-ONLY placeholder `price_versions` row per code (that
 * migration's own doc block carries the "not real catalogue pricing"
 * disclaimer), so this Action records the NEXT version on top of that
 * baseline rather than a service's first-ever price. This Action exists so
 * the versioning MECHANISM is correct and tested; a later batch's admin
 * editor is where a real operator enters a real Rupiah amount. (This doc
 * block previously read "No seeded price data exists for this module to
 * version yet", citing the `180400` migration's own since-corrected claim as
 * its authority; both corrected 09 Aug 2026 by the ServiceCatalog
 * Superpowers retrofit, F9.)
 *
 * Audited via `Audit::record()` and listed in `SensitiveActions` under the
 * emitted domain-qualified action name. (`App\Platform\Audit\SensitiveActions::ACTIONS`
 * already lists `TARIFF_SOURCE_CHANGE` for the unrelated grave-renewal
 * tariff-source concept; this catalogue price-versioning action is distinct
 * and has its own explicit sensitive action.)
 *
 * `$reason` is a REQUIRED, non-blank `string` — not the `?string = null`
 * this signature carried before 10 Aug 2026. `SensitiveActions::ACTIONS`
 * lists both `PRICE_VERSION_RECORDED` and this Action's actually-emitted
 * `ServiceCatalogAuditActions::PRICE_VERSION_RECORDED` value
 * (`SERVICE_DEFINITION_PRICE_VERSION_RECORDED`), so `Audit::record()` already
 * required a non-empty reason here — it just enforced that at runtime, from
 * inside this method's own `DB::transaction()`, against every caller that
 * omitted one. Requiring it at the signature and rejecting a blank one
 * before the transaction opens (see the guard below, same shape as the
 * `$currency` blank check) turns that failure into a plain argument error
 * instead of a half-open transaction. (Baseline repair, Task 2R, 10 Aug
 * 2026 — closes a regression this lane's own Task 1 introduced when it
 * added this action to `SensitiveActions::ACTIONS` without updating this
 * signature to match.)
 */
final readonly class RecordServiceDefinitionPriceVersion
{
    public function __invoke(
        ServiceDefinition $serviceDefinition,
        string $amount,
        int|string $actorReference,
        string $reason,
        string $currency = 'IDR',
        ?string $source = null,
        string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
    ): PriceVersion {
        // Delegates to `RecordPriceVersion`, which is this method's own former
        // body moved verbatim — same validation, same locking, same audit
        // shape. This wrapper survives rather than being deleted because its
        // typed `ServiceDefinition` signature is what 29 existing test methods
        // and every panel call site already speak, and because the audit
        // action name below is service-specific and must stay that way.
        return app(RecordPriceVersion::class)(
            priceable: $serviceDefinition,
            amount: $amount,
            auditAction: ServiceCatalogAuditActions::PRICE_VERSION_RECORDED,
            subjectType: 'service_definition',
            actorReference: $actorReference,
            reason: $reason,
            currency: $currency,
            source: $source,
            actorRole: $actorRole,
            auditSource: $auditSource,
        );
    }
}
