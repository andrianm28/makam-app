<?php

declare(strict_types=1);

use App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage;
use App\Platform\DocumentVault\Adapters\MockScanner;

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

    'object_storage' => env('DOCUMENT_VAULT_OBJECT_STORAGE', LocalFilesystemObjectStorage::class),

    'malware_scanner' => env('DOCUMENT_VAULT_MALWARE_SCANNER', MockScanner::class),
];
