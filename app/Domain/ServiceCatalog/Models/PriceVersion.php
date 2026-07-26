<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Eloquent model for `price_versions` — see the migration
 * (`2026_07_26_180400_create_price_versions_table.php`) for schema
 * reasoning, in particular why this is a single polymorphic (`morphTo`)
 * table shared by `Models\ServiceDefinition` and `Models\
 * ServicePackageVersion` rather than two separate tables.
 *
 * Append-only: written only by `Actions\
 * RecordServiceDefinitionPriceVersion` (today; a future package-priceable
 * caller may create rows directly the same way), one row per recorded
 * price, never updated afterward except to stamp `superseded_at` when the
 * NEXT version for the same priceable is recorded — mirrors this
 * codebase's established append-only-log pattern
 * (`App\Domain\Faq\Models\FaqArticleVersion`, `App\Platform\Audit\Models\
 * AuditEvent`, `App\Platform\IdentityAccess\Mfa\Models\MfaChallenge`).
 * `$timestamps = false` — `effective_from` is the explicit, server-set
 * "recorded at" column, same choice `FaqArticleVersion` makes for the
 * identical reason.
 *
 * `priceable_type` stores the fully-qualified class name (no
 * `Relation::morphMap()` is registered) — this codebase has no existing
 * morph-map convention to follow (this is its first polymorphic relation),
 * and registering one means editing a shared service provider
 * (`bootstrap/providers.php`), which is outside this batch's scope (`app/
 * Domain/ServiceCatalog/**`, migrations, and tests only). A later batch
 * introducing more polymorphic relations across modules is the right place
 * to add a project-wide morph map, at which point existing `priceable_type`
 * rows would need a one-time backfill.
 */
final class PriceVersion extends Model
{
    protected $table = 'price_versions';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'priceable_type',
        'priceable_id',
        'version_number',
        'amount',
        'currency',
        'source',
        'effective_from',
        'superseded_at',
        'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'amount' => 'decimal:2',
            'effective_from' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Polymorphic — either a `ServiceDefinition` or a `ServicePackageVersion`
     * (see this class's own doc block). No single concrete class to narrow
     * to statically; `Model` is the correct, honest generic here.
     *
     * @return MorphTo<Model, $this>
     */
    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }
}
