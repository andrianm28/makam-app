<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Concerns;

use App\Console\Commands\Concerns\RequiresAuditReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The command-layer blank-reason guard.
 *
 * Pinned directly rather than through the `identity:*` commands: since
 * `Audit::record()` runs the same check at the root, a command-level test
 * passes whether or not this trait works, so it cannot tell the two apart.
 * Only a direct test can detect this defence-in-depth layer regressing.
 */
final class RequiresAuditReasonTest extends TestCase
{
    #[DataProvider('blankReasonProvider')]
    public function test_it_treats_a_reason_with_no_readable_content_as_blank(?string $reason): void
    {
        $this->assertTrue($this->guard()->isBlank($reason));
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function blankReasonProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'ASCII spaces' => ['   '];
        yield 'non-breaking space U+00A0' => ["\u{00A0}"];
        yield 'ideographic space U+3000' => ["\u{3000}"];
        yield 'zero-width space U+200B' => ["\u{200B}"];
        yield 'malformed UTF-8: lone 0xA0' => ["\xA0"];
        yield 'malformed UTF-8: bad continuation' => ["\xC3\x28"];
        yield 'malformed UTF-8: surrogate' => ["\xED\xA0\x80"];
    }

    #[DataProvider('realReasonProvider')]
    public function test_it_accepts_a_reason_with_readable_content(string $reason): void
    {
        $this->assertFalse($this->guard()->isBlank($reason));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function realReasonProvider(): iterable
    {
        yield 'Indonesian prose with an em-dash' => [
            'Akses dicabut — pegawai mengundurkan diri, tiket OPS-114.',
        ];
        yield 'prose with accented characters' => ['Permintaan résmi kepala makam.'];
        yield 'prose containing a non-breaking space' => ["Ticket\u{00A0}OPS-114"];
        yield 'single ordinary word' => ['Mengundurkan'];
    }

    private function guard(): object
    {
        return new class
        {
            use RequiresAuditReason;

            public function isBlank(?string $reason): bool
            {
                return $this->reasonIsBlank($reason);
            }
        };
    }
}
