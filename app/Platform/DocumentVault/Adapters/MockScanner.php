<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Adapters;

use App\Platform\DocumentVault\Contracts\MalwareScanner;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\ScanVerdict;

/**
 * Deterministic `MalwareScanner` stand-in for the combined dev/staging host
 * (`file-upload-pipeline.md` §9: "development may use a deterministic mock
 * scanner only for application-flow development" — "Mock scanning is never
 * production evidence").
 *
 * Verdicts, in priority order:
 *  1. The `document-vault.scanner_outage` switch is on → `ERROR`,
 *     unconditionally, before the stream is even read. This is the AC4
 *     fail-closed guarantee: an outage must never be reachable as `CLEAN`
 *     through any code path in this class.
 *  2. The stream's SHA-256 digest equals the published EICAR
 *     (https://www.eicar.org) standard test file's digest → `INFECTED`.
 *  3. The stream is larger than `$kind->maxSizeBytes()` → `SUSPICIOUS`
 *     (reuses `DocumentKind`'s single cap table rather than a second one —
 *     `task-3-brief.md` ambiguity ruling 2).
 *  4. Otherwise → `CLEAN`.
 */
final class MockScanner implements MalwareScanner
{
    /**
     * SHA-256 of the 68-byte EICAR standard antivirus test string
     * (`X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*`),
     * verified against a local `sha256sum` of that exact byte sequence.
     */
    private const string EICAR_SHA256 = '275a021bbfb6489e54d471899f7db9d1663fc695ec2fe2a2c4538aabf651fd0f';

    private const int READ_CHUNK_BYTES = 8192;

    public function scan(DocumentKind $kind, $stream): ScanVerdict
    {
        if ((bool) config('document-vault.scanner_outage', false)) {
            return ScanVerdict::Error;
        }

        [$digest, $bytesRead] = $this->digestAndSize($stream);

        if ($digest === self::EICAR_SHA256) {
            return ScanVerdict::Infected;
        }

        if ($bytesRead > $kind->maxSizeBytes()) {
            return ScanVerdict::Suspicious;
        }

        return ScanVerdict::Clean;
    }

    /**
     * @param  resource  $stream
     * @return array{0: string, 1: int} The SHA-256 hex digest and the total
     *                                  byte count read.
     */
    private function digestAndSize($stream): array
    {
        rewind($stream);

        $context = hash_init('sha256');
        $bytesRead = 0;

        while (! feof($stream)) {
            $chunk = fread($stream, self::READ_CHUNK_BYTES);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $bytesRead += strlen($chunk);
            hash_update($context, $chunk);
        }

        rewind($stream);

        return [hash_final($context), $bytesRead];
    }
}
