<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Platform\IdentityAccess\Contracts\IdentityAccessAdapter;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Panel\AdminPanelAccessPolicy;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Batch 3.1 (platform-identity-and-access, S3-T1) addition.
     * `actor_sessions` bookkeeping — see `ActorSession` for what writes to
     * it and `2026_07_26_100000_create_actor_sessions_table.php` for scope.
     */
    public function actorSessions(): HasMany
    {
        return $this->hasMany(ActorSession::class);
    }

    /**
     * AC4's explicit-per-panel-access-check mechanism
     * (`App\Platform\IdentityAccess\Contracts\PanelAccessPolicy`), wired to
     * Filament through its `FilamentUser` contract. Resolves the candidate
     * user's `ActorContext` fresh (not the request's cached
     * `ActorContextResolver` instance — Filament calls this with an
     * arbitrary candidate `$this`, which the currently-cached request
     * context may or may not correspond to) and delegates the decision to
     * the named policy for that panel id. Unknown/future panel ids
     * (`vendor`, operator — neither exists in this codebase yet) resolve
     * closed rather than falling through to an implicit allow.
     *
     * VERIFICATION STATUS: `Filament\Models\Contracts\FilamentUser` and its
     * `canAccessPanel(Panel $panel): bool` signature are written against
     * Filament's documented public API, stable across recent major
     * versions. This host has no installed `vendor/filament/filament` to
     * confirm the FQCN against (same constraint `AdminPanelProvider`'s own
     * class-level comment already documents for this repo) — confirm at
     * the same point that file's Filament 5 API usage gets confirmed.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        $actorContext = app(IdentityAccessAdapter::class)->resolveActorContext($this);

        return match ($panel->getId()) {
            'admin' => app(AdminPanelAccessPolicy::class)->allows($actorContext),
            default => false,
        };
    }
}
