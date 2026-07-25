<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate\Contracts;

use App\Platform\FeatureGate\GateRegistrySnapshot;

/**
 * A source that can produce one `GateRegistrySnapshot` for the current
 * request/job. `FeatureGateResolver` depends on this interface, not on
 * `EloquentGateRegistrySource` directly, so a test can substitute an
 * in-memory source without touching the database — see
 * `tests/Unit/Platform/FeatureGate/GateRegistrySnapshotTest.php` for the
 * deny-by-default proof built exactly that way.
 *
 * Implementations MUST NOT throw. A load failure is itself a
 * "gate row that fails to load" case this batch's brief names explicitly
 * as a deny-by-default trigger — the safe behaviour is an empty snapshot
 * (every gate resolves unknown -> closed), not an uncaught exception that
 * could take down an otherwise-unrelated page. See
 * `EloquentGateRegistrySource::load()` for where that catch lives.
 */
interface GateRegistrySource
{
    public function load(): GateRegistrySnapshot;
}
