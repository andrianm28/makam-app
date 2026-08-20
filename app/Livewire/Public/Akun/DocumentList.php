<?php

declare(strict_types=1);

namespace App\Livewire\Public\Akun;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/akun/dokumen` — Task 3 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-shell-and-drafts/task-3-brief.md`).
 * Guarded by the route group's `auth` middleware (`routes/web.php`'s
 * `akun.*` block) — this component never re-checks authentication itself.
 *
 * An honest "not yet available" page over `<x-mk.gate-closed-page>`, per
 * design-system.md §6.4's "Gate closed" row — never a raw 403/404. There is
 * no customer-facing document upload path yet (a real, separate follow-up),
 * so this screen sends the visitor to `route('bantuan.index')` rather than
 * fabricating a document list.
 *
 * `render(): View` only — no query, no data.
 */
final class DocumentList extends Component
{
    public function render(): View
    {
        return view('livewire.public.akun.document-list')
            ->layout('layouts.app', [
                'title' => 'Dokumen - Makam.co.id',
                'active' => null,
            ]);
    }
}
