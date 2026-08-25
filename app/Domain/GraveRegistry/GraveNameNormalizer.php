<?php

declare(strict_types=1);

namespace App\Domain\GraveRegistry;

/**
 * Produces `grave_records.deceased_name_normalized` from a raw deceased
 * name — `.kiro/specs/renewal-and-grave-registry/design.md`'s Search
 * section: "PostgreSQL `pg_trgm` proposed for normalized deceased name."
 *
 * The SAME function must run on both sides of the comparison — once at
 * write time (`GraveRecord::booted()`) and once at query time
 * (`GraveRegistryPublicQuery`) — or the stored form and the searched form
 * drift and trigram similarity silently degrades. That symmetry is the
 * whole reason this is one shared class rather than two inline `Str::`
 * chains.
 *
 * ---------------------------------------------------------------------------
 * What it deliberately does NOT do
 * ---------------------------------------------------------------------------
 * No honorific/title stripping ("Bapak", "Ibu", "Alm.", "H.", "Hj."), no
 * transliteration, no stemming. Each of those is a real product decision
 * about Indonesian naming that no document in this repository has made, and
 * getting one wrong here is not a cosmetic bug — it silently changes which
 * families can find their relative's record. `pg_trgm` similarity already
 * absorbs a leading title reasonably well because it scores on shared
 * trigrams rather than requiring a prefix match.
 *
 * Reported as an open question rather than decided here; a later batch that
 * has an operator's real registry file in hand is the right place to make
 * it, and it can be made without a schema change by re-running the
 * normalizer over the column.
 */
final class GraveNameNormalizer
{
    /**
     * Lowercase, replace every non-alphanumeric character with a single
     * space, collapse runs of whitespace, trim.
     *
     * `mb_strtolower` (not `strtolower`) because Indonesian names carry
     * non-ASCII characters and the byte-wise function would leave them
     * uncased, splitting one name into two normalized forms. `\p{L}\p{N}`
     * with the `u` modifier keeps those characters rather than mangling
     * them into separators, so "Soekarno-Hatta" and "Soekarno Hatta"
     * normalize identically while "Ratna Sari" keeps both of its tokens.
     */
    public static function normalize(string $name): string
    {
        $lowered = mb_strtolower(trim($name));

        $separated = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lowered);

        // preg_replace returns null only on a PCRE error (never for this
        // literal pattern); the ?? keeps the return type honest for static
        // analysis rather than guarding a reachable branch.
        $collapsed = preg_replace('/\s+/u', ' ', $separated ?? $lowered);

        return trim($collapsed ?? $lowered);
    }
}
