<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Models;

use App\Domain\ServiceCatalog\Exceptions\PriceVersionIsAppendOnlyException;
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
 *
 * ---------------------------------------------------------------------------
 * ENFORCEMENT ADDED 09 Aug 2026 (ServiceCatalog Superpowers retrofit, F26)
 * ---------------------------------------------------------------------------
 * Until then the "append-only" paragraph above — and
 * `2026_07_26_180400_create_price_versions_table.php:55-56`'s "never delete or
 * renumber a version after the fact" — were claims with nothing behind them:
 * this class defined no `booted()` of any kind, so
 * `$old->forceFill(['amount' => '1'])->save()` and `$old->delete()` both
 * simply worked. `booted()` below closes exactly the gap that paragraph
 * describes and no more:
 *
 * - INSERT is always allowed (that is what "append" means).
 * - UPDATE is allowed only for the single legal mutation the paragraph names:
 *   `superseded_at` alone, and only from `null` to a value. `Actions\
 *   RecordServiceDefinitionPriceVersion` does exactly that
 *   (`forceFill(['superseded_at' => $now])->save()`) and is unaffected.
 *   Anything else — an `amount` rewrite, a `version_number` renumber, a
 *   `superseded_at` un-stamp, even a no-op save — throws.
 * - DELETE always throws.
 *
 * This is the property AC3's price snapshot depends on: a quote references a
 * price version, and `design.md` §Consumption boundary says the quote holds
 * the OLD snapshot, so a historical amount that can be rewritten in place is
 * not a snapshot at all (`AGENTS.md` §Domain and financial invariants).
 *
 * Eloquent-level only, the same limit `App\Domain\Faq\Models\
 * FaqArticleVersion`'s equivalent guard discloses: a raw
 * `DB::table('price_versions')->update(...)` and a builder-level mass
 * `PriceVersion::query()->...->update(...)`/`->delete()` both still bypass
 * it. That is deliberate and load-bearing here — the dev-data seed migration
 * (`2026_07_26_220000_...`) inserts through `DB::table()`, and existing tests
 * clear/supersede seeded rows through the builder. The database-level close
 * is ledgered with F11/F12, not attempted here.
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

    protected static function booted(): void
    {
        self::saving(function (self $priceVersion): void {
            if (! $priceVersion->exists) {
                return;
            }

            $dirty = array_keys($priceVersion->getDirty());

            $isTheOneLegalUpdate = $dirty === ['superseded_at']
                && $priceVersion->getOriginal('superseded_at') === null;

            if (! $isTheOneLegalUpdate) {
                throw PriceVersionIsAppendOnlyException::forUpdate($priceVersion->getKey(), $dirty);
            }
        });

        self::deleting(function (self $priceVersion): void {
            throw PriceVersionIsAppendOnlyException::forDelete($priceVersion->getKey());
        });
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
