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
    ];

    /** Case-insensitive raw-SQL fragments (matched via stripos). @var list<string> */
    private const DESTRUCTIVE_SQL_PATTERNS = [
        'DROP TABLE',
        'DROP COLUMN',
        'DELETE FROM',
        'TRUNCATE',
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
        $upStart = $this->findFunctionOffset($stripped, 'up');

        if ($upStart === null) {
            return [];
        }

        $downStart = $this->findFunctionOffset($stripped, 'down');
        $upBody = $downStart !== null && $downStart > $upStart
            ? substr($stripped, $upStart, $downStart - $upStart)
            : substr($stripped, $upStart);

        $upBodyStartLine = substr_count($stripped, "\n", 0, $upStart) + 1;
        $originalLines = explode("\n", $original);

        $findings = [];

        foreach (explode("\n", $upBody) as $offset => $lineText) {
            foreach ($this->matchedPatterns($lineText) as $pattern) {
                $lineNumber = $upBodyStartLine + $offset;
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
