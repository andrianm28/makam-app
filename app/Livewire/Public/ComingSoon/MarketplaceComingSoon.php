<?php

declare(strict_types=1);

namespace App\Livewire\Public\ComingSoon;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/marketplace` stub ("Layanan Pemakaman" in the four-menu contract).
 * requirements.md AC6, read expansively — see `resources/views/livewire/
 * public/coming-soon.blade.php`'s own doc block for why this is NOT
 * `<x-mk.gate-closed-page>`. Owned, once built, by the
 * `funeral-marketplace-and-vendor-portal` spec
 * (`docs/product/information-architecture.md` §1 —
 * `/marketplace/{kategori/{categorySlug},produk/{productSlug},keranjang,
 * checkout,pesanan/{orderReference}}`), later in Sprint 4 (S4-T8 per this
 * sprint's own task list). This stub is expected to be REPLACED, not
 * extended.
 */
final class MarketplaceComingSoon extends Component
{
    public function render(): View
    {
        return view('livewire.public.coming-soon', [
            'heading' => 'Layanan Pemakaman Segera Hadir',
            'body' => 'Katalog produk dan layanan pemakaman dari vendor sedang kami bangun dan akan tersedia pada rilis berikutnya. Untuk kebutuhan saat ini, silakan hubungi tim Bantuan kami.',
        ])->layout('layouts.app', [
            'title' => 'Layanan Pemakaman - Segera Hadir - Makam.co.id',
            'active' => 'layanan',
        ]);
    }
}
