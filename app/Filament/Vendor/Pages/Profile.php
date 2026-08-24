<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;

/**
 * Self-service account page for the CURRENTLY AUTHENTICATED `/vendor` user
 * — reads `Auth::user()` directly, never a route parameter. A vendor can only
 * ever see or edit their own account through this page.
 * Route name `filament.vendor.pages.profil`.
 *
 * ---------------------------------------------------------------------------
 * Scope: `App\Models\User` fields only — no vendor-model fields exist to add
 * ---------------------------------------------------------------------------
 * `App\Domain\Marketplace\Models\Vendor` (the entity an actor is scoped to
 * via `scope_assignments`, never a direct FK on `users`) has a `$fillable`
 * of only `['name', 'is_active']` — checked before writing this page. Both
 * are admin-managed business identity/activation state, not personal
 * account data a vendor USER would self-edit, and neither is a contact
 * field (no phone/address column exists on that table at all). So there is
 * nothing vendor-scoped for this page to expose; it edits `name`, `email`,
 * and `password` on `users` only. Which vendor(s) an actor is granted stays
 * governed entirely by `App\Filament\Vendor\Concerns\ScopesToCurrentVendor`
 * elsewhere and is unaffected by anything on this page.
 *
 * ---------------------------------------------------------------------------
 * Password changes — current-password confirmation, not the reauth middleware
 * ---------------------------------------------------------------------------
 * Uses Filament's own `TextInput::currentPassword()` (Laravel's built-in
 * `current_password` validation rule, checked against the default `web`
 * guard both panels resolve through) to require the correct current
 * password before a new one is accepted — the same mechanism Filament's own
 * stock `Filament\Auth\Pages\EditProfile` uses for this exact purpose
 * (`vendor/filament/filament/src/Auth/Pages/EditProfile.php`, read in full
 * before writing this page).
 *
 * Deliberately NOT `App\Http\Middleware\RequireRecentAuthentication`: that
 * class's own doc block is explicit that it is "PREPARED, not WIRED" as a
 * HUMAN-GATED follow-up, and none of `docs/security/authentication-and-mfa.md`
 * §5's six sensitive-action classes (payment/refund/payout approval,
 * bank-account change, feature/payment-gate change, specific-plot override,
 * certificate issue/revoke/replace, bulk export of personal data, restricted
 * retention/deletion, break-glass access) name a routine self-service
 * password change. Wiring that middleware here would be exactly the kind of
 * unrequested scope-widening `AGENTS.md` reserves for human review.
 *
 * No prior password-change UI exists anywhere in this repo to mirror
 * (confirmed: `grep`-ing for `current_password`/`Hash::check` across `app/`
 * turns up only the MFA/reauthentication modules, neither of which is a
 * password-entry form) — this page is the first one.
 *
 * ---------------------------------------------------------------------------
 * Other-session revocation after a password change
 * ---------------------------------------------------------------------------
 * `VendorPanelProvider`'s already-wired `Filament\Http\Middleware\AuthenticateSession`
 * (itself `Illuminate\Session\Middleware\AuthenticateSession`) stores a hash
 * of the authenticated user's password in every session and re-checks it on
 * every subsequent request, logging that session out the moment it no longer
 * matches what is now in the database — see that class's `handle()`. That
 * covers docs/security/authentication-and-mfa.md §6's "session revocation
 * after password/MFA reset" automatically, for every OTHER session, the
 * instant `save()` below persists a new hash — no explicit
 * `Auth::logoutOtherDevices()` call is needed here for that guarantee, and
 * this page deliberately does not add one (it would only additionally
 * refresh THIS device's own remember-me cookie signature, a narrower,
 * lower-value addition left for a human to decide is worth the extra
 * surface).
 */
final class Profile extends Page implements HasForms
{
    use InteractsWithForms;

    private const string AUDIT_ACTION_PROFILE_UPDATED = 'VENDOR_PROFILE_UPDATED';

    /**
     * Deliberately NOT added to `App\Platform\Audit\SensitiveActions::ACTIONS`
     * — same reasoning as other routine, self-initiated actions: a user
     * changing their own password after proving they know the old one has no
     * human-authored "reason" a mandatory-reason gate would meaningfully extract.
     * Requiring one here would be a caller-facing dead end, not a real control.
     */
    private const string AUDIT_ACTION_PASSWORD_CHANGED = 'VENDOR_PASSWORD_CHANGED';

    protected static ?string $slug = 'profil';

    protected string $view = 'filament.vendor.pages.profile';

    protected static ?int $navigationSort = 90;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return 'Profil Akun';
    }

    public function getTitle(): string
    {
        return 'Profil Akun';
    }

    public function mount(): void
    {
        $user = Auth::user();

        $this->data = [
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    public function form(Schema $schema): Schema
    {
        $user = Auth::user();

        return $schema
            ->model($user)
            ->statePath('data')
            ->components([
                Section::make('Informasi akun')->schema([
                    TextInput::make('name')
                        ->label('Nama')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                ]),
                Section::make('Ubah kata sandi')
                    ->description('Kosongkan jika Anda tidak ingin mengganti kata sandi.')
                    ->schema([
                        TextInput::make('password')
                            ->label('Kata sandi baru')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->rule(Password::default())
                            ->live(debounce: 500)
                            ->same('passwordConfirmation')
                            ->dehydrated(fn (#[SensitiveParameter] ?string $state): bool => filled($state)),
                        TextInput::make('passwordConfirmation')
                            ->label('Konfirmasi kata sandi baru')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required()
                            ->visible(fn (Get $get): bool => filled($get('password')))
                            ->dehydrated(false),
                        TextInput::make('currentPassword')
                            ->label('Kata sandi saat ini')
                            ->password()
                            ->revealable()
                            ->autocomplete('current-password')
                            ->currentPassword()
                            ->required()
                            ->visible(fn (Get $get): bool => filled($get('password'))
                                || $get('email') !== $user->email)
                            ->dehydrated(false),
                    ]),
            ]);
    }

    public function save(): void
    {
        // `getSchema('form')` — the non-deprecated accessor for the magic
        // `$this->form` property (same choice `EditSiteSettings::save()`
        // documents for the identical reason: the magic property is not a
        // real declared property, so static analysis cannot see it).
        $data = $this->getSchema('form')?->getState() ?? [];

        $user = Auth::user();
        $newPassword = $data['password'] ?? null;
        $passwordChanged = filled($newPassword);

        $actorContext = app(ActorContext::class);

        Audit::wrap(
            mutation: function () use ($user, $data, $passwordChanged, $newPassword): User {
                $user->name = $data['name'];
                $user->email = $data['email'];

                if ($passwordChanged) {
                    $user->password = Hash::make($newPassword);
                }

                $user->save();

                return $user;
            },
            action: $passwordChanged
                ? self::AUDIT_ACTION_PASSWORD_CHANGED
                : self::AUDIT_ACTION_PROFILE_UPDATED,
            subject: fn (User $user): AuditSubject => new AuditSubject('user', $user->id),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorContext->identityReference,
            actorRole: 'authenticated_actor',
            source: AuditSource::Panel,
        );

        $this->data['password'] = null;
        $this->data['passwordConfirmation'] = null;
        $this->data['currentPassword'] = null;

        Notification::make()->success()->title('Profil berhasil diperbarui.')->send();
    }
}
