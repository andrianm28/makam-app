<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Actions;

use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCatalogAuditActions;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
 */
final readonly class RecordServiceDefinitionPriceVersion
{
    public function __invoke(
        ServiceDefinition $serviceDefinition,
        string $amount,
        int|string $actorReference,
        string $currency = 'IDR',
        ?string $source = null,
        string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
        ?string $reason = null,
    ): PriceVersion {
        // Shape assertion, not `is_numeric()` — see this class's own doc
        // block. At most 10 integer digits and at most 2 fractional digits
        // is exactly `decimal(12,2)`'s domain, so a value that passes here
        // reaches the column without the database silently changing it.
        if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount) !== 1) {
            throw new InvalidArgumentException(
                'Price version amount must be a plain decimal string with at most 10 integer digits '.
                "and at most 2 fractional digits — the decimal(12,2) column's exact domain. ".
                "Got [{$amount}]."
            );
        }

        // Zero rejected WITHOUT casting to float: given the shape above, a
        // string of nothing but zeros and an optional decimal point is the
        // only zero it admits.
        if (ltrim(str_replace('.', '', $amount), '0') === '') {
            throw new InvalidArgumentException('Price version amount must be greater than zero.');
        }

        if (trim($currency) === '') {
            throw new InvalidArgumentException('Price version currency must not be blank.');
        }

        return DB::transaction(function () use ($serviceDefinition, $amount, $currency, $source, $actorReference, $actorRole, $auditSource, $reason): PriceVersion {
            /** @var ServiceDefinition $serviceDefinition */
            $serviceDefinition = ServiceDefinition::query()->lockForUpdate()->findOrFail($serviceDefinition->id);

            $current = $serviceDefinition->priceVersions()->whereNull('superseded_at')->lockForUpdate()->first();
            $nextVersionNumber = ((int) $serviceDefinition->priceVersions()->max('version_number')) + 1;
            $now = CarbonImmutable::now();

            if ($current !== null) {
                $current->forceFill(['superseded_at' => $now])->save();
            }

            $priceVersion = PriceVersion::create([
                'priceable_type' => ServiceDefinition::class,
                'priceable_id' => $serviceDefinition->id,
                'version_number' => $nextVersionNumber,
                'amount' => $amount,
                'currency' => strtoupper(trim($currency)),
                'source' => $source,
                'effective_from' => $now,
                'superseded_at' => null,
                'recorded_by' => (string) $actorReference,
            ]);

            Audit::record(
                action: ServiceCatalogAuditActions::PRICE_VERSION_RECORDED,
                subject: new AuditSubject('service_definition', $serviceDefinition->id, $nextVersionNumber),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: $actorRole,
                source: $auditSource,
                reason: $reason,
                metadata: ['note' => "Recorded price version {$nextVersionNumber}."],
            );

            return $priceVersion;
        });
    }
}
