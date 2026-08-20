<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Vendor;

use App\Filament\Vendor\Pages\Profile;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `App\Filament\Vendor\Pages\Profile` — self-service account editing for
 * the currently-authenticated `/vendor` user. `withoutVite()` follows
 * `EditSiteSettingsSmokeTest`'s own precedent: this page's Blade view
 * renders `<x-filament-panels::page>`, which resolves panel assets through
 * Vite, and this repo's own CLAUDE.md forbids running `npm run build`/full
 * `composer install` on this host (build assets aren't compiled here).
 *
 * The core access-control property this page's design gives structurally,
 * not by query scope: it reads `Auth::user()` directly and never accepts a
 * route/record id, so there is no id parameter through which one user could
 * even address another user's account. `test_saving_never_touches_another_users_row`
 * is the behavioural proof of that property, mirroring
 * `VendorPanelScopingTest`'s "one actor's write never reaches another's row"
 * spirit for this page's own (structurally narrower) surface.
 */
final class ProfilePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_user_sees_their_own_account_prefilled(): void
    {
        $user = User::factory()->create(['name' => 'Vendor Satu', 'email' => 'vendor-satu@example.test']);

        Livewire::actingAs($user)
            ->test(Profile::class)
            ->assertFormFieldExists('name')
            ->assertFormFieldExists('email')
            ->assertSet('data.name', 'Vendor Satu')
            ->assertSet('data.email', 'vendor-satu@example.test');
    }

    public function test_a_user_can_update_their_own_name_and_email(): void
    {
        $user = User::factory()->create(['name' => 'Nama Lama', 'email' => 'lama@example.test']);

        Livewire::actingAs($user)
            ->test(Profile::class)
            ->set('data.name', 'Nama Baru')
            // Changing the email also requires the current password — the
            // `currentPassword` field's own `visible()` condition (mirroring
            // Filament's stock `EditProfile`) fires on an email change too,
            // not just a password change, since the account's email doubles
            // as its recovery/login identifier.
            ->set('data.email', 'baru@example.test')
            ->set('data.currentPassword', 'password')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified();

        $user->refresh();
        $this->assertSame('Nama Baru', $user->name);
        $this->assertSame('baru@example.test', $user->email);

        $event = AuditEvent::query()->where('action', 'VENDOR_PROFILE_UPDATED')->first();
        $this->assertNotNull($event);
        $this->assertSame('user', $event->subject_type);
        $this->assertSame((string) $user->id, (string) $event->subject_id);
    }

    /**
     * Structural proof: this page has no route/record id at all, so nothing
     * a request sends can make `save()` write to any row other than
     * `Auth::user()`'s own — there is no id parameter to tamper with.
     */
    public function test_saving_never_touches_another_users_row(): void
    {
        $actingUser = User::factory()->create(['name' => 'Vendor A', 'email' => 'a@example.test']);
        $otherUser = User::factory()->create(['name' => 'Vendor B', 'email' => 'b@example.test']);

        Livewire::actingAs($actingUser)
            ->test(Profile::class)
            ->set('data.name', 'Vendor A Diubah')
            ->call('save')
            ->assertHasNoErrors();

        $actingUser->refresh();
        $otherUser->refresh();

        $this->assertSame('Vendor A Diubah', $actingUser->name);
        $this->assertSame('Vendor B', $otherUser->name);
        $this->assertSame('b@example.test', $otherUser->email);
    }

    public function test_email_must_be_unique_across_users(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);
        $user = User::factory()->create(['email' => 'mine@example.test']);

        Livewire::actingAs($user)
            ->test(Profile::class)
            ->set('data.email', 'taken@example.test')
            ->call('save')
            ->assertHasErrors(['data.email']);

        $this->assertSame('mine@example.test', $user->fresh()->email);
    }

    public function test_changing_password_requires_the_correct_current_password(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;

        Livewire::actingAs($user)
            ->test(Profile::class)
            ->set('data.password', 'a-new-strong-password')
            ->set('data.passwordConfirmation', 'a-new-strong-password')
            ->set('data.currentPassword', 'wrong-password')
            ->call('save')
            ->assertHasErrors(['data.currentPassword']);

        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertNull(AuditEvent::query()->where('action', 'VENDOR_PASSWORD_CHANGED')->first());
    }

    public function test_changing_password_requires_matching_confirmation(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;

        Livewire::actingAs($user)
            ->test(Profile::class)
            ->set('data.password', 'a-new-strong-password')
            ->set('data.passwordConfirmation', 'does-not-match')
            ->set('data.currentPassword', 'password')
            ->call('save')
            ->assertHasErrors(['data.password']);

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_a_user_can_change_their_password_with_the_correct_current_password(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Profile::class)
            ->set('data.password', 'a-new-strong-password')
            ->set('data.passwordConfirmation', 'a-new-strong-password')
            ->set('data.currentPassword', 'password')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified()
            ->assertSet('data.password', null)
            ->assertSet('data.passwordConfirmation', null)
            ->assertSet('data.currentPassword', null);

        $this->assertTrue(Hash::check('a-new-strong-password', $user->fresh()->password));

        $event = AuditEvent::query()->where('action', 'VENDOR_PASSWORD_CHANGED')->first();
        $this->assertNotNull($event);
        $this->assertSame('user', $event->subject_type);
        $this->assertSame((string) $user->id, (string) $event->subject_id);
    }

    public function test_leaving_password_fields_blank_does_not_change_the_password(): void
    {
        $user = User::factory()->create(['name' => 'Nama Lama']);
        $originalHash = $user->password;

        Livewire::actingAs($user)
            ->test(Profile::class)
            ->set('data.name', 'Nama Baru')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($originalHash, $user->fresh()->password);
    }
}
