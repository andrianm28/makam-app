<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Platform\Notification\Contracts\NotificationMatrixSource;
use App\Platform\Notification\Exceptions\NotificationTemplateVersionIsImmutableException;
use App\Platform\Notification\Models\NotificationTemplate;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class NotificationTemplatePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_models_are_guarded_against_mass_assignment(): void
    {
        $this->assertSame(['*'], (new NotificationTemplate)->getGuarded());
        $this->assertSame(['*'], (new NotificationTemplateVersion)->getGuarded());
    }

    public function test_a_new_version_does_not_change_the_existing_snapshot(): void
    {
        $template = $this->template('Snapshot event');
        $first = $this->version($template, 'Original body');

        $second = $this->version($template, 'Revised body', version: 2);

        $this->assertSame('Original body', $first->fresh()->body);
        $this->assertSame('Revised body', $second->fresh()->body);
        $this->assertSame(1, $first->fresh()->version);
    }

    public function test_a_persisted_template_version_cannot_be_updated_or_deleted(): void
    {
        $template = $this->template('Immutable event');
        $version = $this->version($template, 'Immutable body');

        $this->expectException(NotificationTemplateVersionIsImmutableException::class);

        $version->update(['body' => 'Changed body']);
    }

    public function test_a_persisted_template_version_cannot_be_saved_or_deleted(): void
    {
        $template = $this->template('Immutable save event');
        $version = $this->version($template, 'Immutable body');

        try {
            $version->forceFill(['body' => 'Changed body'])->save();
            $this->fail('Saving an existing notification template version should throw.');
        } catch (NotificationTemplateVersionIsImmutableException) {
            // expected
        }

        try {
            $version->delete();
            $this->fail('Deleting a notification template version should throw.');
        } catch (NotificationTemplateVersionIsImmutableException) {
            // expected
        }

        $this->assertDatabaseHas('notification_template_versions', [
            'id' => $version->id,
            'body' => 'Immutable body',
        ]);
    }

    public function test_a_query_builder_update_cannot_change_a_template_version(): void
    {
        $template = $this->template('Builder update event');
        $version = $this->version($template, 'Immutable body');

        $this->expectException(QueryException::class);

        NotificationTemplateVersion::query()
            ->whereKey($version->id)
            ->update(['body' => 'Changed by builder']);
    }

    public function test_a_query_builder_delete_cannot_remove_a_template_version(): void
    {
        $template = $this->template('Builder delete event');
        $version = $this->version($template, 'Immutable body');

        $this->expectException(QueryException::class);

        NotificationTemplateVersion::query()->whereKey($version->id)->delete();
    }

    public function test_a_template_with_versions_cannot_be_deleted_by_model_or_builder(): void
    {
        $template = $this->template('Parent deletion event');
        $this->version($template, 'Immutable body');

        try {
            $template->delete();
            $this->fail('Deleting a template with versions should be restricted.');
        } catch (QueryException) {
            // expected
        }

        $this->expectException(QueryException::class);

        NotificationTemplate::query()->whereKey($template->id)->delete();
    }

    public function test_an_active_version_must_belong_to_its_template(): void
    {
        $owner = $this->template('Active owner');
        $other = $this->template('Different owner');
        $otherVersion = $this->version($other, 'Other template body');

        $this->expectException(QueryException::class);

        $owner->forceFill(['active_version_id' => $otherVersion->id])->save();
    }

    public function test_the_template_version_index_is_partial_on_non_null_versions(): void
    {
        $indexDefinition = DB::connection()->getDriverName() === 'pgsql'
            ? DB::table('pg_indexes')
                ->where('tablename', 'notification_template_versions')
                ->where('indexname', 'notification_template_versions_template_version_unique')
                ->value('indexdef')
            : DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('name', 'notification_template_versions_template_version_unique')
                ->value('sql');

        // PostgreSQL's pg_get_indexdef() reconstructs the predicate wrapped in
        // parentheses ("where (version is not null)"); SQLite's stored CREATE
        // INDEX text carries the clause verbatim, unparenthesized. Strip
        // parens so the same assertion holds for both catalogue formats.
        $normalizedDefinition = str_replace(['(', ')'], '', strtolower((string) $indexDefinition));

        $this->assertStringContainsString('where version is not null', $normalizedDefinition);
    }

    public function test_matrix_rows_without_external_channels_have_no_default_channel(): void
    {
        $this->assertNull(NotificationTemplate::query()->where('event_name', 'Booking draft created')->value('default_channel'));
        $this->assertNull(NotificationTemplate::query()->where('event_name', 'Quote accepted')->value('default_channel'));
        $this->assertSame('EMAIL', NotificationTemplate::query()->where('event_name', 'Booking submitted')->value('default_channel'));
        $this->assertSame('EMAIL', NotificationTemplate::query()->where('event_name', 'Quote issued')->value('default_channel'));
    }

    public function test_matrix_seed_up_is_idempotent_and_reconciles_existing_rows(): void
    {
        $draftTemplate = NotificationTemplate::query()->where('event_name', 'Booking draft created')->sole();
        $draftVersionId = $draftTemplate->active_version_id;
        $templateCount = NotificationTemplate::query()->count();
        $versionCount = NotificationTemplateVersion::query()->count();

        // Simulate a prior seed that used the old external fallback and a
        // partial rerun that lost the active pointer and corrupted the
        // outbox event key.
        DB::table('notification_templates')
            ->where('id', $draftTemplate->id)
            ->update(['default_channel' => 'EMAIL', 'active_version_id' => null]);

        DB::table('notification_templates')
            ->where('event_name', 'Booking submitted')
            ->update(['outbox_event_name' => 'stale.value.v0']);

        DB::table('migrations')
            ->where('migration', '2026_08_09_100020_seed_notification_templates_from_matrix')
            ->delete();

        $seed = require base_path('database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php');
        $seed->up();

        $this->assertSame($templateCount, NotificationTemplate::query()->count());
        $this->assertSame($versionCount, NotificationTemplateVersion::query()->count());
        $this->assertNull($draftTemplate->fresh()->default_channel);
        $this->assertSame($draftVersionId, $draftTemplate->fresh()->active_version_id);
        $this->assertSame('EMAIL', NotificationTemplate::query()->where('event_name', 'Booking submitted')->value('default_channel'));
        $this->assertSame(
            'booking.draft_submitted.v2',
            NotificationTemplate::query()->where('event_name', 'Booking submitted')->value('outbox_event_name')
        );
    }

    public function test_only_the_twelve_ruled_rows_carry_an_outbox_event_name(): void
    {
        $mapped = [
            'Booking submitted' => 'booking.draft_submitted.v2',
            'Availability requested' => 'availability.requested.v1',
            'Availability confirmed/rejected' => 'availability.confirmed.v2',
            'Quote issued' => 'quote.issued.v1',
            'Quote accepted' => 'quote.accepted.v1',
            'Payment received' => 'payment.received.v1',
            'Payment failed/exception' => 'payment.outcome_failed.v1',
            'Marketplace order submitted' => 'marketplace_order.submitted.v1',
            'Vendor accepted/rejected' => 'vendor_order.decided.v1',
            'Vendor evidence uploaded' => 'vendor.evidence_uploaded.v1',
            'Renewal submitted' => 'renewal.submitted.v1',
            'Renewal paid/verified' => 'renewal.paid_online.v1',
        ];

        foreach ($mapped as $eventName => $outboxEventName) {
            $this->assertSame(
                $outboxEventName,
                NotificationTemplate::query()->where('event_name', $eventName)->value('outbox_event_name'),
                "Expected {$eventName} to carry outbox_event_name {$outboxEventName}."
            );
        }

        $unmapped = [
            'Booking draft created',
            'Payment opened',
            'Order processing',
            'Order completed',
            'Reminder due',
        ];

        foreach ($unmapped as $eventName) {
            $this->assertNull(
                NotificationTemplate::query()->where('event_name', $eventName)->value('outbox_event_name'),
                "Expected {$eventName} to have a NULL outbox_event_name."
            );
        }
    }

    public function test_notification_schema_has_json_variable_snapshots_and_no_version_update_timestamp(): void
    {
        $defaultChannel = array_values(array_filter(
            Schema::getColumns('notification_templates'),
            static fn (array $column): bool => $column['name'] === 'default_channel',
        ))[0];

        $outboxEventName = array_values(array_filter(
            Schema::getColumns('notification_templates'),
            static fn (array $column): bool => $column['name'] === 'outbox_event_name',
        ))[0];

        $this->assertTrue(Schema::hasColumn('notification_templates', 'event_name'));
        $this->assertTrue(Schema::hasColumn('notification_templates', 'default_channel'));
        $this->assertTrue(Schema::hasColumn('notification_templates', 'active_version_id'));
        $this->assertTrue(Schema::hasColumn('notification_templates', 'outbox_event_name'));
        $this->assertTrue($defaultChannel['nullable']);
        $this->assertTrue($outboxEventName['nullable']);
        $this->assertTrue(Schema::hasColumn('notification_template_versions', 'variable_allowlist'));
        $this->assertTrue(Schema::hasColumn('notification_template_versions', 'restricted_fields'));
        $this->assertFalse(Schema::hasColumn('notification_template_versions', 'updated_at'));
    }

    public function test_the_matrix_seed_covers_every_matrix_event_with_one_active_version(): void
    {
        $matrixRows = (new NotificationMatrixSource)->rows();
        $matrixEvents = array_column($matrixRows, 'event');
        $seededEvents = NotificationTemplate::query()->pluck('event_name')->all();

        sort($matrixEvents);
        sort($seededEvents);

        $this->assertSame($matrixEvents, $seededEvents);
        $this->assertSame(count($matrixRows), NotificationTemplateVersion::query()->count());
        $this->assertSame(count($matrixRows), NotificationTemplate::query()->whereNotNull('active_version_id')->count());

        foreach ($matrixRows as $row) {
            $template = NotificationTemplate::query()->where('event_name', $row['event'])->sole();
            $version = NotificationTemplateVersion::query()->where('template_id', $template->id)->sole();

            $this->assertSame($version->id, $template->active_version_id);
            $this->assertSame($this->matrixDefaultChannel($row['recipients']), $template->default_channel);
            $this->assertSame($this->matrixOutboxEventName($row['event']), $template->outbox_event_name);

            foreach ($row['recipients'] as $recipient => $channelFact) {
                $this->assertStringContainsString($recipient.': '.$channelFact, $version->body);
            }
        }
    }

    /**
     * @param  array<string, string>  $recipients
     */
    private function matrixDefaultChannel(array $recipients): ?string
    {
        $facts = implode(' ', $recipients);

        if (str_contains($facts, 'EMAIL')) {
            return 'EMAIL';
        }

        return str_contains($facts, 'WA') ? 'WA' : null;
    }

    /**
     * Ruling 1's approved refinement (`docs/superpowers/plans/2026-08-10-
     * wave1a-notifications-decisions.md`), extended by the 24 Aug 2026
     * MEDIUM-row batch and the 25 Aug 2026 `renewal-online-payment` lane:
     * only these 12 matrix rows have a clean, non-ambiguous catalogue
     * counterpart. Mirrors — does not duplicate as canonical data — the
     * mapping the seed migration applies, so this test can independently
     * confirm every other row stays NULL.
     */
    private function matrixOutboxEventName(string $eventName): ?string
    {
        return match ($eventName) {
            'Booking submitted' => 'booking.draft_submitted.v2',
            'Availability requested' => 'availability.requested.v1',
            'Availability confirmed/rejected' => 'availability.confirmed.v2',
            'Quote issued' => 'quote.issued.v1',
            'Quote accepted' => 'quote.accepted.v1',
            'Payment received' => 'payment.received.v1',
            'Payment failed/exception' => 'payment.outcome_failed.v1',
            'Marketplace order submitted' => 'marketplace_order.submitted.v1',
            'Vendor accepted/rejected' => 'vendor_order.decided.v1',
            'Vendor evidence uploaded' => 'vendor.evidence_uploaded.v1',
            'Renewal submitted' => 'renewal.submitted.v1',
            'Renewal paid/verified' => 'renewal.paid_online.v1',
            default => null,
        };
    }

    private function template(string $eventName, ?string $defaultChannel = 'EMAIL'): NotificationTemplate
    {
        $template = new NotificationTemplate;
        $template->forceFill([
            'event_name' => $eventName,
            'default_channel' => $defaultChannel,
        ])->save();

        return $template;
    }

    private function version(NotificationTemplate $template, string $body, int $version = 1): NotificationTemplateVersion
    {
        $row = new NotificationTemplateVersion;
        $row->forceFill([
            'template_id' => $template->id,
            'version' => $version,
            'subject' => $template->event_name,
            'body' => $body,
            'variable_allowlist' => [],
            'restricted_fields' => [],
            'created_by' => 'test',
            'created_at' => now(),
        ])->save();

        return $row;
    }
}
