<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Renewal;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\GuardRenewalPaymentOpening;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalQuote;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FinancialLedger\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GuardRenewalPaymentOpeningTest extends TestCase
{
    use RefreshDatabase;

    private function openThePaymentGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'open']);
    }

    private function closeThePaymentGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'closed']);
    }

    private function acceptedQuote(): RenewalQuote
    {
        $grave = GraveRecord::factory()->create();
        $renewal = Renewal::factory()->create(['grave_record_id' => $grave->id]);
        $quote = RenewalQuote::factory()->accepted()->create(['renewal_id' => $renewal->id]);

        return $quote;
    }

    public function test_an_expired_quote_denies_payment_opening(): void
    {
        $this->openThePaymentGate();
        $quote = RenewalQuote::factory()->create([
            'expires_at' => now()->subDay(),
            'accepted_at' => now()->subDays(2),
        ]);

        $result = app(GuardRenewalPaymentOpening::class)($quote->renewal, $quote->amountAsMoney());

        $this->assertFalse($result->isAllowed());
    }

    public function test_an_amount_that_does_not_equal_the_quote_total_denies(): void
    {
        $this->openThePaymentGate();
        $quote = RenewalQuote::factory()->accepted()->create(['amount_minor' => 150_000_00]);

        $result = app(GuardRenewalPaymentOpening::class)($quote->renewal, new Money(149_999_00));

        $this->assertFalse($result->isAllowed());
    }

    public function test_an_unaccepted_quote_denies_payment_opening(): void
    {
        $this->openThePaymentGate();
        $quote = RenewalQuote::factory()->create([
            'accepted_at' => null,
            'expires_at' => now()->addDays(7),
        ]);

        $result = app(GuardRenewalPaymentOpening::class)($quote->renewal, $quote->amountAsMoney());

        $this->assertFalse($result->isAllowed());
    }

    public function test_a_closed_gate_denies_with_manual_coordination_required(): void
    {
        $this->closeThePaymentGate();
        $quote = $this->acceptedQuote();

        $result = app(GuardRenewalPaymentOpening::class)($quote->renewal, $quote->amountAsMoney());

        $this->assertTrue($result->isAllowed());
        $this->assertTrue($result->isManualCoordinationRequired());
    }

    public function test_all_conditions_pass_with_open_gate_returns_allowed(): void
    {
        $this->openThePaymentGate();
        $quote = $this->acceptedQuote();

        $result = app(GuardRenewalPaymentOpening::class)($quote->renewal, $quote->amountAsMoney());

        $this->assertTrue($result->isAllowed());
        $this->assertFalse($result->isManualCoordinationRequired());
    }

    public function test_a_grave_with_closed_access_mode_denies(): void
    {
        $this->openThePaymentGate();
        $grave = GraveRecord::factory()->create(['access_mode' => 'closed']);
        $renewal = Renewal::factory()->create(['grave_record_id' => $grave->id]);
        $quote = RenewalQuote::factory()->accepted()->create(['renewal_id' => $renewal->id]);

        $result = app(GuardRenewalPaymentOpening::class)($renewal, $quote->amountAsMoney());

        $this->assertFalse($result->isAllowed());
    }
}
