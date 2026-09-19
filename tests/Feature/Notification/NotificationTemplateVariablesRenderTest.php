<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\Channels\MailChannel;
use App\Platform\Notification\Channels\RenderedNotificationMailable;
use App\Platform\Notification\Contracts\Channel;
use App\Platform\Notification\Contracts\NotificationSubjectSource;
use App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob;
use App\Platform\Notification\Models\InAppNotification;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\RecipientResolutionSubject;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AC15 (platform-notifications): a value carried by the outbox payload
 * reaches the rendered body through the version's allowlist. Before this
 * plan every render call passed `[]`, so no template could carry a value
 * — this test is the one that would have failed then.
 *
 * ---------------------------------------------------------------------------
 * Two render call shapes, two tests
 * ---------------------------------------------------------------------------
 * `NotificationVariableResolver` has two entry points because the two call
 * shapes hold different things: `Actions\DispatchNotification` holds the
 * fresh `OutboxEvent` row (`forOutboxRow()`), while a `Contracts\Channel`
 * implementation holds only the `NotificationDelivery` and must re-find the
 * outbox row from it (`forDelivery()`). A test that only walks the in-app
 * path proves the first and nothing about the second, so the mail-channel
 * test below drives a REAL `Channels\MailChannel::send()`.
 *
 * The first test renders the SHIPPED active version of "Marketplace order
 * submitted"; the second renders a version this test inserts itself. That
 * split is deliberate — see each test's own note.
 */
final class NotificationTemplateVariablesRenderTest extends TestCase
{
    use RefreshDatabase;

    private const MATRIX_EVENT = 'Marketplace order submitted';

    /**
     * The shipped version-2 template body uses SINGLE braces (`{order_id}`)
     * while `TemplateRenderer::PLACEHOLDER_PATTERN` matches `{{ order_id }}`
     * only, so this test stays red until Task 4 ships a version whose body
     * uses the real placeholder syntax. That is the point: this is the
     * acceptance test for the shipped copy, and the renderer's pattern is
     * not to be widened to make it pass.
     */
    public function test_a_payload_value_renders_into_the_in_app_body_through_the_allowlist(): void
    {
        $this->seedPlatformAdminRecipient();

        $outboxEventId = Outbox::record(
            eventName: 'marketplace_order.submitted.v1',
            eventVersion: 1,
            aggregateType: 'marketplace_order',
            aggregateId: (string) Str::uuid(),
            data: ['order_id' => 'MO-TEST-42'],
            classification: OutboxClassification::Internal,
        )->getKey();

        ConsumeOutboxNotificationJob::dispatchSync($outboxEventId, matrixEventName: self::MATRIX_EVENT);

        $body = InAppNotification::query()->latest('id')->value('body');

        self::assertIsString($body);
        self::assertStringContainsString('MO-TEST-42', $body);
        self::assertStringNotContainsString('{order_id}', $body, 'single-brace placeholders must not ship as literal text');
        self::assertStringNotContainsString('{{', $body);
    }

    /**
     * `forDelivery()` — the channel-side entry point — against a real
     * `Channels\MailChannel::send()` reached through the ordinary
     * consume-then-send flow (`QUEUE_CONNECTION=sync`, so
     * `Jobs\SendNotificationChannelJob` runs inside `dispatchSync()`).
     *
     * This one does NOT depend on Task 4: it activates a template version
     * this test inserts, whose body uses the real `{{ order_id }}` syntax,
     * so the resolver -> renderer -> mailable chain is proved green TODAY.
     * Nothing updates an existing version row (the
     * `notification_template_versions` immutability trigger forbids it);
     * only `notification_templates.active_version_id`, an ordinary column,
     * is repointed.
     */
    public function test_a_payload_value_reaches_a_real_mail_channel_send_through_for_delivery(): void
    {
        Mail::fake();
        $this->app->bind(Channel::class, MailChannel::class);

        $customer = DB::table('users')->insertGetId([
            'name' => 'Pelanggan Uji',
            'email' => 'pelanggan.uji@example.test',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->activateDoubleBracePlaceholderVersion();
        $this->seedPlatformAdminRecipient(ownerRef: (string) $customer);

        $outboxEventId = Outbox::record(
            eventName: 'marketplace_order.submitted.v1',
            eventVersion: 1,
            aggregateType: 'marketplace_order',
            aggregateId: (string) Str::uuid(),
            data: ['order_id' => 'MO-TEST-77'],
            classification: OutboxClassification::Internal,
        )->getKey();

        ConsumeOutboxNotificationJob::dispatchSync($outboxEventId, matrixEventName: self::MATRIX_EVENT);

        self::assertTrue(
            NotificationDelivery::query()->where('event_id', $outboxEventId)->where('channel', 'EMAIL')->exists(),
            'The fixture must produce an EMAIL delivery, otherwise no channel send happened at all.',
        );

        Mail::assertSent(function (RenderedNotificationMailable $mailable): bool {
            $html = (string) $mailable->render();

            return str_contains($html, 'MO-TEST-77') && ! str_contains($html, '{{');
        });
    }

    /**
     * Copied from `NotificationDispatchPipelineTest::
     * test_ac7_platform_admin_recipient_gets_an_in_app_record()` — a
     * `ScopeAssignment` on the platform `business_entity` scope is what
     * makes the admin an unconditional IN_APP recipient, and the subject
     * source is replaced so no `marketplace_orders` row is needed.
     */
    private function seedPlatformAdminRecipient(string $ownerRef = 'customer-1'): void
    {
        $this->bindWhatsAppMode(open: true);

        ScopeAssignment::query()->create([
            'actor_identifier' => 'admin-1',
            'entity_type' => ScopeEntityType::BUSINESS_ENTITY,
            'entity_id' => 'business-entity-1',
        ]);

        $this->bindSubject(ownerRef: $ownerRef, scopeType: ScopeEntityType::BUSINESS_ENTITY, scopeId: 'business-entity-1');
    }

    private function activateDoubleBracePlaceholderVersion(): void
    {
        $templateId = DB::table('notification_templates')->where('event_name', self::MATRIX_EVENT)->value('id');
        self::assertNotNull($templateId, 'The matrix row must be seeded by the template migration.');

        $versionId = DB::table('notification_template_versions')->insertGetId([
            'template_id' => $templateId,
            'version' => 99,
            'subject' => 'Pesanan {{ order_id }} kami terima',
            'body' => 'Terima kasih, pesanan Anda dengan nomor {{ order_id }} telah kami terima.',
            'variable_allowlist' => json_encode(['order_id'], JSON_THROW_ON_ERROR),
            'restricted_fields' => json_encode(['ktp', 'kk', 'death_certificate', 'bank_details', 'full_address'], JSON_THROW_ON_ERROR),
            'created_by' => 'test:notification-template-variables',
            'created_at' => now(),
        ]);

        DB::table('notification_templates')->where('id', $templateId)->update(['active_version_id' => $versionId]);
    }

    private function bindWhatsAppMode(bool $open): void
    {
        $source = new class($open) implements GateRegistrySource
        {
            public function __construct(private readonly bool $open) {}

            public function load(): GateRegistrySnapshot
            {
                return new GateRegistrySnapshot(['G-WA-01' => GateState::fromRecord('G-WA-01', open: $this->open)]);
            }
        };

        $this->app->instance(ModeResolver::class, new ModeResolver(new FeatureGateResolver($source)));
    }

    private function bindSubject(int|string $ownerRef, string $scopeType, int|string $scopeId): void
    {
        $this->app->instance(NotificationSubjectSource::class, new class($ownerRef, $scopeType, $scopeId) implements NotificationSubjectSource
        {
            public function __construct(
                private readonly int|string $ownerRef,
                private readonly string $scopeType,
                private readonly int|string $scopeId,
            ) {}

            public function subjectFor(string $aggregateType, int|string $aggregateId): ?RecipientResolutionSubject
            {
                return new RecipientResolutionSubject($this->ownerRef, $this->scopeType, $this->scopeId);
            }
        });
    }
}
