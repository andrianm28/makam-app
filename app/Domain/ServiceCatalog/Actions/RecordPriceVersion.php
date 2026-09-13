<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Actions;

use App\Domain\ServiceCatalog\Contracts\Priceable;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records one new price version for any priceable, superseding its incumbent
 * inside the same transaction.
 *
 * ---------------------------------------------------------------------------
 * Where this came from, and what is deliberately unchanged
 * ---------------------------------------------------------------------------
 * Every line of validation and every step of the write below is lifted
 * verbatim from `RecordServiceDefinitionPriceVersion`, which has been the only
 * price-recording path since `price_versions` was created and is covered by 29
 * existing test methods. That action now delegates here and keeps its own
 * public signature, so those tests prove this engine rather than merely
 * tolerating it.
 *
 * The migration that created `price_versions` anticipated exactly this moment:
 * "a package-priceable caller may create rows directly the same way". Rather
 * than a second caller writing rows "the same way" by hand — which is how two
 * implementations of one financial invariant start drifting — there is now one
 * engine and thin, type-named wrappers around it.
 *
 * ---------------------------------------------------------------------------
 * Why the audit action and subject type are parameters
 * ---------------------------------------------------------------------------
 * `SensitiveActions` keys a mandatory-reason requirement off the action NAME.
 * If every priceable shared one generic name, an operator reviewing the audit
 * trail could not tell a service-fee change from a grave-package price change
 * without joining to another table — and those are very different events. So
 * each wrapper passes its own domain-qualified name, and each name is listed
 * in `SensitiveActions::ACTIONS` independently.
 *
 * The same reasoning applies to `$subjectType`: the audit row names the kind
 * of thing repriced, in the vocabulary the rest of that domain already uses.
 *
 * ---------------------------------------------------------------------------
 * Append-only, and the one lock that makes it true
 * ---------------------------------------------------------------------------
 * A priceable must never have two rows with `superseded_at IS NULL`. That is
 * held by locking the priceable row AND the incumbent price row before reading
 * the next version number, so two concurrent repricings serialise instead of
 * both computing the same number and racing the unique index
 * (`price_versions_priceable_version_unique`). The loser waits, re-reads, and
 * gets the next number — rather than failing with a constraint violation an
 * operator would have to interpret.
 */
final readonly class RecordPriceVersion
{
    /**
     * @param  Priceable  $priceable  the thing being priced
     * @param  string  $amount  plain decimal string — `decimal(12,2)`'s exact domain
     * @param  string  $auditAction  domain-qualified, and listed in `SensitiveActions::ACTIONS`
     * @param  string  $subjectType  the audit subject's type, in its own domain's vocabulary
     */
    public function __invoke(
        Priceable $priceable,
        string $amount,
        string $auditAction,
        string $subjectType,
        int|string $actorReference,
        string $reason,
        string $currency = 'IDR',
        ?string $source = null,
        string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
    ): PriceVersion {
        // Shape assertion, not `is_numeric()`. At most 10 integer digits and
        // at most 2 fractional digits is exactly `decimal(12,2)`'s domain, so
        // a value that passes here reaches the column without the database
        // silently rounding it into something the operator did not type.
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

        // Checked here — before DB::transaction() opens — so a blank reason
        // fails as a plain argument error rather than a half-open transaction
        // that Audit::record() only rejects once already inside it.
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Price version reason must not be blank.');
        }

        return DB::transaction(function () use (
            $priceable, $amount, $currency, $source, $actorReference,
            $actorRole, $auditSource, $reason, $auditAction, $subjectType
        ): PriceVersion {
            /** @var Priceable&Model $locked */
            $locked = $priceable::query()->lockForUpdate()->findOrFail($priceable->getKey());

            $current = $locked->priceVersions()->whereNull('superseded_at')->lockForUpdate()->first();
            $nextVersionNumber = ((int) $locked->priceVersions()->max('version_number')) + 1;
            $now = CarbonImmutable::now();

            if ($current !== null) {
                $current->forceFill(['superseded_at' => $now])->save();
            }

            $priceVersion = PriceVersion::create([
                'priceable_type' => $locked::class,
                'priceable_id' => $locked->getKey(),
                'version_number' => $nextVersionNumber,
                'amount' => $amount,
                'currency' => strtoupper(trim($currency)),
                'source' => $source,
                'effective_from' => $now,
                'superseded_at' => null,
                'recorded_by' => (string) $actorReference,
            ]);

            Audit::record(
                action: $auditAction,
                subject: new AuditSubject($subjectType, (string) $locked->getKey(), $nextVersionNumber),
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
