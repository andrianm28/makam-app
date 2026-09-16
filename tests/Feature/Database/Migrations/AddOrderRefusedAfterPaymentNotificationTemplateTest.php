<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `2026_09_13_120000_add_order_refused_after_payment_notification_template.php`.
 *
 * ---------------------------------------------------------------------------
 * What this covers that `OrderNotificationTest` cannot
 * ---------------------------------------------------------------------------
 * A test run always starts from a FRESH `migrate`, and
 * `2026_08_09_100020_seed_notification_templates_from_matrix.php` re-reads
 * the matrix file at migration time — so on a fresh database the new row is
 * created by the seed migration and this one has nothing left to do.
 * `OrderNotificationTest::test_refusal_after_payment_notification_uses_its_own_matrix_row`
 * therefore proves the fresh path and only the fresh path.
 *
 * The path that actually needs this migration is the opposite one: dev, beta,
 * and eventually production, where the seed migration ran months ago and will
 * never run again. This test reproduces that state by removing the row the
 * fresh migrate created and then running the migration, which is the only way
 * to exercise the branch those environments will take.
 */
final class AddOrderRefusedAfterPaymentNotificationTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const MATRIX_EVENT_NAME = 'Order refused after payment';

    public function test_it_creates_the_template_on_a_database_that_already_ran_the_seed(): void
    {
        $this->removeTemplateAsIfTheSeedHadNeverCoveredIt();

        $this->runMigration();

        $template = DB::table('notification_templates')
            ->where('event_name', self::MATRIX_EVENT_NAME)
            ->first();

        self::assertNotNull($template, 'The refusal-after-payment template was not created.');
        self::assertNotNull($template->active_version_id, 'The template has no active version to render.');

        // Recipient facts come from the matrix row, never retyped in the
        // migration: the Customer column reads `EMAIL/WA`, so EMAIL is the
        // default channel.
        self::assertSame('EMAIL', $template->default_channel);

        // NULL on purpose — this row is reached by status discrimination on
        // `order.status_changed.v1`, exactly like `Order processing`, and no
        // new event name is invented for it.
        self::assertNull($template->outbox_event_name);
    }

    public function test_rerunning_it_neither_duplicates_the_row_nor_rewrites_version_one(): void
    {
        $this->removeTemplateAsIfTheSeedHadNeverCoveredIt();
        $this->runMigration();

        $versionBefore = DB::table('notification_template_versions')
            ->join('notification_templates', 'notification_templates.id', '=', 'notification_template_versions.template_id')
            ->where('notification_templates.event_name', self::MATRIX_EVENT_NAME)
            ->where('notification_template_versions.version', 1)
            ->select('notification_template_versions.id', 'notification_template_versions.body')
            ->first();

        $this->runMigration();

        self::assertSame(1, DB::table('notification_templates')
            ->where('event_name', self::MATRIX_EVENT_NAME)
            ->count());

        $versionAfter = DB::table('notification_template_versions')
            ->join('notification_templates', 'notification_templates.id', '=', 'notification_template_versions.template_id')
            ->where('notification_templates.event_name', self::MATRIX_EVENT_NAME)
            ->select('notification_template_versions.id', 'notification_template_versions.body')
            ->get();

        self::assertCount(1, $versionAfter, 'A rerun added a second version row.');
        self::assertSame($versionBefore->id, $versionAfter->first()->id);
        self::assertSame($versionBefore->body, $versionAfter->first()->body, 'Version 1 is immutable and must never be rewritten.');
    }

    /**
     * The version-1 body is a snapshot of the matrix row's recipient facts,
     * read through `NotificationMatrixSource` rather than retyped into the
     * migration — `AGENTS.md` §Documentation: canonical catalogue data is not
     * duplicated. Asserting the facts appear proves the read actually
     * happened, rather than a hardcoded string happening to look right.
     */
    public function test_version_one_snapshots_the_matrix_row_rather_than_a_retyped_copy(): void
    {
        $this->removeTemplateAsIfTheSeedHadNeverCoveredIt();
        $this->runMigration();

        $body = DB::table('notification_template_versions')
            ->join('notification_templates', 'notification_templates.id', '=', 'notification_template_versions.template_id')
            ->where('notification_templates.event_name', self::MATRIX_EVENT_NAME)
            ->value('notification_template_versions.body');

        self::assertStringContainsString('Customer: EMAIL/WA', (string) $body);
        self::assertStringContainsString('Admin platform: IN_APP', (string) $body);
        self::assertStringContainsString('Pengelola TPU/TPS: IN_APP', (string) $body);
        self::assertStringContainsString('Vendor: none', (string) $body);
    }

    /**
     * Reproduces the state of an already-migrated database — one where the
     * seed migration ran long before this matrix row existed, so no template
     * carries this `event_name`.
     *
     * Renaming rather than deleting is not a convenience: `notification_
     * template_versions` rows are made genuinely immutable by the PostgreSQL
     * trigger `notification_template_versions_prevent_mutation()`, so the
     * fresh-migrate row cannot be removed at all. `event_name` is UNIQUE, so
     * moving the existing row out of the way leaves the migration's own
     * `where('event_name', ...)` lookup finding nothing — which is exactly
     * the condition those databases will present, reached without weakening
     * a real control to get there.
     */
    private function removeTemplateAsIfTheSeedHadNeverCoveredIt(): void
    {
        DB::table('notification_templates')
            ->where('event_name', self::MATRIX_EVENT_NAME)
            ->update(['event_name' => 'Archived for test: '.self::MATRIX_EVENT_NAME]);
    }

    private function runMigration(): void
    {
        $this->migration()->up();
    }

    private function migration(): object
    {
        return require base_path($this->migrationPath());
    }

    private function migrationPath(): string
    {
        return 'database/migrations/2026_09_13_120000_add_order_refused_after_payment_notification_template.php';
    }
}
