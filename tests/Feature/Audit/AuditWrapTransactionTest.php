<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * AC4: "WHEN a state change is committed THE SYSTEM SHALL write its
 * audit record in the same database transaction, such that no
 * committed state change exists without a corresponding audit
 * record." This is the test `Audit::wrap()`'s own doc block points
 * at as proof.
 *
 * Uses `App\Models\User` (the existing base Laravel scaffold model,
 * not a new one) as a stand-in mutation target — this batch owns no
 * domain model of its own to mutate, and `Audit::wrap()` must work
 * against ANY Eloquent model, so a generic, already-present one proves
 * the point without inventing domain-specific fixtures this batch has
 * no spec authority over.
 */
final class AuditWrapTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrap_rolls_back_the_mutation_when_the_audit_write_itself_fails(): void
    {
        $user = User::factory()->create(['name' => 'Original Name']);

        try {
            Audit::wrap(
                mutation: function () use ($user) {
                    $user->forceFill(['name' => 'Changed Name'])->save();

                    return $user;
                },
                // Sensitive action, no $reason supplied below — forces
                // Audit::record() to throw AFTER the mutation closure
                // has already run and modified the row, but still
                // inside the same DB::transaction().
                action: 'DITOLAK',
                subject: fn (User $result): AuditSubject => new AuditSubject(type: 'user', id: $result->id),
                outcome: AuditOutcome::Denied,
                actorRef: $user->id,
                actorRole: 'admin',
                source: AuditSource::Panel,
            );

            $this->fail('Expected AuditReasonRequiredException to be thrown.');
        } catch (AuditReasonRequiredException) {
            // Expected — this is the simulated audit-write failure.
        }

        // The mutation's own database change must have been rolled
        // back along with the audit write that never happened. This
        // is the actual proof of AC4: without wrap()'s shared
        // transaction, the User::save() above would have already
        // committed on its own before Audit::record() ever ran.
        $this->assertSame('Original Name', $user->fresh()->name);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_wrap_rolls_back_the_mutation_when_the_mutation_itself_throws(): void
    {
        $user = User::factory()->create(['name' => 'Original Name']);

        try {
            Audit::wrap(
                mutation: function () use ($user) {
                    $user->forceFill(['name' => 'Changed Name'])->save();

                    throw new RuntimeException('simulated mutation failure');
                },
                action: 'profile.updated',
                subject: fn (): AuditSubject => new AuditSubject(type: 'user', id: $user->id),
                outcome: AuditOutcome::Failed,
                actorRef: $user->id,
                actorRole: 'admin',
                source: AuditSource::Panel,
            );

            $this->fail('Expected RuntimeException to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated mutation failure', $exception->getMessage());
        }

        $this->assertSame('Original Name', $user->fresh()->name);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_wrap_commits_both_the_mutation_and_the_audit_record_together_on_success(): void
    {
        $user = User::factory()->create(['name' => 'Original Name']);

        $result = Audit::wrap(
            mutation: function () use ($user) {
                $user->forceFill(['name' => 'Changed Name'])->save();

                return $user;
            },
            action: 'profile.updated',
            subject: fn (User $result): AuditSubject => new AuditSubject(type: 'user', id: $result->id),
            outcome: AuditOutcome::Allowed,
            actorRef: $user->id,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertSame('Changed Name', $user->fresh()->name);
        $this->assertSame(1, AuditEvent::query()->count());
        $this->assertSame($user->id, $result->id);

        $auditEvent = AuditEvent::query()->sole();
        $this->assertSame('user', $auditEvent->subject_type);
        $this->assertSame((string) $user->id, $auditEvent->subject_id);
    }

    public function test_wrap_accepts_a_subject_known_upfront_instead_of_a_closure(): void
    {
        // The AuditSubject|Closure union's non-closure branch — for
        // mutations where the subject's identity is already known
        // before the mutation runs (e.g. updating an existing
        // record), a caller need not wrap it in a closure at all.
        $user = User::factory()->create(['name' => 'Original Name']);

        Audit::wrap(
            mutation: fn () => $user->forceFill(['name' => 'Changed Name'])->save(),
            action: 'profile.updated',
            subject: new AuditSubject(type: 'user', id: $user->id),
            outcome: AuditOutcome::Allowed,
            actorRef: $user->id,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertSame(1, AuditEvent::query()->count());
    }
}
