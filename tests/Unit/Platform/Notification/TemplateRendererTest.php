<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\Contracts\NotificationMatrixSource;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Notification\TemplateRenderer;
use InvalidArgumentException;
use Tests\TestCase;

final class TemplateRendererTest extends TestCase
{
    public function test_it_substitutes_only_allowlisted_variables(): void
    {
        $version = $this->version(
            subject: 'Pesanan {{ reference }}',
            body: 'Status pesanan {{ reference }}: {{ status }}.',
            allowlist: ['reference', 'status'],
        );

        $rendered = (new TemplateRenderer)->render($version, [
            'reference' => 'ORD-123',
            'status' => 'menunggu verifikasi',
        ]);

        $this->assertSame('Pesanan ORD-123', $rendered['subject']);
        $this->assertSame('Status pesanan ORD-123: menunggu verifikasi.', $rendered['body']);
    }

    public function test_a_restricted_variable_is_rejected_even_when_allowlisted(): void
    {
        $version = $this->version(
            body: 'Dokumen {{ ktp }} untuk {{ reference }}.',
            allowlist: ['ktp', 'reference'],
            restrictedFields: ['ktp'],
        );

        $this->expectException(InvalidArgumentException::class);

        (new TemplateRenderer)->render($version, [
            'ktp' => 'private-document-reference',
            'reference' => 'ORD-123',
        ]);
    }

    public function test_a_non_allowlisted_template_variable_is_a_hard_error(): void
    {
        $version = $this->version(
            body: 'Status {{ status }}.',
            allowlist: ['reference'],
        );

        $this->expectException(InvalidArgumentException::class);

        (new TemplateRenderer)->render($version, ['status' => 'queued']);
    }

    public function test_the_matrix_reader_returns_every_event_and_preserves_cell_facts(): void
    {
        $rows = (new NotificationMatrixSource)->rows();

        $this->assertNotEmpty($rows);
        $this->assertSame(['event', 'recipients'], array_keys($rows[0]));
        $this->assertSame('IN_APP/EMAIL for selected location', $rows[1]['recipients']['Pengelola TPU/TPS']);
        $this->assertSame('EMAIL/WA + invoice', $rows[7]['recipients']['Customer']);
    }

    /**
     * Proves the reconciled matrix still reads end to end. The 11 Aug 2026
     * reconciliation annotates the document with a header note ABOVE the
     * table (Task 6) — `NotificationMatrixSource` scans for a header row
     * whose first cell is `Event` and then throws on any later line whose
     * cell count differs, so a note that snuck into the table (or a row
     * added with the wrong column count) must fail loudly here rather than
     * silently skewing the seed and every recipient-resolution decision.
     * The 17-row count and the live `forEvent()` lookup pin the canonical
     * event set and the header-derived recipient columns without restating
     * the full event list as a second source of truth.
     */
    public function test_the_reconciled_matrix_parses_end_to_end(): void
    {
        $source = new NotificationMatrixSource;

        $rows = $source->rows();

        $this->assertCount(17, $rows);

        $headerColumns = array_keys($rows[0]['recipients']);
        foreach ($rows as $row) {
            $this->assertSame($headerColumns, array_keys($row['recipients']));
        }

        $bookingSubmitted = $source->forEvent('Booking submitted');
        $this->assertNotNull($bookingSubmitted);
        $this->assertSame('Booking submitted', $bookingSubmitted['event']);
        $this->assertSame('IN_APP/EMAIL for selected location', $bookingSubmitted['recipients']['Pengelola TPU/TPS']);

        $this->assertNull($source->forEvent('Not a matrix event'));
    }

    /**
     * @param  list<string>  $allowlist
     * @param  list<string>  $restrictedFields
     */
    private function version(
        string $subject = 'Subject',
        string $body = 'Body',
        array $allowlist = [],
        array $restrictedFields = [],
    ): NotificationTemplateVersion {
        return (new NotificationTemplateVersion)->forceFill([
            'version' => 1,
            'subject' => $subject,
            'body' => $body,
            'variable_allowlist' => $allowlist,
            'restricted_fields' => $restrictedFields,
            'created_by' => 'test',
            'created_at' => now(),
        ]);
    }
}
