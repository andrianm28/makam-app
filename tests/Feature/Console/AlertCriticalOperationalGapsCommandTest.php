<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stop-gap visibility for two audit findings (COORD-07, COORD-15) while
 * their permanent fixes land separately: no worker on the deployed beta host
 * consumes the `media` queue (so documents can never leave quarantine), and
 * failed critical/urgent jobs have sat unretried with nothing surfacing
 * them. This command changes nothing — it only reports counts.
 */
final class AlertCriticalOperationalGapsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_a_stale_quarantined_document(): void
    {
        $document = Document::createQuarantined([
            'document_kind' => DocumentKind::Ktp,
            'owner_type' => 'booking_draft',
            'owner_id' => 'draft-stale',
            'original_filename' => 'ktp.pdf',
            'storage_prefix' => 'quarantine',
            'storage_key' => 'stale-key',
            'size_bytes' => 100,
            'mime_declared' => 'application/pdf',
            'scanner_required' => true,
        ]);
        DB::table('documents')->where('id', $document->id)
            ->update(['created_at' => now()->subHours(2)]);

        $fresh = Document::createQuarantined([
            'document_kind' => DocumentKind::Ktp,
            'owner_type' => 'booking_draft',
            'owner_id' => 'draft-fresh',
            'original_filename' => 'ktp.pdf',
            'storage_prefix' => 'quarantine',
            'storage_key' => 'fresh-key',
            'size_bytes' => 100,
            'mime_declared' => 'application/pdf',
            'scanner_required' => true,
        ]);
        DB::table('documents')->where('id', $fresh->id)
            ->update(['created_at' => now()->subMinutes(5)]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, '1 document(s)'));

        $this->artisan('alert:critical-operational-gaps')->assertExitCode(0);
    }

    public function test_it_reports_failed_jobs_on_critical_and_urgent_queues_only(): void
    {
        DB::table('failed_jobs')->insert([
            [
                'uuid' => (string) Str::uuid(),
                'connection' => 'redis',
                'queue' => 'critical',
                'payload' => '{}',
                'exception' => 'boom',
                'failed_at' => now()->subDays(12),
            ],
            [
                'uuid' => (string) Str::uuid(),
                'connection' => 'redis',
                'queue' => 'urgent',
                'payload' => '{}',
                'exception' => 'boom',
                'failed_at' => now()->subDays(12),
            ],
            [
                'uuid' => (string) Str::uuid(),
                'connection' => 'redis',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'boom',
                'failed_at' => now()->subDays(12),
            ],
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, '2 job(s)'));

        $this->artisan('alert:critical-operational-gaps')->assertExitCode(0);
    }

    public function test_it_logs_nothing_when_healthy(): void
    {
        Log::shouldReceive('warning')->never();

        $this->artisan('alert:critical-operational-gaps')->assertExitCode(0);
    }
}
