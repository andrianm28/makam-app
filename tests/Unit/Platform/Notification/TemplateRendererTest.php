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
