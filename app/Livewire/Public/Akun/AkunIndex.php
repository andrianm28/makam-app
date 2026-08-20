<?php

declare(strict_types=1);

namespace App\Livewire\Public\Akun;

use App\Domain\Booking\BookingDraftQuery;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/akun` — the account-area home shell, Task 2 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-shell-and-drafts/task-2-brief.md`).
 * Guarded by the route group's `auth` middleware (`routes/web.php`'s
 * `akun.*` block) — this component never re-checks authentication itself.
 *
 * `render(): View` only — no action, no form. The draft-tile count reads
 * `BookingDraftQuery::openForUser()` (the same read `DraftList` uses for
 * its own row set), never a raw `BookingDraft::query()->count()` — one
 * definition of "open draft" for both screens.
 *
 * Three tiles render: `/akun/draft`, `/akun/perpanjangan`, and
 * `/akun/dokumen` — all now registered in `routes/web.php`'s `akun.*`
 * group (Task 3). The renewal and document tiles still link through (never
 * omitted, per design-system.md §6.4 and the IA's own "closed but
 * explained" requirement) but carry a `<x-mk.badge>` "Segera hadir" marker
 * since both routes render `<x-mk.gate-closed-page>` rather than real
 * account-scoped data — see `RenewalList`/`DocumentList`'s own doc blocks.
 */
final class AkunIndex extends Component
{
    public function render(): View
    {
        return view('livewire.public.akun.akun-index', [
            'user' => auth()->user(),
            'openDraftCount' => BookingDraftQuery::openForUser(auth()->id())->count(),
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
