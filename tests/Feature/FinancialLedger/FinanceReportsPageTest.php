<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Filament\Admin\Pages\FinanceReports;
use App\Models\User;
use App\Platform\FinancialLedger\Journal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The `FinanceReports` admin page — the report's and the export's mount
 * point. `MfaSettingsPageTest` is the sibling precedent for the Filament
 * page test shape. Required states (§6) covered here: empty (the exact
 * "Belum ada transaksi pada periode ini" copy), success (rows + metadata),
 * validation error (inline, malformed period), and the export button's link
 * to the gated route.
 */
final class FinanceReportsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_ledger_renders_the_required_empty_state_with_the_current_period(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(FinanceReports::class);

        $this->assertSame(CarbonImmutable::now()->format('Y-m'), $component->get('period'));
        $component->assertSee('Belum ada transaksi pada periode ini')
            ->assertSet('source', 'journal')
            ->assertCount('reportRows', 0);
    }

    public function test_a_seeded_period_renders_rows_metadata_and_totals(): void
    {
        $user = User::factory()->create();
        $this->seedCurrentMonthLedger();

        $component = Livewire::actingAs($user)->test(FinanceReports::class);

        $component->assertSee('Kode akun')
            ->assertSee('TOTAL')
            ->assertSee('Sumber: journal')
            ->assertCount('reportRows', 2)
            ->assertSet('debitTotal', 100_000)
            ->assertSet('creditTotal', 100_000);
    }

    public function test_a_malformed_period_renders_the_inline_validation_error(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(FinanceReports::class);

        $component->set('period', '2026-13')->call('loadReport');

        $component->assertSee('Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.')
            ->assertCount('reportRows', 0)
            ->assertSet('error', 'Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.');
    }

    public function test_reloading_after_fixing_a_bad_period_recovers_the_report(): void
    {
        $user = User::factory()->create();
        $this->seedCurrentMonthLedger();

        $component = Livewire::actingAs($user)->test(FinanceReports::class);

        $component->set('period', '2026-13')->call('loadReport')->assertCount('reportRows', 0);

        $component->set('period', CarbonImmutable::now()->format('Y-m'))->call('loadReport');

        $component->assertSet('error', '')
            ->assertCount('reportRows', 2);
    }

    public function test_the_export_button_links_to_the_gated_exports_route(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(FinanceReports::class)
            ->assertSeeHtml(route('admin.finance.exports', ['period' => CarbonImmutable::now()->format('Y-m')]));
    }

    private function seedCurrentMonthLedger(): void
    {
        $this->journal()->post(
            businessKey: 'payment:provider-event-report-page-1',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'provider-event-report-page-1',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 100_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 100_000],
            ],
            correlationId: 'trace-report-page-1',
            occurredAt: CarbonImmutable::now()->toISOString(),
        );
    }

    private function journal(): Journal
    {
        return $this->app->make(Journal::class);
    }
}
