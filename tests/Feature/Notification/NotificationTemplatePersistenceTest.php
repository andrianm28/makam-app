<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Platform\Notification\Contracts\NotificationMatrixSource;
use App\Platform\Notification\Exceptions\NotificationTemplateVersionIsImmutableException;
use App\Platform\Notification\Models\NotificationTemplate;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_notification_schema_has_json_variable_snapshots_and_no_version_update_timestamp(): void
    {
        $this->assertTrue(Schema::hasColumn('notification_templates', 'event_name'));
        $this->assertTrue(Schema::hasColumn('notification_templates', 'default_channel'));
        $this->assertTrue(Schema::hasColumn('notification_templates', 'active_version_id'));
        $this->assertTrue(Schema::hasColumn('notification_template_versions', 'variable_allowlist'));
        $this->assertTrue(Schema::hasColumn('notification_template_versions', 'restricted_fields'));
        $this->assertFalse(Schema::hasColumn('notification_template_versions', 'updated_at'));
    }

    public function test_the_matrix_seed_covers_every_matrix_event_with_one_active_version(): void
    {
        $matrixEvents = array_column((new NotificationMatrixSource)->rows(), 'event');
        $seededEvents = NotificationTemplate::query()->pluck('event_name')->all();

        sort($matrixEvents);
        sort($seededEvents);

        $this->assertSame($matrixEvents, $seededEvents);
        $this->assertSame(17, NotificationTemplateVersion::query()->count());
        $this->assertSame(17, NotificationTemplate::query()->whereNotNull('active_version_id')->count());
    }

    private function template(string $eventName): NotificationTemplate
    {
        $template = new NotificationTemplate;
        $template->forceFill([
            'event_name' => $eventName,
            'default_channel' => 'EMAIL',
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
