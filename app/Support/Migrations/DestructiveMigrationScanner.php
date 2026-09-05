<?php

declare(strict_types=1);

namespace App\Support\Migrations;

/**
 * Flags a destructive schema call (dropColumn/dropTable/... ) that appears
 * inside a migration's up() method without an explicit override — see
 * `docs/superpowers/specs/2026-09-05-cicd-automation-design.md` §1.3 and
 * §3.4 for why this exists and why it is comment-aware rather than a raw
 * grep: this codebase's own migrations routinely NAME these calls inside
 * doc-block comments while describing why the migration is safe (e.g.
 * "every dropColumn()/DELETE among them is confined to a down() rollback
 * method, never up()") — a naive text search would flag those comments as
 * violations. Comments are stripped (blanked to spaces, preserving line
 * numbers) before any pattern is searched for.
 */
final class DestructiveMigrationScanner
{
    /** @var list<string> */
    private const DESTRUCTIVE_PATTERNS = [
        'dropColumn',
        'dropTable',
        'dropForeign',
        'dropUnique',
        'dropIndex',
        'DB::delete',
        '->truncate(',
        'DELETE FROM',
        'TRUNCATE',
    ];

    /**
     * @return list<array{line: int, pattern: string}>
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

        $violations = [];

        foreach (explode("\n", $upBody) as $offset => $lineText) {
            foreach (self::DESTRUCTIVE_PATTERNS as $pattern) {
                if (! str_contains($lineText, $pattern)) {
                    continue;
                }

                $lineNumber = $upBodyStartLine + $offset;
                $precedingLine = $originalLines[$lineNumber - 2] ?? '';

                if (str_contains($precedingLine, 'contract-approved')) {
                    continue;
                }

                $violations[] = ['line' => $lineNumber, 'pattern' => $pattern];
            }
        }

        return $violations;
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
