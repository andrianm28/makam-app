<?php

declare(strict_types=1);

use App\Platform\Notification\Contracts\NotificationMatrixSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 Batch M8b, NOTIF-13 (07 Sep 2026): `visit.booking_requested.v1`
 * and `visit.booking_confirmed.v1` were already catalogued
 * (`docs/contracts/event-catalog.md`) and already had real producers
 * (`App\Domain\Visitation\Actions\RequestVisitation::book()` and
 * `App\Domain\Visitation\Actions\ChangeVisitationBookingStatus::__invoke()`,
 * both recording `aggregate_type = 'visitation_booking'`), but NO
 * `notification_templates` mapping and no subject-source arm — every
 * visitation event was recorded onto the outbox and then silently dropped,
 * while the customer-facing copy claimed the request was sent to the
 * operator. `ProvisionalAggregateNotificationSubjectSource::
 * visitationBookingSubject()` (same PR) closes the subject-resolution half;
 * this migration closes the template-mapping half, following
 * `2026_09_07_100000_add_renewal_marked_external_notification_template.php`
 * (Batch M1a)'s shape exactly for both rows.
 *
 * `2026_08_09_100020_seed_notification_templates_from_matrix.php`'s own
 * `up()` already creates a `notification_templates` row for EVERY current
 * matrix row (including these two new ones) on every fresh migrate, because
 * it re-reads the matrix file at migration time — but its `outboxEventName()`
 * match has no arm for either new label, so those rows are created with a
 * NULL `outbox_event_name`. This migration finds those same rows (creating
 * them if a specific run order ever left them missing) and sets the mapping
 * and its immutable version-1 body.
 *
 * Non-destructive `down()`, same reasoning as the seed migration's own:
 * immutable version data is not deleted as a rollback mechanism.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const array EVENT_MAPPINGS = [
        'Visitation booking requested' => 'visit.booking_requested.v1',
        'Visitation booking confirmed' => 'visit.booking_confirmed.v1',
    ];

    public function up(): void
    {
        $now = now();
        $source = new NotificationMatrixSource;

        foreach (self::EVENT_MAPPINGS as $matrixEventName => $outboxEventName) {
            $row = $source->forEvent($matrixEventName);

            if ($row === null) {
                // The matrix row is the authority; if it is ever renamed or
                // removed without updating this migration, fail loudly
                // rather than silently wiring the wrong recipients.
                throw new RuntimeException(
                    'Notification matrix row "'.$matrixEventName.'" was not found; '.
                    'update this migration to match docs/contracts/notification-matrix.md.'
                );
            }

            DB::transaction(function () use ($now, $row, $matrixEventName, $outboxEventName): void {
                $template = DB::table('notification_templates')
                    ->where('event_name', $matrixEventName)
                    ->first();

                if ($template === null) {
                    $templateId = DB::table('notification_templates')->insertGetId([
                        'event_name' => $matrixEventName,
                        'default_channel' => $this->defaultChannel($row['recipients']),
                        'outbox_event_name' => $outboxEventName,
                    ]);
                } else {
                    $templateId = $template->id;

                    DB::table('notification_templates')
                        ->where('id', $templateId)
                        ->update([
                            'default_channel' => $this->defaultChannel($row['recipients']),
                            'outbox_event_name' => $outboxEventName,
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
                        'subject' => $matrixEventName,
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
                    // Existing version 1 is immutable. Never update its
                    // body, allowlist, or restricted-field snapshot on a
                    // rerun.
                    $versionId = $version->id;
                }

                DB::table('notification_templates')
                    ->where('id', $templateId)
                    ->update(['active_version_id' => $versionId]);
            });
        }
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
