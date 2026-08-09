<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\DocumentVault\Adapters;

use App\Platform\DocumentVault\Adapters\MockScanner;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\ScanVerdict;
use Tests\TestCase;

final class MockScannerTest extends TestCase
{
    private const string EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    public function test_a_harmless_small_stream_is_clean(): void
    {
        $scanner = new MockScanner;

        $verdict = $scanner->scan(DocumentKind::Ktp, $this->streamFor('a harmless identity document'));

        $this->assertSame(ScanVerdict::Clean, $verdict);
    }

    public function test_the_eicar_test_string_is_infected(): void
    {
        $scanner = new MockScanner;

        $verdict = $scanner->scan(DocumentKind::Ktp, $this->streamFor(self::EICAR));

        $this->assertSame(ScanVerdict::Infected, $verdict);
    }

    public function test_a_stream_larger_than_the_kind_cap_is_suspicious(): void
    {
        $scanner = new MockScanner;
        $oversized = str_repeat('a', DocumentKind::Ktp->maxSizeBytes() + 1);

        $verdict = $scanner->scan(DocumentKind::Ktp, $this->streamFor($oversized));

        $this->assertSame(ScanVerdict::Suspicious, $verdict);
    }

    public function test_the_outage_switch_returns_error_and_never_falls_through_to_clean(): void
    {
        config(['document-vault.scanner_outage' => true]);

        $scanner = new MockScanner;

        $verdict = $scanner->scan(DocumentKind::Ktp, $this->streamFor('perfectly harmless content'));

        $this->assertSame(ScanVerdict::Error, $verdict);
    }

    public function test_the_outage_switch_takes_priority_over_an_infected_verdict(): void
    {
        config(['document-vault.scanner_outage' => true]);

        $scanner = new MockScanner;

        $verdict = $scanner->scan(DocumentKind::Ktp, $this->streamFor(self::EICAR));

        $this->assertSame(
            ScanVerdict::Error,
            $verdict,
            'Fail-closed: outage must win even when the content would otherwise be INFECTED.',
        );
    }

    public function test_the_stream_is_left_rewound_for_the_caller_to_reuse(): void
    {
        $scanner = new MockScanner;
        $stream = $this->streamFor('content the caller still needs afterward');

        $scanner->scan(DocumentKind::Ktp, $stream);

        $this->assertSame('content the caller still needs afterward', stream_get_contents($stream));
    }

    /**
     * @return resource
     */
    private function streamFor(string $content)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
