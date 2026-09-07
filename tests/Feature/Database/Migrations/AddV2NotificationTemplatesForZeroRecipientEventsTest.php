<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Migrations;

use App\Platform\Notification\Exceptions\NotificationTemplateVersionIsImmutableException;
use App\Platform\Notification\Models\NotificationTemplate;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * NOTIF-03, `2026_09_06_130000_add_v2_notification_templates_for_zero_
 * recipient_events.php` — see `docs/superpowers/plans/2026-09-06-batch2e-
 * notification-recipients-templates.md` for the full design. This
 * migration runs as part of every `RefreshDatabase` test (it is a real,
 * already-applied migration, not a fixture instantiated by hand), so these
 * tests read its effect directly rather than re-running `up()`/`down()`.
 */
final class AddV2NotificationTemplatesForZeroRecipientEventsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: NotificationTemplate, 1: NotificationTemplateVersion, 2: NotificationTemplateVersion}
     */
    private function vendorAcceptedRejected(): array
    {
        $template = NotificationTemplate::query()->where('event_name', 'Vendor accepted/rejected')->sole();
        $v1 = NotificationTemplateVersion::query()->where('template_id', $template->id)->where('version', 1)->sole();
        $v2 = NotificationTemplateVersion::query()->where('template_id', $template->id)->where('version', 2)->sole();

        return [$template, $v1, $v2];
    }

    public function test_vendor_accepted_rejected_now_has_a_version_2_active_with_real_indonesian_copy(): void
    {
        [$template, , $v2] = $this->vendorAcceptedRejected();

        $this->assertSame($v2->id, $template->active_version_id);
        $this->assertNotNull($v2->subject);
        $this->assertStringNotContainsString('Matrix snapshot', $v2->body);
        $this->assertStringContainsString('vendor', mb_strtolower($v2->subject.' '.$v2->body));
    }

    public function test_vendor_accepted_rejected_version_1_survives_untouched(): void
    {
        [, $v1] = $this->vendorAcceptedRejected();

        $this->assertSame(1, $v1->version);
        $this->assertStringContainsString('Matrix snapshot', $v1->body);
        $this->assertSame('seed:notification-matrix', $v1->created_by);
    }

    public function test_marketplace_order_submitted_now_has_a_version_2_active_with_real_indonesian_copy(): void
    {
        $template = NotificationTemplate::query()->where('event_name', 'Marketplace order submitted')->sole();
        $v2 = NotificationTemplateVersion::query()->where('template_id', $template->id)->where('version', 2)->sole();

        $this->assertSame($v2->id, $template->active_version_id);
        $this->assertStringNotContainsString('Matrix snapshot', $v2->body);
    }

    public function test_marketplace_order_submitted_version_1_survives_untouched(): void
    {
        $template = NotificationTemplate::query()->where('event_name', 'Marketplace order submitted')->sole();
        $v1 = NotificationTemplateVersion::query()->where('template_id', $template->id)->where('version', 1)->sole();

        $this->assertSame(1, $v1->version);
        $this->assertStringContainsString('Matrix snapshot', $v1->body);
    }

    /**
     * The trigger from `2026_08_09_100010` still fires against version 1
     * after this migration — it was never bypassed or weakened.
     */
    public function test_the_immutability_trigger_still_rejects_mutating_version_1_via_the_model(): void
    {
        [, $v1] = $this->vendorAcceptedRejected();

        $this->expectException(NotificationTemplateVersionIsImmutableException::class);

        $v1->update(['body' => 'Changed body']);
    }

    /**
     * The same trigger equally protects the NEW version-2 row this
     * migration inserted — it is not a "version 1 only" guard, per the
     * trigger's own unconditional `BEFORE UPDATE OR DELETE ... FOR EACH
     * ROW` definition. Proven here via the query builder (bypassing
     * Eloquent's own guard) so this actually exercises the database
     * trigger, not just the model-level exception.
     */
    public function test_the_immutability_trigger_also_rejects_mutating_the_new_version_2_row_via_the_builder(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The immutability trigger is only asserted against a real PostgreSQL connection.');
        }

        [, , $v2] = $this->vendorAcceptedRejected();

        $this->expectException(QueryException::class);

        DB::table('notification_template_versions')
            ->where('id', $v2->id)
            ->update(['body' => 'Changed by builder']);
    }

    public function test_the_variable_allowlist_and_restricted_fields_are_valid_json_arrays(): void
    {
        [, , $v2] = $this->vendorAcceptedRejected();

        $this->assertIsArray($v2->variable_allowlist);
        $this->assertIsArray($v2->restricted_fields);
        $this->assertNotEmpty($v2->variable_allowlist);
        $this->assertSame(
            ['ktp', 'kk', 'death_certificate', 'bank_details', 'full_address'],
            $v2->restricted_fields
        );
    }

    /**
     * Every other matrix event not touched by this batch keeps exactly one
     * version row and stays active on it — no collateral damage.
     */
    public function test_an_untouched_event_still_has_exactly_one_version(): void
    {
        $template = NotificationTemplate::query()->where('event_name', 'Renewal submitted')->sole();
        $versionCount = NotificationTemplateVersion::query()->where('template_id', $template->id)->count();

        $this->assertSame(1, $versionCount);
        $this->assertSame(
            1,
            NotificationTemplateVersion::query()->whereKey($template->active_version_id)->value('version')
        );
    }
}
