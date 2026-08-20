<?php

declare(strict_types=1);

namespace App\Livewire\Public\Akun;

use App\Domain\OrderWorkflow\Models\Order;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/akun/pesanan` — the order list, Task 2 of PR 3 of the `/akun` account
 * area (`.superpowers/sdd/2026-08-20-akun-pesanan/task-2-brief.md`). Guarded
 * by the route group's `auth` middleware (`routes/web.php`'s `akun.*`
 * block) — this component never re-checks authentication itself.
 *
 * `render(): View` only — no action, no form, no write. Reads
 * `Order::forUser(auth()->id())`, Task 1's `#[Scope]`-attributed filter
 * (`OrderForUserScopeTest` already covers its own scoping/ordering
 * contract: own `PEMESAN` parties only, most-recent-first). This component
 * adds no query logic of its own, only the view over it.
 *
 * Empty state mirrors `DraftList`'s own §6.2 recipe (no canonical copy
 * exists for THIS screen — checked design-system.md first, same discipline
 * PR 1/2 both used for their own new screens): "Belum ada pesanan." + a
 * one-line explanation + a `Mulai pemesanan` button to
 * `route('pemesanan-makam.index')`.
 *
 * The view deliberately renders NO "Lihat detail" link. `information-
 * architecture.md`'s `/pesanan/{orderReference}` detail page (PUB-050) is
 * an orphaned forward-reference (`docs/planning/kiro-specs-analysis.md`)
 * that does not exist in this codebase, and the only real order-detail
 * surface (`/marketplace/pesanan/{orderNumber}`) is marketplace-only and
 * unrelated to this list. Linking to either would 404 or point at the
 * wrong order — see the view's own doc block for the same note kept next
 * to the row markup.
 */
final class OrderList extends Component
{
    public function render(): View
    {
        return view('livewire.public.akun.order-list', [
            'orders' => Order::forUser(auth()->id())->get(),
        ])->layout('layouts.app', [
            'title' => 'Pesanan Saya - Makam.co.id',
            'active' => null,
        ]);
    }
}
