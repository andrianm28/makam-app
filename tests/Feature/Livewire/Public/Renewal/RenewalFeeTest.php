<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Renewal;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Livewire\Public\Renewal\RenewalFee;
use App\Platform\FeatureGate\Models\FeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class RenewalFeeTest extends TestCase
{
    use RefreshDatabase;

    private function openTheDataGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open']);
    }

    private function cemeteryWithPrice(): Cemetery
    {
        return Cemetery::query()->where('slug', 'tpu-jakarta-menteng')->sole();
    }

    public function test_the_fee_screen_always_shows_the_tariff_source_and_last_update(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->assertOk()
            ->assertSee('Sumber tarif')
            ->assertSee('Terakhir diperbarui');
    }

    public function test_no_late_fine_figure_is_rendered_when_there_is_no_written_basis(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $cemetery->id,
            'due_date' => now()->subYears(3)->format('Y-m-d'),
        ]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->assertOk()
            ->assertDontSee('Denda');
    }

    public function test_the_fee_screen_shows_the_renewal_amount(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->assertOk()
            ->assertSee('Rp');
    }

    public function test_a_grave_without_a_tariff_source_renders_a_useful_error(): void
    {
        $this->openTheDataGate();
        $cemetery = Cemetery::query()->where('slug', 'tpu-jakarta-menteng')->sole();

        DB::table('cemeteries')
            ->where('id', $cemetery->id)
            ->update(['price_min' => null, 'price_source' => null, 'price_effective_at' => null]);

        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->assertOk()
            ->assertSee('tarif');
    }

    public function test_the_stepper_shows_step_4_as_current(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);

        $component = Livewire::test(RenewalFee::class, ['makam' => $grave->id]);

        $component->assertSee('Langkah 4 dari 6');

        foreach (['Kota', 'TPU/TPS', 'Cari Makam', 'Biaya', 'Pembayaran', 'Konfirmasi'] as $label) {
            $component->assertSee($label);
        }
    }

    public function test_support_escape_hatch_is_present(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->assertOk()
            ->assertSee('/bantuan');
    }

    /**
     * The defect this guards against: `mount()` used to call an Action that
     * persisted a `Renewal` and a `RenewalQuote`. Every anonymous GET of this
     * URL — a refresh, a crawler, a link preview — created rows and claimed
     * the AC11 unique business key `(grave_record_id, target_due_period)` for
     * a grave the visitor has no relationship to. The second GET then hit the
     * constraint, and the squatted key also blocked the admin AC10 marking
     * path for that grave and period.
     */
    public function test_rendering_the_fee_screen_writes_nothing(): void
    {
        $this->openTheDataGate();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $this->cemeteryWithPrice()->id]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])->assertOk();
        Livewire::test(RenewalFee::class, ['makam' => $grave->id])->assertOk();
        Livewire::test(RenewalFee::class, ['makam' => $grave->id])->assertOk();

        $this->assertDatabaseCount('renewals', 0);
        $this->assertDatabaseCount('renewal_quotes', 0);
    }

    /**
     * The acceptance is the write, and it happens once per period. A second
     * acceptance surfaces AC11 as a handled message, never a 500.
     */
    public function test_accepting_the_quote_creates_exactly_one_renewal_and_redirects(): void
    {
        $this->openTheDataGate();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $this->cemeteryWithPrice()->id]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->call('terimaDanLanjutkan')
            ->assertRedirect();

        $this->assertDatabaseCount('renewals', 1);
        $this->assertDatabaseCount('renewal_quotes', 1);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->call('terimaDanLanjutkan')
            ->assertNoRedirect()
            ->assertSee('sudah tercatat');

        $this->assertDatabaseCount('renewals', 1);
    }

    /**
     * AC14. A `closed` record is acknowledged as existing — never silently
     * dropped, per `GraveRecordAccessMode`'s own doc block — but discloses no
     * fields, and cannot be renewed online.
     */
    public function test_a_closed_record_shows_the_privacy_limited_state_and_no_grave_fields(): void
    {
        $this->openTheDataGate();
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $this->cemeteryWithPrice()->id,
            'deceased_name' => 'Budi Santoso Rahasia',
            'block' => 'Z-99',
            'access_mode' => GraveRecordAccessMode::CLOSED,
        ]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->assertOk()
            ->assertSee('dibatasi')
            ->assertDontSee('Budi Santoso Rahasia')
            ->assertDontSee('Z-99')
            ->assertDontSee('Sumber tarif');
    }

    /**
     * `limited` withholds the deceased's identity and dates while still
     * naming the location, so it too must not render a fee.
     */
    public function test_a_limited_record_shows_the_privacy_limited_state_and_no_identity(): void
    {
        $this->openTheDataGate();
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $this->cemeteryWithPrice()->id,
            'deceased_name' => 'Siti Aminah Rahasia',
            'access_mode' => GraveRecordAccessMode::LIMITED,
        ]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->assertOk()
            ->assertSee('dibatasi')
            ->assertDontSee('Siti Aminah Rahasia')
            ->assertDontSee('Sumber tarif');
    }

    public function test_a_restricted_record_cannot_be_renewed_by_calling_the_action_directly(): void
    {
        $this->openTheDataGate();
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $this->cemeteryWithPrice()->id,
            'access_mode' => GraveRecordAccessMode::CLOSED,
        ]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->call('terimaDanLanjutkan')
            ->assertNoRedirect();

        $this->assertDatabaseCount('renewals', 0);
    }

    public function test_an_unknown_grave_reports_not_found_rather_than_rendering_a_broken_card(): void
    {
        $this->openTheDataGate();

        Livewire::test(RenewalFee::class, ['makam' => '0198f000-0000-7000-8000-000000000000'])
            ->assertOk()
            ->assertSee('tidak ditemukan')
            ->assertDontSee('Sumber tarif');
    }

    /**
     * `grave_records.due_date` is nullable, so a published grave with no due
     * date reaches this screen. There is no period to renew and no quote to
     * accept — the screen must show the quote-unavailable state and acceptance
     * must write nothing. Previously acceptance crashed with a fatal `Error`
     * (the NOT NULL insert failed, and the duplicate-handler then dereferenced
     * the null date).
     */
    public function test_a_grave_without_a_due_date_shows_quote_unavailable_and_acceptance_writes_nothing(): void
    {
        $this->openTheDataGate();
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $this->cemeteryWithPrice()->id,
            'due_date' => null,
        ]);

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->assertOk()
            ->assertSee('Tarif tidak tersedia')
            ->assertDontSee('Lanjutkan ke Pembayaran');

        Livewire::test(RenewalFee::class, ['makam' => $grave->id])
            ->call('terimaDanLanjutkan')
            ->assertNoRedirect();

        $this->assertDatabaseCount('renewals', 0);
        $this->assertDatabaseCount('renewal_quotes', 0);
    }
}
