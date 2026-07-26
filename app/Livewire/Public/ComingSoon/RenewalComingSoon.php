<?php

declare(strict_types=1);

namespace App\Livewire\Public\ComingSoon;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/perpanjangan` stub. requirements.md AC6, read expansively — see
 * `resources/views/livewire/public/coming-soon.blade.php`'s own doc block
 * for why this is NOT `<x-mk.gate-closed-page>`. Owned, once built, by the
 * `renewal-and-grave-registry` spec
 * (`docs/product/information-architecture.md` §1 — `/perpanjangan/{cari,
 * permohonan/{renewalReference},konfirmasi/{renewalReference}}`), later in
 * Sprint 4 (S4-T7 per this sprint's own task list). This stub is expected
 * to be REPLACED, not extended.
 */
final class RenewalComingSoon extends Component
{
    public function render(): View
    {
        return view('livewire.public.coming-soon', [
            'heading' => 'Perpanjangan Makam Segera Hadir',
            'body' => 'Pencarian data makam dan pengajuan perpanjangan masa sewa online sedang kami bangun dan akan tersedia pada rilis berikutnya. Untuk kebutuhan saat ini, silakan hubungi tim Bantuan kami.',
        ])->layout('layouts.app', [
            'title' => 'Perpanjangan Makam - Segera Hadir - Makam.co.id',
            'active' => 'perpanjangan',
        ]);
    }
}
