<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\AuditEvents;

use App\Filament\Admin\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Admin\Resources\AuditEvents\Pages\ViewAuditEvent;
use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * `ListAuditEvents`'s table: filters (action, actor, outcome, date range —
 * the four dimensions ADM-100's brief names) and the "no mutation
 * affordance" requirement. Every case here grants `ActorRole::ADMIN`
 * first — the access boundary itself is `AuditEventsResourceAccessTest`'s
 * subject, not this file's.
 */
final class AuditEventsTableTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        return $user;
    }

    public function test_filtering_by_action_narrows_the_table(): void
    {
        $matching = Audit::record(
            action: 'booking.rescheduled',
            subject: new AuditSubject(type: 'booking', id: 1),
            outcome: AuditOutcome::Allowed,
            actorRef: 1,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $other = Audit::record(
            action: 'faq.published',
            subject: new AuditSubject(type: 'faq_article', id: 1),
            outcome: AuditOutcome::Allowed,
            actorRef: 1,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->admin();

        Livewire::test(ListAuditEvents::class)
            ->filterTable('action', ['action' => 'booking'])
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_filtering_by_actor_narrows_the_table(): void
    {
        $matching = Audit::record(
            action: 'booking.updated',
            subject: new AuditSubject(type: 'booking', id: 1),
            outcome: AuditOutcome::Allowed,
            actorRef: 'actor-alpha',
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $other = Audit::record(
            action: 'booking.updated',
            subject: new AuditSubject(type: 'booking', id: 2),
            outcome: AuditOutcome::Allowed,
            actorRef: 'actor-beta',
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->admin();

        Livewire::test(ListAuditEvents::class)
            ->filterTable('actor_ref', ['actor_ref' => 'alpha'])
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_filtering_by_outcome_narrows_the_table(): void
    {
        $denied = Audit::record(
            action: 'booking.updated',
            subject: new AuditSubject(type: 'booking', id: 1),
            outcome: AuditOutcome::Denied,
            actorRef: 1,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $allowed = Audit::record(
            action: 'booking.updated',
            subject: new AuditSubject(type: 'booking', id: 2),
            outcome: AuditOutcome::Allowed,
            actorRef: 1,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->admin();

        Livewire::test(ListAuditEvents::class)
            ->filterTable('outcome', AuditOutcome::Denied->value)
            ->assertCanSeeTableRecords([$denied])
            ->assertCanNotSeeTableRecords([$allowed]);
    }

    public function test_filtering_by_date_range_narrows_the_table(): void
    {
        $inRange = Audit::record(
            action: 'booking.updated',
            subject: new AuditSubject(type: 'booking', id: 1),
            outcome: AuditOutcome::Allowed,
            actorRef: 1,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        // Backdate this one row directly — Audit::record() always stamps
        // "now", and this test needs a row genuinely outside the filtered
        // range rather than a second "now" row that would land inside it
        // too. AuditEvent::update() throws by design (AC1), so the backdate
        // goes through a raw query builder update, not the Eloquent
        // instance — the one path AuditEvent's own guard does not cover
        // (see that model's class-level doc block) and the only way this
        // test can produce an out-of-range fixture at all.
        $outOfRange = Audit::record(
            action: 'booking.updated',
            subject: new AuditSubject(type: 'booking', id: 2),
            outcome: AuditOutcome::Allowed,
            actorRef: 1,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );
        AuditEvent::query()->whereKey($outOfRange->id)->update([
            'occurred_at' => CarbonImmutable::now()->subDays(30),
        ]);

        $this->admin();

        Livewire::test(ListAuditEvents::class)
            ->filterTable('occurred_at', ['occurred_from' => CarbonImmutable::now()->subDay()->toDateString()])
            ->assertCanSeeTableRecords([$inRange])
            ->assertCanNotSeeTableRecords([$outOfRange]);
    }

    public function test_the_list_page_renders_no_create_action(): void
    {
        $this->admin();

        Livewire::test(ListAuditEvents::class)
            ->assertActionDoesNotExist('create');
    }

    public function test_the_view_page_renders_no_edit_action(): void
    {
        $event = Audit::record(
            action: 'booking.updated',
            subject: new AuditSubject(type: 'booking', id: 1),
            outcome: AuditOutcome::Allowed,
            actorRef: 1,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->admin();

        Livewire::test(ViewAuditEvent::class, ['record' => $event->getKey()])
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete');
    }

    public function test_the_view_page_shows_the_full_event_detail(): void
    {
        $event = Audit::record(
            action: 'VENDOR_PAYOUT',
            subject: new AuditSubject(type: 'payout', id: 42),
            outcome: AuditOutcome::Allowed,
            actorRef: 'actor-77',
            actorRole: 'finance',
            source: AuditSource::Panel,
            reason: 'Vendor payout for completed work order.',
            correlationId: 'corr-xyz',
            metadata: ['reference_number' => 'INV-001'],
        );

        $this->admin();

        Livewire::test(ViewAuditEvent::class, ['record' => $event->getKey()])
            ->assertSee('VENDOR_PAYOUT')
            ->assertSee('actor-77')
            ->assertSee('finance')
            ->assertSee('Vendor payout for completed work order.')
            ->assertSee('corr-xyz')
            ->assertSee('reference_number')
            ->assertSee('INV-001');
    }
}
