<?php

declare(strict_types=1);

/**
 * Platform Document Vault configuration (Task 3 scope only —
 * `task-3-brief.md` ambiguity ruling 5).
 *
 * `scanner_outage` is the deterministic switch `Adapters\MockScanner` reads
 * to simulate a real scanner being unavailable (AC4 fail-closed,
 * `file-upload-pipeline.md` §7: "Scanner unavailable: keep in quarantine").
 * It is read via `config('document-vault.scanner_outage')`, never a raw
 * `env()` call at scan time, so the value survives `config:cache`
 * (`docs/operations/ci-cd-and-release.md` config-caching rule) — `env()` is
 * only called here, at config-load time.
 *
 * Task 8 extends this file with the storage/scanner provider binding
 * config (which `ObjectStorage`/`MalwareScanner` implementation the
 * container resolves); nothing here anticipates that yet.
 */
return [
    'scanner_outage' => (bool) env('DOCUMENT_VAULT_SCANNER_OUTAGE', false),
];
