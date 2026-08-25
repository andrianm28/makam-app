<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\GraveRegistry;

use App\Domain\GraveRegistry\GraveNameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `App\Domain\GraveRegistry\GraveNameNormalizer` — Sprint 4 S4-T7,
 * `.kiro/specs/renewal-and-grave-registry` AC3.
 *
 * No `RefreshDatabase`: this is a pure string function with no database
 * behind it, the same shape as the value-object and enum tests under
 * `tests/Unit/Platform/**`.
 *
 * The property that matters most is the LIKE-safety one at the bottom.
 * `GraveRegistryPublicQuery` builds an unanchored `LIKE '%...%'` pattern
 * out of this function's output and documents that no wildcard escaping is
 * needed because normalization cannot emit `%`, `_` or `\`. That claim is
 * load-bearing enough to be asserted rather than trusted.
 */
final class GraveNameNormalizerTest extends TestCase
{
    /**
     * @return list<array{string, string}>
     */
    public static function normalizationCases(): array
    {
        return [
            'lowercases' => ['Budi Santoso', 'budi santoso'],
            'trims surrounding whitespace' => ['   Budi Santoso   ', 'budi santoso'],
            'collapses internal whitespace' => ["Budi\t\n  Santoso", 'budi santoso'],
            'replaces punctuation with a space' => ['Soekarno-Hatta', 'soekarno hatta'],
            'drops full stops in an abbreviation' => ['H. Abdul Rahman', 'h abdul rahman'],
            'drops commas and parentheses' => ['Santoso, Budi (Alm)', 'santoso budi alm'],
            'keeps digits' => ['Blok A 12', 'blok a 12'],
            'empty stays empty' => ['', ''],
            'punctuation only collapses to empty' => ['---', ''],
        ];
    }

    #[DataProvider('normalizationCases')]
    public function test_it_normalizes_a_name_to_its_searchable_form(string $input, string $expected): void
    {
        $this->assertSame($expected, GraveNameNormalizer::normalize($input));
    }

    /**
     * The whole reason this is one shared function rather than two inline
     * chains: the write side (`GraveRecord::booted()`) and the read side
     * (`GraveRegistryPublicQuery`) must produce byte-identical output, or
     * trigram similarity silently degrades against a form that was stored
     * differently.
     */
    public function test_normalization_is_idempotent(): void
    {
        $once = GraveNameNormalizer::normalize('  H. Soekarno-Hatta,  Jr. ');

        $this->assertSame($once, GraveNameNormalizer::normalize($once));
    }

    /**
     * Different spellings of the same name must converge, because AC3's
     * "fuzzy" promise starts here rather than at `pg_trgm` — the trigram
     * index only ever sees this function's output.
     */
    public function test_punctuation_variants_of_one_name_converge(): void
    {
        $expected = 'siti nur halimah';

        $this->assertSame($expected, GraveNameNormalizer::normalize('Siti Nur Halimah'));
        $this->assertSame($expected, GraveNameNormalizer::normalize('SITI NUR-HALIMAH'));
        $this->assertSame($expected, GraveNameNormalizer::normalize('siti  nur  halimah'));
    }

    /**
     * Non-ASCII letters survive rather than being mangled into separators —
     * the documented reason `\p{L}\p{N}` with the `u` modifier is used
     * instead of `[a-z0-9]`. A byte-wise implementation would split such a
     * name into two tokens and quietly make it unfindable.
     */
    public function test_non_ascii_letters_are_preserved_and_lowercased(): void
    {
        $this->assertSame('josé peña', GraveNameNormalizer::normalize('José Peña'));
    }

    /**
     * Asserts `GraveRegistryPublicQuery::buildQuery()`'s stated reason for
     * not escaping LIKE wildcards. If normalization ever starts preserving
     * one of these characters, this test fails and that comment becomes a
     * lie — which is exactly when someone needs to know.
     */
    public function test_normalized_output_can_never_contain_a_like_wildcard(): void
    {
        $hostile = '100% _match_ \\ back\\slash % _';

        $normalized = GraveNameNormalizer::normalize($hostile);

        $this->assertStringNotContainsString('%', $normalized);
        $this->assertStringNotContainsString('_', $normalized);
        $this->assertStringNotContainsString('\\', $normalized);
        $this->assertSame('100 match back slash', $normalized);
    }
}
