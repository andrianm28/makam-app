<?php

declare(strict_types=1);

namespace App\Livewire\Public\ComingSoon;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/pemesanan-makam` stub. requirements.md AC6, read expansively — see
 * `resources/views/livewire/public/coming-soon.blade.php`'s own doc block
 * for why this is NOT `<x-mk.gate-closed-page>`. Owned, once built, by the
 * `booking-wizard` spec (`docs/product/information-architecture.md` §1 —
 * `/pemesanan-makam/{baru,draft/{draftId},konfirmasi/{orderReference}}`),
 * Sprint 4 S4-T4, the very next task in this same sprint. This stub is
 * expected to be REPLACED, not extended — a future batch registers the
 * real `booking-wizard` routes at these paths and this class + its route
 * registration in `routes/web.php` are deleted, not built upon.
 */
final class BookingWizardComingSoon extends Component
{
    public function render(): View
    {
        return view('livewire.public.coming-soon', [
            'heading' => 'Pemesanan Makam Segera Hadir',
            'body' => 'Alur pemesanan makam online sedang kami bangun dan akan tersedia pada rilis berikutnya. Untuk kebutuhan mendesak, silakan hubungi tim Bantuan kami terlebih dahulu.',
        ])->layout('layouts.app', [
            'title' => 'Pemesanan Makam - Segera Hadir - Makam.co.id',
            'active' => 'pemesanan',
        ]);
    }
}
