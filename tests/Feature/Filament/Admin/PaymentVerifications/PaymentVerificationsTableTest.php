<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\PaymentVerifications;

use App\Filament\Admin\Resources\PaymentVerifications\Pages\ListPaymentVerifications;
use App\Filament\Admin\Resources\PaymentVerifications\Pages\ViewPaymentVerification;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\Payment\Models\PaymentVerification;
use App\Platform\Payment\PaymentVerificationDecision;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * `ListPaymentVerifications`/`ViewPaymentVerification`'s table and infolist:
 * real seeded rows render their real field values, newest-`submitted_at`
 * first, and the resource offers no mutation affordance anywhere. Every
 * case here grants `ActorRole::FINANCE` first — the access boundary itself
 * is `PaymentVerificationsResourceAccessTest`'s subject, not this file's.
 */
final class PaymentVerificationsTableTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function finance(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSubmitted(array $overrides = []): PaymentVerification
    {
        return PaymentVerification::createSubmitted(array_merge([
            'reference' => 'order-'.uniqid(),
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'TRX-'.uniqid(),
            'instructions' => 'Transfer ke rekening BCA 123456.',
        ], $overrides));
    }

    public function test_the_list_page_renders_seeded_rows_real_field_values(): void
    {
        $oldest = $this->createSubmitted([
            'reference' => 'order-alpha',
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'TRX-ALPHA',
            'submitted_at' => CarbonImmutable::now()->subDays(2),
        ]);

        $middle = $this->createSubmitted([
            'reference' => 'order-beta',
            'payment_method' => 'e_wallet',
            'payment_reference' => 'TRX-BETA',
            'submitted_at' => CarbonImmutable::now()->subDay(),
        ]);
        $middle->decide(PaymentVerificationDecision::Approve, 'actor-finance-1', 'Bukti transfer sesuai.');

        $newest = $this->createSubmitted([
            'reference' => 'order-gamma',
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'TRX-GAMMA',
            'submitted_at' => CarbonImmutable::now(),
        ]);
        $newest->decide(PaymentVerificationDecision::Reject, 'actor-finance-2', 'Nominal tidak sesuai.');

        $this->finance();

        Livewire::test(ListPaymentVerifications::class)
            ->assertCanSeeTableRecords([$oldest, $middle, $newest])
            ->assertSee('order-alpha')
            ->assertSee('order-beta')
            ->assertSee('order-gamma')
            ->assertSee('TRX-ALPHA')
            ->assertSee('SUBMITTED')
            ->assertSee('VERIFIED')
            ->assertSee('REJECTED');
    }

    public function test_the_list_page_sorts_newest_submission_first_by_default(): void
    {
        $oldest = $this->createSubmitted([
            'reference' => 'order-oldest',
            'submitted_at' => CarbonImmutable::now()->subDays(3),
        ]);

        $middle = $this->createSubmitted([
            'reference' => 'order-middle',
            'submitted_at' => CarbonImmutable::now()->subDay(),
        ]);

        $newest = $this->createSubmitted([
            'reference' => 'order-newest',
            'submitted_at' => CarbonImmutable::now(),
        ]);

        $this->finance();

        Livewire::test(ListPaymentVerifications::class)
            ->assertCanSeeTableRecords([$newest, $middle, $oldest], inOrder: true);
    }

    public function test_the_list_page_renders_no_create_action(): void
    {
        $this->finance();

        Livewire::test(ListPaymentVerifications::class)
            ->assertActionDoesNotExist('create');
    }

    public function test_the_view_page_renders_no_edit_action(): void
    {
        $verification = $this->createSubmitted();

        $this->finance();

        Livewire::test(ViewPaymentVerification::class, ['record' => $verification->getKey()])
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete');
    }

    public function test_the_view_page_shows_the_full_verification_detail(): void
    {
        $verification = $this->createSubmitted([
            'reference' => 'order-detail-1',
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'TRX-DETAIL-1',
            'instructions' => 'Transfer ke rekening BCA 123456.',
        ]);
        $verification->decide(PaymentVerificationDecision::Approve, 'actor-finance-9', 'Bukti transfer sesuai catatan bank.');
        $verification->refresh();

        $this->finance();

        Livewire::test(ViewPaymentVerification::class, ['record' => $verification->getKey()])
            ->assertSee('order-detail-1')
            ->assertSee('bank_transfer')
            ->assertSee('TRX-DETAIL-1')
            ->assertSee('Transfer ke rekening BCA 123456.')
            ->assertSee('VERIFIED')
            ->assertSee('actor-finance-9')
            ->assertSee('Bukti transfer sesuai catatan bank.');
    }
}
