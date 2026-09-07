<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NOTIF-03 (`docs/superpowers/plans/2026-09-06-batch2e-notification-recipients-templates.md`).
 *
 * `2026_08_09_100020_seed_notification_templates_from_matrix.php` seeded
 * version 1 of every matrix row with a placeholder body — literally the
 * string `'Matrix snapshot (recipient/channel facts; not message copy):
 * ...'` — never real Indonesian copy. `2026_08_09_100010`'s trigger makes
 * EVERY row in `notification_template_versions` immutable the moment it is
 * inserted (`BEFORE UPDATE OR DELETE ... FOR EACH ROW ... RAISE EXCEPTION`,
 * unconditional on `version` — it is not a "version 1 only" guard, it
 * protects every version equally). The only way to give an event real copy
 * is therefore a brand-new version row, never an update to an existing one.
 *
 * This migration inserts version 2 for exactly the two matrix rows Batch
 * 2E's recipient-resolution fix
 * (`App\Platform\Notification\ProvisionalAggregateNotificationSubjectSource`)
 * makes reachable end-to-end: "Vendor accepted/rejected"
 * (`vendor_order.decided.v1`) and "Marketplace order submitted"
 * (`marketplace_order.submitted.v1`). `payment.outcome_failed.v1` and
 * `vendor.evidence_uploaded.v1` are deliberately left on their version-1
 * placeholder — their recipient resolution is NOT fixed by this batch (see
 * the derived plan doc), and shipping real copy for a message nothing ever
 * sends would misrepresent those events as fixed.
 *
 * `restricted_fields` is carried over unchanged from version 1
 * (`ktp`, `kk`, `death_certificate`, `bank_details`, `full_address`) — this
 * migration introduces no new sensitive field, so the PII guard list does
 * not change. `variable_allowlist` lists only the non-restricted fields each
 * event's `Outbox::record()` call actually carries in its `data` payload
 * (`UpdateVendorOrderStatus`, `PlaceMarketplaceOrder`) — references and
 * enum values, never customer PII.
 *
 * `down()` cannot delete the version-2 rows it inserts — the same trigger
 * that protects version 1 protects version 2 the instant it exists. This
 * mirrors the original seed migration's own non-destructive `down()`
 * (append-only data is not a rollback mechanism); this migration's `down()`
 * only un-flips `active_version_id` back to each template's version-1 row,
 * leaving the harmless, unreferenced version-2 rows in place — safe,
 * because nothing FK-restricts on an inactive version row remaining.
 */
return new class extends Migration
{
    private const string CREATED_BY = 'seed:batch2e-notification-templates';

    /**
     * @var list<array{event: string, subject: string, body: string, variable_allowlist: list<string>}>
     */
    private const array TEMPLATES = [
        [
            'event' => 'Vendor accepted/rejected',
            'subject' => 'Pesanan Anda telah diproses vendor',
            'body' => 'Kabar baik! Pesanan Anda dengan nomor referensi vendor {vendor_order_id} telah {outcome} oleh vendor. '
                .'Silakan cek halaman pesanan Anda di Makam.co.id untuk detail lebih lanjut. '
                .'Jika ada pertanyaan, hubungi layanan pelanggan kami.',
            'variable_allowlist' => ['vendor_order_id', 'outcome'],
        ],
        [
            'event' => 'Marketplace order submitted',
            'subject' => 'Pesanan Anda telah kami terima',
            'body' => 'Terima kasih, pesanan Anda dengan nomor {order_id} telah berhasil kami terima dan sedang diproses oleh vendor. '
                .'Kami akan mengabari Anda begitu vendor memberikan keputusan atas pesanan ini.',
            'variable_allowlist' => ['order_id'],
        ],
    ];

    public function up(): void
    {
        $now = now();

        DB::transaction(function () use ($now): void {
            foreach (self::TEMPLATES as $template) {
                $templateId = DB::table('notification_templates')
                    ->where('event_name', $template['event'])
                    ->value('id');

                if ($templateId === null) {
                    // The matrix row must exist (seeded by 2026_08_09_100020);
                    // a missing template here means the matrix document
                    // changed underneath this migration. Fail loudly rather
                    // than silently skipping real copy for a live event.
                    throw new RuntimeException("Notification template not found for event [{$template['event']}].");
                }

                $existingV2 = DB::table('notification_template_versions')
                    ->where('template_id', $templateId)
                    ->where('version', 2)
                    ->first();

                if ($existingV2 !== null) {
                    // Idempotent rerun: version 2 already exists (and is
                    // itself immutable), just make sure it is active.
                    DB::table('notification_templates')
                        ->where('id', $templateId)
                        ->update(['active_version_id' => $existingV2->id]);

                    continue;
                }

                $versionId = DB::table('notification_template_versions')->insertGetId([
                    'template_id' => $templateId,
                    'version' => 2,
                    'subject' => $template['subject'],
                    'body' => $template['body'],
                    'variable_allowlist' => json_encode($template['variable_allowlist'], JSON_THROW_ON_ERROR),
                    'restricted_fields' => json_encode([
                        'ktp',
                        'kk',
                        'death_certificate',
                        'bank_details',
                        'full_address',
                    ], JSON_THROW_ON_ERROR),
                    'created_by' => self::CREATED_BY,
                    'created_at' => $now,
                ]);

                DB::table('notification_templates')
                    ->where('id', $templateId)
                    ->update(['active_version_id' => $versionId]);
            }
        });
    }

    public function down(): void
    {
        // Non-destructive by design (see class doc block): version-2 rows
        // are immutable the instant they exist, identical to version-1's
        // own trigger-enforced guarantee, so they cannot be deleted here.
        // Only the active-version pointer (an ordinary, un-triggered column
        // on notification_templates) is reverted back to version 1.
        foreach (self::TEMPLATES as $template) {
            $templateId = DB::table('notification_templates')
                ->where('event_name', $template['event'])
                ->value('id');

            if ($templateId === null) {
                continue;
            }

            $version1Id = DB::table('notification_template_versions')
                ->where('template_id', $templateId)
                ->where('version', 1)
                ->value('id');

            if ($version1Id !== null) {
                DB::table('notification_templates')
                    ->where('id', $templateId)
                    ->update(['active_version_id' => $version1Id]);
            }
        }
    }
};
