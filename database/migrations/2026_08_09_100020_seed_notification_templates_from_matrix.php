<?php

declare(strict_types=1);

use App\Platform\Notification\Contracts\NotificationMatrixSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Snapshots every row in `docs/contracts/notification-matrix.md` as version 1.
 * The Markdown document remains the durable authority. These rows are only a
 * versioned rendering snapshot for later dispatch work.
 *
 * The matrix currently defines recipient/channel facts, not message copy.
 * Consequently each seeded body is explicitly marked as a matrix fact
 * snapshot and contains those facts verbatim; this migration invents no
 * customer-facing notification language.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $source = new NotificationMatrixSource;

        foreach ($source->rows() as $row) {
            $templateId = DB::table('notification_templates')->insertGetId([
                'event_name' => $row['event'],
                'default_channel' => $this->defaultChannel($row['recipients']),
            ]);

            $versionId = DB::table('notification_template_versions')->insertGetId([
                'template_id' => $templateId,
                'version' => 1,
                'subject' => $row['event'],
                'body' => $this->snapshotBody($row['recipients']),
                'variable_allowlist' => json_encode([], JSON_THROW_ON_ERROR),
                'restricted_fields' => json_encode([
                    'ktp',
                    'kk',
                    'death_certificate',
                    'bank_details',
                    'full_address',
                ], JSON_THROW_ON_ERROR),
                'created_by' => 'seed:notification-matrix',
                'created_at' => $now,
            ]);

            DB::table('notification_templates')
                ->where('id', $templateId)
                ->update(['active_version_id' => $versionId]);
        }
    }

    public function down(): void
    {
        $events = array_column((new NotificationMatrixSource)->rows(), 'event');
        $templateIds = DB::table('notification_templates')
            ->whereIn('event_name', $events)
            ->pluck('id');

        DB::table('notification_templates')
            ->whereIn('id', $templateIds)
            ->update(['active_version_id' => null]);

        DB::table('notification_template_versions')
            ->whereIn('template_id', $templateIds)
            ->where('created_by', 'seed:notification-matrix')
            ->delete();

        DB::table('notification_templates')
            ->whereIn('id', $templateIds)
            ->delete();
    }

    /**
     * @param  array<string, string>  $recipients
     */
    private function defaultChannel(array $recipients): string
    {
        $facts = implode(' ', $recipients);

        // EMAIL is the documented baseline whenever the matrix names both
        // external channels. WA remains available for a future row that names
        // only WA. Rows without an external recipient still need the schema's
        // non-null structural default; no recipient is added by it.
        if (! str_contains($facts, 'EMAIL') && str_contains($facts, 'WA')) {
            return 'WA';
        }

        return 'EMAIL';
    }

    /**
     * @param  array<string, string>  $recipients
     */
    private function snapshotBody(array $recipients): string
    {
        $facts = [];

        foreach ($recipients as $recipient => $channelFact) {
            $facts[] = $recipient.': '.$channelFact;
        }

        return 'Matrix snapshot (recipient/channel facts; not message copy): '.implode('; ', $facts);
    }
};
