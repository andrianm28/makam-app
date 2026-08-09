<?php

declare(strict_types=1);

namespace App\Domain\CemeteryCapability\Actions;

use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Support\Carbon;

/**
 * Resolves "the" current capability profile for a cemetery — requirements.
 * md AC4: "THE SYSTEM SHALL maintain an explicit versioned capability
 * profile for every cemetery. WHEN a capability profile is missing THE
 * SYSTEM SHALL use safe defaults."
 *
 * Read-only: this Action never writes to `cemetery_capability_profiles`.
 * When no current row exists for the given cemetery, it returns an UNSAVED
 * `CemeteryCapabilityProfile` instance (`Model::make()`, not `::create()`)
 * built from `CemeteryCapabilityProfile::safeDefaults()` — a transient
 * fallback VALUE the caller can render/check against, not a phantom row
 * silently written to the database on the caller's behalf without any real
 * evidence backing it. Persisting an actual activated profile is a
 * deliberate admin action with its own evidence trail (owner/evidence/
 * effective date/rollback plan) — out of this batch's S4-T1 (master
 * data/seeds only) scope; a later batch (S4-T6's capability resolver, or
 * `admin-operations`) is expected to add the write-side Action
 * (`ActivateCemeteryCapabilityProfile` or similar) that actually persists a
 * new version and emits `cemetery.capability_changed.v1`
 * (`docs/contracts/event-catalog.md`).
 *
 * `current()` filters on `superseded_at IS NULL` only, and the schema
 * deliberately declines a uniqueness constraint on that predicate — so
 * "current" is not structurally singular and two current rows are possible.
 * `orderByDesc('version_number')` makes the tiebreak deterministic (highest
 * version wins) instead of leaving it to whatever the storage engine
 * returns first, which SQLite and PostgreSQL 18 need not agree on. This is
 * a determinism guarantee, not a correctness one: it does not make two
 * current rows impossible. A partial unique index on
 * `(cemetery_id) WHERE superseded_at IS NULL` is what would, and that is a
 * migration against an already deployed table — human review per `AGENTS.md`
 * §Infrastructure-agent execution, deliberately not done here.
 *
 * `version_number => 0` and `source => 'system:safe-default-fallback'` on
 * the fallback instance mark it, structurally, as not a real activated
 * version — every seeded/activated profile this codebase writes starts at
 * `version_number = 1`.
 */
final readonly class ResolveCemeteryCapabilityProfile
{
    public function __invoke(Cemetery $cemetery): CemeteryCapabilityProfile
    {
        /** @var CemeteryCapabilityProfile|null $current */
        $current = CemeteryCapabilityProfile::query()
            ->where('cemetery_id', $cemetery->id)
            ->current()
            ->orderByDesc('version_number')
            ->first();

        if ($current !== null) {
            return $current;
        }

        return new CemeteryCapabilityProfile(array_merge(
            CemeteryCapabilityProfile::safeDefaults(),
            [
                'cemetery_id' => $cemetery->id,
                'version_number' => 0,
                'source' => 'system:safe-default-fallback',
                'owner' => null,
                'evidence' => null,
                'rollback_plan' => null,
                'effective_at' => Carbon::now(),
                'superseded_at' => null,
            ],
        ));
    }
}
