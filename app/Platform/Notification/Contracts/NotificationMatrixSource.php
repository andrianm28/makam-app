<?php

declare(strict_types=1);

namespace App\Platform\Notification\Contracts;

use RuntimeException;

/**
 * Reads the canonical notification matrix without copying its event table
 * into application code. The seed migration consumes `rows()` to create a
 * point-in-time snapshot; later notification work can use the same reader to
 * reconcile scope and channel decisions against the document.
 */
final class NotificationMatrixSource
{
    private const MATRIX_PATH = 'docs/contracts/notification-matrix.md';

    public function __construct(private readonly ?string $path = null) {}

    /**
     * @return list<array{event: string, recipients: array<string, string>}>
     */
    public function rows(): array
    {
        $path = $this->path ?? base_path(self::MATRIX_PATH);
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read notification matrix: {$path}");
        }

        $lines = preg_split('/\R/', $contents) ?: [];
        $headers = null;
        $rows = [];

        foreach ($lines as $line) {
            $cells = $this->cells($line);

            if ($cells === []) {
                continue;
            }

            if ($headers === null) {
                if ($cells[0] !== 'Event') {
                    continue;
                }

                $headers = $cells;

                continue;
            }

            if ($this->isSeparator($cells)) {
                continue;
            }

            if (count($cells) !== count($headers)) {
                throw new RuntimeException('Notification matrix row does not match its header columns.');
            }

            $rows[] = [
                'event' => $cells[0],
                'recipients' => array_combine(array_slice($headers, 1), array_slice($cells, 1)),
            ];
        }

        if ($headers === null) {
            throw new RuntimeException('Notification matrix header was not found.');
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function eventNames(): array
    {
        return array_column($this->rows(), 'event');
    }

    /**
     * @return array{event: string, recipients: array<string, string>}|null
     */
    public function forEvent(string $event): ?array
    {
        foreach ($this->rows() as $row) {
            if ($row['event'] === $event) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function cells(string $line): array
    {
        $line = trim($line);

        if (! str_starts_with($line, '|') || ! str_ends_with($line, '|')) {
            return [];
        }

        return array_map('trim', explode('|', trim($line, '|')));
    }

    /**
     * @param  list<string>  $cells
     */
    private function isSeparator(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (preg_match('/^:?-{3,}:?$/', $cell) !== 1) {
                return false;
            }
        }

        return true;
    }
}
