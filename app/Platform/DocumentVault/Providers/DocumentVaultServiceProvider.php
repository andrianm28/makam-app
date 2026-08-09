<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Providers;

use App\Platform\DocumentVault\Contracts\MalwareScanner;
use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\Contracts\StoragePathResolver;
use App\Platform\DocumentVault\StoragePathPolicy;
use Illuminate\Support\ServiceProvider;

/**
 * Wires this lane's `DocumentVault` bindings: `ObjectStorage` ->
 * whatever `config('document-vault.object_storage')` names (the combined
 * dev/staging host: `Adapters\LocalFilesystemObjectStorage`),
 * `MalwareScanner` -> `config('document-vault.malware_scanner')`
 * (`Adapters\MockScanner`), and `StoragePathResolver` ->
 * `StoragePathPolicy` (Task 3's single implementation — see that class's
 * own doc block). The first two are config-driven rather than hardcoded
 * here so a later production swap (Task 8, ADR-0033 provider-neutrality
 * precedent) is a config change only (`task-4-brief.md` ambiguity ruling
 * 3) — this class never names `LocalFilesystemObjectStorage`/`MockScanner`
 * directly.
 *
 * ---------------------------------------------------------------------------
 * ServiceProvider path deviation (`task-4-brief.md` ambiguity ruling 1)
 * ---------------------------------------------------------------------------
 * The plan text names `DocumentVaultServiceProvider.php` at the module
 * root, but every existing platform module registers its provider under
 * `app/Platform/<Module>/Providers/` (`CorrelationServiceProvider`,
 * `IdentityAccessServiceProvider`, `FeatureGateServiceProvider`) — this
 * class follows that house style instead, per the coordinator's explicit
 * ruling recorded in `task-4-brief.md`'s ambiguity resolutions. A
 * documented deviation from the plan's literal file path, not a conflict.
 *
 * ---------------------------------------------------------------------------
 * `bootstrap/providers.php` registration (`task-4-brief.md` ambiguity
 * ruling 2)
 * ---------------------------------------------------------------------------
 * Registered there with a one-line addition, following the same precedent
 * `IdentityAccessServiceProvider`/`CorrelationServiceProvider` already
 * established for a provider not in a batch's literal owned-files list —
 * see their own class doc blocks for the identical reasoning. Explicitly
 * authorized by the coordinator for this task; without that line, this
 * entire class (and everything it binds) is dead code no consumer can
 * reach.
 */
final class DocumentVaultServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ObjectStorage::class, config('document-vault.object_storage'));
        $this->app->bind(MalwareScanner::class, config('document-vault.malware_scanner'));
        $this->app->bind(StoragePathResolver::class, StoragePathPolicy::class);
    }
}
