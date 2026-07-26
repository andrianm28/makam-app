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
 * No seeded price data exists for this module to version yet — see
 * `2026_07_26_180400_create_price_versions_table.php`'s own doc block for
 * why. This Action exists so the versioning MECHANISM is correct and
 * tested; a later batch's admin editor is where a real operator enters a
 * real Rupiah amount.
 *
 * Audited via `Audit::record()` but not `SensitiveActions`-listed — see
 * `App\Domain\ServiceCatalog\ServiceCatalogAuditActions`'s own doc block.
 * (`App\Platform\Audit\SensitiveActions::ACTIONS` already lists
 * `TARIFF_SOURCE_CHANGE` for the unrelated grave-renewal tariff-source
 * concept — this catalogue price-versioning action is a distinct concept
 * from that one and is not added to it.)
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
        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw new InvalidArgumentException('Price version amount must be a positive numeric value.');
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
