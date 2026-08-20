<?php

declare(strict_types=1);

namespace App\Livewire\Public\Akun;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/akun/perpanjangan` — Task 3 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-shell-and-drafts/task-3-brief.md`).
 * Guarded by the route group's `auth` middleware (`routes/web.php`'s
 * `akun.*` block) — this component never re-checks authentication itself.
 *
 * An honest "not yet available" page over `<x-mk.gate-closed-page>`, per
 * design-system.md §6.4's "Gate closed" row — never a raw 403/404. There is
 * no customer-ownership infrastructure linking a grave/renewal record to a
 * user account yet (a real, separate follow-up), so this screen explains
 * that renewals are still handled through the existing public flow
 * (`route('perpanjangan.index')`) rather than fabricating an account-scoped
 * renewal list.
 *
 * `render(): View` only — no query, no data.
 */
final class RenewalList extends Component
{
    public function render(): View
    {
        return view('livewire.public.akun.renewal-list')
            ->layout('layouts.app', [
                'title' => 'Perpanjangan - Makam.co.id',
                'active' => null,
            ]);
    }
}
