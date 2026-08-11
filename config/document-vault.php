<?php

declare(strict_types=1);

use App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage;
use App\Platform\DocumentVault\Adapters\MockScanner;
use App\Platform\DocumentVault\DocumentKind;

$isDevelopmentEnvironment = in_array(
    env('APP_ENV', 'production'),
    ['local', 'development', 'testing'],
    true,
);

$configuredObjectStorage = env('DOCUMENT_VAULT_OBJECT_STORAGE');
$configuredMalwareScanner = env('DOCUMENT_VAULT_MALWARE_SCANNER');

// Mock scanning is a development/testing convenience, never a staging or
// production safety boundary. Treat an explicit non-development mock value as
// unavailable so the provider fails closed instead of booting with it.
if (! $isDevelopmentEnvironment && $configuredMalwareScanner === MockScanner::class) {
    $configuredMalwareScanner = null;
}

/**
 * Platform Document Vault configuration.
 *
 * `scanner_outage` is the deterministic switch `Adapters\MockScanner` reads
 * to simulate a real scanner being unavailable (AC4 fail-closed,
 * `file-upload-pipeline.md` §7: "Scanner unavailable: keep in quarantine").
 * It is read via `config('document-vault.scanner_outage')`, never a raw
 * `env()` call at scan time, so the value survives `config:cache`
 * (`docs/operations/ci-cd-and-release.md` config-caching rule) — `env()` is
 * only called here, at config-load time. Added in Task 3
 * (`task-3-brief.md` ambiguity ruling 5).
 *
 * `object_storage`/`malware_scanner` are the class names
 * `Providers\DocumentVaultServiceProvider` binds `Contracts\ObjectStorage`/
 * `Contracts\MalwareScanner` to (Task 4, `task-4-brief.md` ambiguity ruling
 * 3) — this file's own previous doc block said Task 8 would add this;
 * ruling 3 explicitly brought it forward to Task 4 instead so
 * `UploadDocument`'s container-resolved dependencies are wired from day
 * one. A later production swap (Task 8, ADR-0033 provider-neutrality
 * precedent — e.g. a real S3-compatible adapter/scanner) is a config change
 * only, never a code change to the provider or to `UploadDocument` itself.
 * Same `env()`-at-load-time-only rule as `scanner_outage` above applies.
 */
return [
    'scanner_outage' => (bool) env('DOCUMENT_VAULT_SCANNER_OUTAGE', false),

    // Defaults are configuration-overridable policy values, expressed in days
    // from logical deletion. Holds always take precedence over this clock.
    'retention_days' => [
        DocumentKind::Ktp->value => 3650,
        DocumentKind::Kk->value => 3650,
        DocumentKind::DeathCertificate->value => 3650,
        DocumentKind::PaymentProof->value => 2555,
        DocumentKind::Agreement->value => 2555,
        DocumentKind::Certificate->value => 2555,
        DocumentKind::VendorEvidence->value => 1095,
        DocumentKind::ProductImage->value => 365,
        DocumentKind::GraveImport->value => 365,
    ],

    // Local/mock defaults are intentionally limited to development-like
    // environments. Staging and production must name their providers
    // explicitly or the service provider fails closed during boot.
    'object_storage' => $configuredObjectStorage ?? ($isDevelopmentEnvironment ? LocalFilesystemObjectStorage::class : null),

    'malware_scanner' => $configuredMalwareScanner ?? ($isDevelopmentEnvironment ? MockScanner::class : null),
];
