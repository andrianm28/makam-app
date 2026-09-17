<?php

declare(strict_types=1);

use App\Platform\Notification\Contracts\NotificationMatrixSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage R1 of `docs/superpowers/plans/2026-09-13-sistem-refund.md` (= Tahap 2
 * of `docs/superpowers/plans/2026-09-13-bayar-penuh-di-muka-online-saja.md`)
 * gives `docs/contracts/notification-matrix.md` a new row, `Order refused
 * after payment`, and this migration makes that row real on databases that
 * have already been migrated.
 *
 * ---------------------------------------------------------------------------
 * Why a migration is needed at all, when the seed migration reads the matrix
 * ---------------------------------------------------------------------------
 * `2026_08_09_100020_seed_notification_templates_from_matrix.php` re-reads
 * the matrix file at migration time, so a FRESH `migrate` (every test run,
 * every new environment) already creates a `notification_templates` row for
 * the new matrix row without any help. An ALREADY-MIGRATED database — dev,
 * beta, and eventually production — will never run that migration again, so
 * for those the row would simply not exist.
 *
 * That gap is not cosmetic. `App\Domain\OrderWorkflow\Listeners\
 * DispatchOrderNotifications` looks the template up by `event_name` when an
 * order reaches `DITOLAK_SETELAH_BAYAR`, and a missing row means the
 * notification fails at runtime — at the exact moment a customer who has
 * already paid in full needs to be told their order was refused. Verified by
 * reading the seed migration rather than assumed, per the task brief.
 *
 * ---------------------------------------------------------------------------
 * `outbox_event_name` stays NULL, deliberately
 * ---------------------------------------------------------------------------
 * Unlike `2026_09_07_100000_add_renewal_marked_external_notification_
 * template.php`, this row maps to NO dedicated outbox event and must not
 * invent one (Global Constraint / finding N-12: never invent an event name).
 * It is reached the same way `Order processing` and `Order completed` are:
 * `DispatchOrderNotifications` discriminates on the single catalogued
 * `order.status_changed.v1` event's `to_status` and passes the matrix label
 * explicitly, and `ConsumeOutboxNotificationJob`'s lookup never reads
 * `outbox_event_name` at all. Those two rows carry NULL for exactly this
 * reason; so does this one.
 *
 * Recipient facts and the version-1 body come from the matrix row itself via
 * `NotificationMatrixSource`, never retyped here — the matrix is the single
 * source of truth for recipient scope and channel (`AGENTS.md`
 * §Documentation: do not duplicate canonical catalog data).
 *
 * Idempotent and non-destructive, following the two migrations above: an
 * existing version 1 is immutable and is never rewritten on a rerun, and
 * `down()` deletes nothing.
 */
return new class extends Migration
{
    private const string MATRIX_EVENT_NAME = 'Order refused after payment';

    public function up(): void
    {
        $now = now();
        $row = (new NotificationMatrixSource)->forEvent(self::MATRIX_EVENT_NAME);

        if ($row === null) {
            // The matrix row is the authority. If it is ever renamed or
            // removed without updating this migration, fail loudly rather
            // than silently wiring the wrong recipients onto a message about
            // somebody's money.
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
                    'outbox_event_name' => null,
                ]);
            } else {
                $templateId = $template->id;

                // Reconcile the channel only. `outbox_event_name` is left
                // exactly as found: NULL is this row's correct value, and
                // writing NULL over a value some later change deliberately
                // set would be this migration silently undoing it.
                DB::table('notification_templates')
                    ->where('id', $templateId)
                    ->update(['default_channel' => $this->defaultChannel($row['recipients'])]);
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
