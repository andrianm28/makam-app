<?php

declare(strict_types=1);

use App\Platform\Notification\Contracts\NotificationMatrixSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 Batch M1a, QUE-03 (07 Sep 2026): the offline renewal-settlement
 * event `renewal.marked_external.v1` was already catalogued
 * (`docs/contracts/event-catalog.md`) but had NO producer and NO
 * `notification_templates` mapping — `Actions\MarkExternalRenewal` and
 * `Actions\MarkRenewalPaidExternally` now emit it (see those classes'
 * doc blocks), and this migration wires the dispatch side.
 *
 * `docs/contracts/notification-matrix.md` gained a new row, `Renewal paid/
 * verified (external)`, carrying the SAME recipient facts as the existing
 * `Renewal paid/verified` row — a new row was necessary only because
 * `notification_templates.event_name` is UNIQUE and that label already
 * belongs to the online path's template
 * (`2026_08_09_100020_seed_notification_templates_from_matrix.php`'s
 * `Renewal paid/verified` => `renewal.paid_online.v1` entry, which this
 * migration mirrors exactly for the new row).
 *
 * `2026_08_09_100020_seed_notification_templates_from_matrix.php`'s own
 * `up()` already creates a `notification_templates` row for EVERY current
 * matrix row (including this new one) on every fresh migrate, because it
 * re-reads the matrix file at migration time — but its `outboxEventName()`
 * match has no arm for the new label, so that row is created with a NULL
 * `outbox_event_name`. This migration finds that same row (creating it if
 * a specific run order ever left it missing) and sets the mapping and its
 * immutable version-1 body, following that migration's own insert shape.
 *
 * Non-destructive `down()`, same reasoning as the seed migration's own:
 * immutable version data is not deleted as a rollback mechanism.
 */
return new class extends Migration
{
    private const string MATRIX_EVENT_NAME = 'Renewal paid/verified (external)';

    private const string OUTBOX_EVENT_NAME = 'renewal.marked_external.v1';

    public function up(): void
    {
        $now = now();
        $source = new NotificationMatrixSource;
        $row = $source->forEvent(self::MATRIX_EVENT_NAME);

        if ($row === null) {
            // The matrix row is the authority; if it is ever renamed or
            // removed without updating this migration, fail loudly rather
            // than silently wiring the wrong recipients.
            throw new RuntimeException(
                'Notification matrix row "'.self::MATRIX_EVENT_NAME.'" was not found; '.
                'update this migration to match docs/contracts/notification-matrix.md.'
            );
        }

        DB::transaction(function () use ($now, $row): void {
            $template = DB::table('notification_templates')
                ->where('event_name', self::MATRIX_EVENT_NAME)
                ->first();

            if ($template === null) {
                $templateId = DB::table('notification_templates')->insertGetId([
                    'event_name' => self::MATRIX_EVENT_NAME,
                    'default_channel' => $this->defaultChannel($row['recipients']),
                    'outbox_event_name' => self::OUTBOX_EVENT_NAME,
                ]);
            } else {
                $templateId = $template->id;

                DB::table('notification_templates')
                    ->where('id', $templateId)
                    ->update([
                        'default_channel' => $this->defaultChannel($row['recipients']),
                        'outbox_event_name' => self::OUTBOX_EVENT_NAME,
                    ]);
            }

            $version = DB::table('notification_template_versions')
                ->where('template_id', $templateId)
                ->where('version', 1)
                ->first();

            if ($version === null) {
                $versionId = DB::table('notification_template_versions')->insertGetId([
                    'template_id' => $templateId,
                    'version' => 1,
                    'subject' => self::MATRIX_EVENT_NAME,
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
            } else {
                // Existing version 1 is immutable. Never update its body,
                // allowlist, or restricted-field snapshot on a rerun.
                $versionId = $version->id;
            }

            DB::table('notification_templates')
                ->where('id', $templateId)
                ->update(['active_version_id' => $versionId]);
        });
    }

    public function down(): void
    {
        // Non-destructive by design — see this migration's own doc block.
    }

    /**
     * @param  array<string, string>  $recipients
     */
    private function defaultChannel(array $recipients): ?string
    {
        $facts = implode(' ', $recipients);

        if (str_contains($facts, 'EMAIL')) {
            return 'EMAIL';
        }

        return str_contains($facts, 'WA') ? 'WA' : null;
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
