<?php

declare(strict_types=1);

namespace App\Support\Migrations;

/**
 * Flags a destructive schema call or raw-SQL statement that appears inside
 * a migration's up() method without an explicit override, and reports (but
 * does not fail on) one that does carry a `// contract-approved: <ref>`
 * override — see `docs/superpowers/specs/2026-09-05-cicd-automation-
 * design.md` §1.3 and §3.4 for why this exists and why it is comment-aware
 * rather than a raw grep: this codebase's own migrations routinely NAME
 * these calls inside doc-block comments while describing why the migration
 * is safe (e.g. "every dropColumn()/DELETE among them is confined to a
 * down() rollback method, never up()") — a naive text search would flag
 * those comments as violations. Comments are stripped (blanked to spaces,
 * preserving line numbers) before any pattern is searched for.
 *
 * FIXED post-final-review (5 Sep 2026): the original pattern list named
 * `dropTable`, which is not a real Laravel Schema/Blueprint method (grepped
 * this repo's real migrations: zero occurrences) — while missing the real
 * destructive forms this codebase actually uses (`Schema::dropIfExists()`,
 * `Schema::drop()`, `dropConstrainedForeignId()`) and any raw-SQL DROP/
 * DELETE issued via `DB::statement()`. `dropUnique`/`dropIndex` were
 * removed entirely — an index drop does not destroy data, so it never
 * belonged in a DATA-destructive gate; keeping it only produced spurious
 * blocks on safe index-swap migrations (a real one exists in this repo:
 * `2026_08_10_130200_harden_reconciliation_exceptions.php`).
 */
final class DestructiveMigrationScanner
{
    /** Case-sensitive PHP method-call patterns. @var list<string> */
    private const DESTRUCTIVE_METHOD_PATTERNS = [
        'dropColumn',
        'dropIfExists',
        'Schema::drop(',
        '->drop(',
        'dropConstrainedForeignId',
        'dropForeign',
        'DB::delete',
        '->truncate(',
        '->change(',
        'renameColumn',
        'dropPrimary',
    ];

    /** Case-insensitive raw-SQL fragments (matched via stripos). @var list<string> */
    private const DESTRUCTIVE_SQL_PATTERNS = [
        'DROP TABLE',
        'DROP COLUMN',
        'DELETE FROM',
        'TRUNCATE',
        'DROP CONSTRAINT',
    ];

    /**
     * @return list<array{line: int, pattern: string, status: 'violation'|'overridden'}>
     */
    public function scan(string $path): array
    {
        $original = file_get_contents($path);

        if ($original === false) {
            return [];
        }

        $stripped = $this->stripComments($original);

        if ($this->findFunctionOffset($stripped, 'up') === null) {
            return [];
        }

        // Scan the WHOLE file, not just an "up() start to down() start" slice
        // — a private helper method declared textually AFTER down() (called
        // FROM up()) falls outside that slice and was previously invisible
        // to this scanner. The only region deliberately excluded is down()'s
        // own brace-matched body, blanked out (not deleted) so line numbers
        // for everything else in the file stay accurate. This is correct
        // regardless of which method — up() or down() — is declared first.
        $searchSpace = $this->excludeDownBody($stripped);

        $originalLines = explode("\n", $original);

        $findings = [];

        foreach (explode("\n", $searchSpace) as $offset => $lineText) {
            $lineNumber = $offset + 1;

            foreach ($this->matchedPatterns($lineText) as $pattern) {
                $precedingLine = $originalLines[$lineNumber - 2] ?? '';
                $overridden = str_contains($precedingLine, 'contract-approved');

                $findings[] = [
                    'line' => $lineNumber,
                    'pattern' => $pattern,
                    'status' => $overridden ? 'overridden' : 'violation',
                ];
            }
        }

        return $findings;
    }

    /**
     * Blanks out down()'s own declaration-plus-body (brace-matched, so
     * nested braces inside it — e.g. `Schema::table('x', function ($t) {
     * ... })` — don't truncate the exclusion early) while preserving every
     * newline, so line numbers for the rest of the file are unaffected. If
     * down() cannot be found or its brace cannot be matched, the source is
     * returned unchanged (fail open to scanning, not to skipping).
     */
    private function excludeDownBody(string $source): string
    {
        $downStart = $this->findFunctionOffset($source, 'down');

        if ($downStart === null) {
            return $source;
        }

        $braceOpen = strpos($source, '{', $downStart);

        if ($braceOpen === false) {
            return $source;
        }

        $braceClose = $this->matchBrace($source, $braceOpen);

        if ($braceClose === null) {
            return $source;
        }

        $downRegion = substr($source, $downStart, $braceClose + 1 - $downStart);
        $blanked = (string) preg_replace('/[^\n]/', ' ', $downRegion);

        return substr($source, 0, $downStart).$blanked.substr($source, $braceClose + 1);
    }

    /**
     * Given the offset of an opening `{`, returns the offset of its
     * matching closing `}` by depth counting. Does not account for braces
     * inside string literals — out of scope for this migration-hygiene
     * scanner, which already blanks comments before this runs.
     */
    private function matchBrace(string $source, int $openOffset): ?int
    {
        $depth = 0;
        $length = strlen($source);

        for ($i = $openOffset; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function matchedPatterns(string $lineText): array
    {
        $matched = [];

        foreach (self::DESTRUCTIVE_METHOD_PATTERNS as $pattern) {
            if (str_contains($lineText, $pattern)) {
                $matched[] = $pattern;
            }
        }

        foreach (self::DESTRUCTIVE_SQL_PATTERNS as $pattern) {
            if (stripos($lineText, $pattern) !== false) {
                $matched[] = $pattern;
            }
        }

        // `ALTER COLUMN ... TYPE ...` narrows/widens a column's storage
        // type and can silently truncate or reject existing data — but a
        // bare `ALTER COLUMN` also appears in safe forms this gate must not
        // flag (`SET NOT NULL`, `DROP DEFAULT`, `SET DEFAULT`), so only
        // flag it when the same line also mentions `TYPE`.
        if (stripos($lineText, 'ALTER COLUMN') !== false && stripos($lineText, 'TYPE') !== false) {
            $matched[] = 'ALTER COLUMN ... TYPE';
        }

        return $matched;
    }

    private function findFunctionOffset(string $source, string $name): ?int
    {
        if (preg_match('/function\s+'.$name.'\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return $matches[0][1];
    }

    private function stripComments(string $source): string
    {
        $result = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $result .= (string) preg_replace('/[^\n]/', ' ', $token[1]);

                continue;
            }

            $result .= is_array($token) ? $token[1] : $token;
        }

        return $result;
    }
}
