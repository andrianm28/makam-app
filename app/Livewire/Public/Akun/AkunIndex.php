<?php

declare(strict_types=1);

namespace App\Livewire\Public\Akun;

use App\Domain\Booking\BookingDraftQuery;
use App\Domain\OrderWorkflow\Models\Order;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/akun` — the account-area home shell, Task 2 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-shell-and-drafts/task-2-brief.md`),
 * extended with a fourth tile by Task 2 of PR 3
 * (`.superpowers/sdd/2026-08-20-akun-pesanan/task-2-brief.md`). Guarded by
 * the route group's `auth` middleware (`routes/web.php`'s `akun.*` block)
 * — this component never re-checks authentication itself.
 *
 * `render(): View` only — no action, no form. The draft-tile count reads
 * `BookingDraftQuery::openForUser()` (the same read `DraftList` uses for
 * its own row set), never a raw `BookingDraft::query()->count()` — one
 * definition of "open draft" for both screens. The order-tile count reads
 * `Order::forUser(auth()->id())->count()` the same way, for the same
 * reason: an honest count over Task 1's own `#[Scope]`, not a static
 * description — a single indexed `EXISTS` subquery per page load is cheap
 * enough that the destination screen already showing the real list is not
 * a reason to skip it here.
 *
 * Four tiles render, closing the `md:grid-cols-2` grid into a clean 2x2 —
 * `/akun/draft`, `/akun/pesanan`, `/akun/perpanjangan`, and `/akun/dokumen`,
 * all registered in `routes/web.php`'s `akun.*` group. The renewal and
 * document tiles still link through (never omitted, per design-system.md
 * §6.4 and the IA's own "closed but explained" requirement) but carry a
 * `<x-mk.badge>` "Segera hadir" marker since both routes render
 * `<x-mk.gate-closed-page>` rather than real account-scoped data — see
 * `RenewalList`/`DocumentList`'s own doc blocks. `/akun/pesanan` carries no
 * such marker: it renders real account-scoped data (`OrderList`).
 */
final class AkunIndex extends Component
{
    public function render(): View
    {
        return view('livewire.public.akun.akun-index', [
            'user' => auth()->user(),
            'openDraftCount' => BookingDraftQuery::openForUser(auth()->id())->count(),
            'orderCount' => Order::forUser(auth()->id())->count(),
        ])->layout('layouts.app', [
            'title' => 'Akun Saya - Makam.co.id',
            // <x-mk.header>'s nav keys are only 'pemesanan' | 'layanan' |
            // 'perpanjangan' | 'faq' — Akun is a separate persistent
            // action with no active treatment, same reasoning as
            // HelpCentre's own 'active' => null.
            'active' => null,
        ]);
    }
}
