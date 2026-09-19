<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 4 (`.superpowers/sdd/2026-09-19-notification-template-variables/`).
 *
 * `2026_09_06_130000_add_v2_notification_templates_for_zero_recipient_events.php`
 * shipped real Indonesian copy for "Vendor accepted/rejected"
 * (`vendor_order.decided.v1`) and "Marketplace order submitted"
 * (`marketplace_order.submitted.v1`), but both bodies used SINGLE-brace
 * placeholders (`{vendor_order_id}`, `{outcome}`, `{order_id}`).
 * `TemplateRenderer::PLACEHOLDER_PATTERN` has only ever matched
 * `{{ name }}` (see `.kiro/specs/platform-notifications/design.md`
 * §"Template variables (AC15, added 19 Sep 2026)": "Placeholders are
 * `{{ name }}` only; the two version-2 bodies that used single braces are
 * superseded by version-3 rows.") — the single braces were never a
 * placeholder from the renderer's point of view, just literal characters.
 * Every customer who received one of these two notifications since version
 * 2 shipped saw the literal string `{order_id}` (or `{vendor_order_id}` /
 * `{outcome}`) in their notification body instead of the real value.
 *
 * This migration inserts version 3 for exactly those two templates.
 * `variable_allowlist` and `restricted_fields` are carried over unchanged
 * from version 2 — no new variable is introduced, so the allowlist and the
 * PII guard list do not change.
 *
 * ---------------------------------------------------------------------------
 * "Vendor accepted/rejected" body is NOT a byte-for-byte copy of version 2
 * ---------------------------------------------------------------------------
 * Fix round 1 review caught two live copy defects in version 2's
 * "Vendor accepted/rejected" body that were latent only because the single
 * braces never rendered: (1) it opened with `Kabar baik!` ("Good news!"),
 * but `UpdateVendorOrderStatus.php` emits `vendor_order.decided.v1` for
 * BOTH `DITERIMA_VENDOR` (accepted) and `DITOLAK_VENDOR` (rejected) — one
 * matrix row, one template, no branch by outcome — so that sentence would
 * congratulate a bereaved customer on a REJECTION; (2) `{{ outcome }}`
 * would have interpolated the raw `VendorProcessingStatus` constant
 * (`DITERIMA_VENDOR` / `DITOLAK_VENDOR`) directly into the sentence — no
 * Indonesian label map exists anywhere in this codebase — producing
 * "telah DITOLAK_VENDOR oleh vendor." in a customer-facing message. Making
 * these placeholders actually render (this task's whole point) is what
 * would have shipped both defects live, so fixing them is this task's to
 * do, not a follow-up.
 *
 * The version-3 body below is deliberately NEUTRAL: it states that the
 * vendor has made a decision and sends the customer to their order page
 * for the outcome, rather than asserting or wording the outcome itself.
 * `outcome` stays in `variable_allowlist` (a supplied, allowlisted, but
 * UNreferenced variable is accepted by `TemplateRenderer::render()` — its
 * loop only requires "referenced" and "supplied" names to be allowlisted
 * and, for referenced-but-unsupplied names, to throw; it does not require
 * that a supplied name be referenced) so `UpdateVendorOrderStatus`'s
 * existing payload shape (`vendor_order_id`, `outcome`) needs no change.
 * A proper accepted-vs-rejected wording needs either a second matrix row
 * (splitting this into two events) or a status-label catalogue mapping
 * `VendorProcessingStatus` constants to Indonesian phrases — both are
 * product decisions out of this task's scope, noted as a follow-up in
 * `task-4-report.md` rather than guessed at here.
 *
 * `down()` cannot delete the version-3 rows it inserts — the immutability
 * trigger on `notification_template_versions` (from
 * `2026_08_09_100010`) protects every version equally, the instant it
 * exists. This mirrors both the original seed migration's `down()` and
 * version 2's own `down()`: only the active-version pointer is reverted,
 * this time back to version 2 (not version 1 — version 2's copy was
 * correct Indonesian text, just unrenderable; reverting further back to
 * version 1's placeholder-matrix-snapshot body would be a worse regression
 * than leaving version 2 active while this migration is rolled back).
 */
return new class extends Migration
{
    private const string CREATED_BY = 'seed:v3-double-brace-placeholders';

    /**
     * @var list<array{event: string, subject: string, body: string, variable_allowlist: list<string>}>
     */
    private const array TEMPLATES = [
        [
            'event' => 'Vendor accepted/rejected',
            'subject' => 'Pesanan Anda telah diproses vendor',
            'body' => 'Vendor telah membuat keputusan atas pesanan Anda dengan nomor referensi vendor {{ vendor_order_id }}. '
                .'Silakan cek halaman pesanan Anda di Makam.co.id untuk melihat keputusan dan detail lebih lanjut. '
                .'Jika ada pertanyaan, hubungi layanan pelanggan kami.',
            'variable_allowlist' => ['vendor_order_id', 'outcome'],
        ],
        [
            'event' => 'Marketplace order submitted',
            'subject' => 'Pesanan Anda telah kami terima',
            'body' => 'Terima kasih, pesanan Anda dengan nomor {{ order_id }} telah berhasil kami terima dan sedang diproses oleh vendor. '
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

                $existingV3 = DB::table('notification_template_versions')
                    ->where('template_id', $templateId)
                    ->where('version', 3)
                    ->first();

                if ($existingV3 !== null) {
                    // Idempotent rerun: version 3 already exists (and is
                    // itself immutable), just make sure it is active.
                    DB::table('notification_templates')
                        ->where('id', $templateId)
                        ->update(['active_version_id' => $existingV3->id]);

                    continue;
                }

                $versionId = DB::table('notification_template_versions')->insertGetId([
                    'template_id' => $templateId,
                    'version' => 3,
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
        // Non-destructive by design (see class doc block): version-3 rows
        // are immutable the instant they exist, identical to version-1 and
        // version-2's own trigger-enforced guarantee, so they cannot be
        // deleted here. Only the active-version pointer (an ordinary,
        // un-triggered column on notification_templates) is reverted back
        // to version 2.
        foreach (self::TEMPLATES as $template) {
            $templateId = DB::table('notification_templates')
                ->where('event_name', $template['event'])
                ->value('id');

            if ($templateId === null) {
                continue;
            }

            $version2Id = DB::table('notification_template_versions')
                ->where('template_id', $templateId)
                ->where('version', 2)
                ->value('id');

            if ($version2Id !== null) {
                DB::table('notification_templates')
                    ->where('id', $templateId)
                    ->update(['active_version_id' => $version2Id]);
            }
        }
    }
};
