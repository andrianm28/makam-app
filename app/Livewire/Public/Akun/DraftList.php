<?php

declare(strict_types=1);

namespace App\Livewire\Public\Akun;

use App\Domain\Booking\BookingDraftQuery;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/akun/draft` — the draft-resume list, Task 2 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-shell-and-drafts/task-2-brief.md`).
 * Guarded by the route group's `auth` middleware (`routes/web.php`'s
 * `akun.*` block) — this component never re-checks authentication itself.
 *
 * `render(): View` only — no action, no form, no write. Reads
 * `BookingDraftQuery::openForUser(auth()->id())`, the same read-model
 * `BookingDraftQueryTest` already covers for its own scoping/ordering/
 * exclusion contract (own user's drafts only, submitted drafts excluded,
 * most-recently-updated first) — this component adds no query logic of
 * its own, only the view over it.
 *
 * Empty state is design-system.md §6.2's literal `/akun/draft` row: "Belum
 * ada draft pemesanan." + a `Mulai pemesanan` secondary button to
 * `route('pemesanan-makam.index')`.
 */
final class DraftList extends Component
{
    public function render(): View
    {
        return view('livewire.public.akun.draft-list', [
            'drafts' => BookingDraftQuery::openForUser(auth()->id()),
        ])->layout('layouts.app', [
            'title' => 'Draft Pemesanan - Makam.co.id',
            'active' => null,
        ]);
    }
}
