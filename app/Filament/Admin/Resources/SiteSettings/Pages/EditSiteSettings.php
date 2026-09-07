<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SiteSettings\Pages;

use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Filament\Admin\Resources\SiteSettings\Schemas\SiteSettingsForm;
use App\Filament\Admin\Resources\SiteSettings\SiteSettingsResource;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SiteSettingsAuditActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single-record settings page (route `/`, under `SiteSettingsResource`).
 *
 * A `Page` with a Livewire-managed form rather than an Eloquent
 * `EditRecord`: `site_settings` is an append-only key/value table, not an
 * entity, and the plan rejects single-record Eloquent plumbing for it.
 *
 * Filament 5 API notes:
 *  - The page implements `HasForms` and uses `InteractsWithForms`; the
 *    form is defined by overriding `form(Schema $schema)` (the
 *    non-deprecated override point in filament/forms 5.x — `getFormSchema()`
 *    is marked deprecated there).
 *  - `InteractsWithForms::getFormStatePath()` defaults to `null`, so the
 *    schema's state path is the Livewire component root and the fields bind
 *    their literal `data.*` paths against this page's `$data` property.
 *    `mount()` therefore pre-fills `$this->data` from the persisted rows
 *    before the form hydrates on first render.
 *  - `save()` reads `$this->data` directly and upserts one row per changed
 *    key inside one transaction, then writes exactly one `SITE_SETTING_UPDATED`
 *    audit event for the changed set — never one per key, never a bare
 *    write with no audit trail.
 */
final class EditSiteSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = SiteSettingsResource::class;

    protected static ?string $slug = 'pengaturan-situs';

    protected string $view = 'filament.admin.resources.site-settings.edit-site-settings';

    // UI-audit fix (26 Aug 2026): with no `$title`, `BasePage::getTitle()`
    // humanises the class basename ("EditSiteSettings" -> "Edit Site
    // Settings"), which is where the breadcrumb's English trailing crumb
    // came from — `SiteSettingsResource::getModelLabel()`/
    // `getPluralModelLabel()` fix the leading crumb, this fixes the page's
    // own. Plain noun, no "Edit" prefix, matching this codebase's other
    // single-page `$title` overrides (e.g. `InAppNotifications::$title =
    // 'Notifikasi'`) and the already-correct navigation label.
    protected static ?string $title = 'Pengaturan Situs';

    public array $data = [];

    public function mount(): void
    {
        foreach (SiteSetting::KNOWN_KEYS as $key) {
            $this->data[$key] = (string) (SiteSetting::valueFor($key) ?? '');
        }
    }

    public function form(Schema $schema): Schema
    {
        return SiteSettingsForm::configure($schema);
    }

    /**
     * `bank_transfer_*` keys the manual-payment destination account (finding
     * SEC-02): any save touching one of these requires the actor to hold
     * admin/restricted_admin/finance (rbac-matrix.md: "Payout/refund, incl.
     * manual payment verification" — Operator is "No"), a fresh
     * re-authentication, and a mandatory recorded reason — the same
     * treatment `RequireRecentAuthentication`'s established Filament actions
     * give every other money-adjacent write.
     *
     * @var list<string>
     */
    private const array BANK_TRANSFER_KEYS = [
        SiteSetting::KEY_BANK_TRANSFER_BANK_NAME,
        SiteSetting::KEY_BANK_TRANSFER_ACCOUNT_NUMBER,
        SiteSetting::KEY_BANK_TRANSFER_ACCOUNT_HOLDER,
    ];

    /**
     * @var list<string>
     */
    private const array BANK_TRANSFER_AUTHORISED_ROLES = [
        ActorRole::ADMIN,
        ActorRole::RESTRICTED_ADMIN,
        ActorRole::FINANCE,
    ];

    public function save(): void
    {
        // Review fix (D2): run the form's per-key rules (email, maxLength)
        // before touching the DB — a ValidationException surfaces as inline
        // field errors and nothing is written. `getSchema('form')` is the
        // non-deprecated accessor for the magic `$this->form` property.
        $this->getSchema('form')?->validate();

        // A read-only peek at what WOULD change, computed before any write
        // and before the transaction opens, so the bank-transfer gates below
        // (authorization, re-authentication, mandatory reason) can run first
        // and abort cleanly with nothing written if any of them fail.
        $changed = $this->computeChangedKeys();
        $bankKeysChanged = array_values(array_intersect($changed, self::BANK_TRANSFER_KEYS));

        if ($bankKeysChanged !== [] && ! $this->guardBankAccountChange()) {
            // The guard already sent a notification (and, for a stale
            // session, issued the redirect) — stop here with nothing
            // written.
            return;
        }

        $this->assertBankAccountFieldsAreCompleteOrEmpty();

        $actor = app(ActorContext::class);
        $actorRef = $actor->identityReference;
        $actorRole = SiteSettingsResource::auditRoleFor($actor);
        $reason = $bankKeysChanged !== []
            ? trim((string) ($this->data['bank_transfer_change_reason'] ?? ''))
            : null;

        DB::transaction(function () use ($changed, $actorRef, $actorRole, $reason): void {
            foreach ($changed as $key) {
                $value = trim((string) ($this->data[$key] ?? ''));

                SiteSetting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $value, 'updated_by_ref' => $actorRef === null ? null : (string) $actorRef],
                );
            }

            // Review fix (round 1): the audit record is written INSIDE the
            // same transaction as the upserts so the audit row commits
            // atomically with the state change (Audit::record's own doc
            // block — "call this from inside an existing transaction ... to
            // get AC4's 'same transaction as the state change' guarantee").
            if ($changed !== []) {
                $action = array_intersect($changed, self::BANK_TRANSFER_KEYS) !== []
                    ? SiteSettingsAuditActions::BANK_TRANSFER_UPDATED
                    : SiteSettingsAuditActions::UPDATED;

                Audit::record(
                    action: $action,
                    subject: new AuditSubject('site_settings', implode(',', $changed)),
                    outcome: AuditOutcome::Allowed,
                    actorRef: $actorRef,
                    actorRole: $actorRole,
                    source: AuditSource::Panel,
                    reason: $reason,
                );
            }
        });

        Notification::make()->success()->title('Pengaturan disimpan.')->send();
    }

    /**
     * @return list<string> the KNOWN_KEYS whose submitted value differs from
     *                      what is currently persisted. Read-only — issues
     *                      no writes.
     */
    private function computeChangedKeys(): array
    {
        $changed = [];

        foreach (SiteSetting::KNOWN_KEYS as $key) {
            $value = trim((string) ($this->data[$key] ?? ''));

            // Bug fix (found while adding the 3 bank-transfer keys):
            // `valueFor()` returns null when no row exists yet, which is NOT
            // `===` to an empty-string submission from an untouched form
            // field — so on the very FIRST save, every key the admin never
            // touched (still '') was treated as "changed" (null !== ''),
            // creating an empty row and inflating the audit event's
            // `subject_id` (one comma-joined list of every KNOWN_KEYS entry)
            // with keys nobody actually edited. Adding the 3 new keys pushed
            // that list past the `subject_id` column's 255-char limit and
            // crashed the first-ever save with a Postgres "value too long"
            // error — a real, previously-latent bug this change exposed, not
            // a new one. Both "no row" and "an empty-string row" mean the
            // SAME thing to every caller (`SettingsService`, `SiteSetting::
            // valueFor`) — "not configured" — so they must compare equal
            // here too.
            $current = SiteSetting::valueFor($key) ?? '';

            if ($current !== $value) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * `false` means: fail closed with nothing written. A notification (and,
     * for a stale session, the redirect) has already been sent — the caller
     * only needs to stop. Mirrors the established boolean-helper shape at
     * `App\Filament\Admin\Resources\GravePlots\Tables\
     * GravePlotsTable::requireFreshAuthentication()`, and the
     * authorize-then-reauthenticate order at
     * `App\Filament\Admin\Resources\RenewalOrders\Actions\
     * RecordExternalRenewalPaymentAction`.
     *
     * The mandatory-reason check is deliberately a `ValidationException`,
     * not part of this boolean gate — an inline field error on the reason
     * textarea is the correct UX for it (the admin is still on the page,
     * mid-edit), whereas authorization and freshness genuinely stop the
     * flow.
     */
    private function guardBankAccountChange(): bool
    {
        $actor = app(ActorContext::class);

        $authorised = false;

        foreach (self::BANK_TRANSFER_AUTHORISED_ROLES as $role) {
            if ($actor->hasRole($role)) {
                $authorised = true;

                break;
            }
        }

        if (! $authorised) {
            Notification::make()
                ->danger()
                ->title('Hanya admin, restricted admin, atau finance yang dapat mengubah rekening transfer manual.')
                ->send();

            return false;
        }

        try {
            app(ReauthenticationGuard::class)->assertFresh($actor);
        } catch (ReauthenticationRequiredException) {
            // Exact established pattern (App\Filament\Admin\Pages\
            // FeatureGateAdmin::transitionGate()): a bare redirect() call,
            // never `return redirect()...` — Livewire's response lifecycle
            // picks this up.
            session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, 'bank_account_change');
            session()->put('url.intended', SiteSettingsResource::getUrl('edit'));
            Notification::make()->warning()->title('Perlu verifikasi ulang')->send();
            redirect()->route(PasswordReauthentication::ROUTE_NAME);

            return false;
        }

        if (trim((string) ($this->data['bank_transfer_change_reason'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'data.bank_transfer_change_reason' => 'Alasan wajib diisi saat mengubah rekening transfer manual.',
            ]);
        }

        return true;
    }

    /**
     * The three bank-transfer fields are treated as one unit everywhere else
     * (`App\Support\BankTransferInfo`'s own doc block: "a partial set is as
     * useless to a payer as none") — reject a partial submission here too,
     * rather than silently saving a combination the public side will just
     * hide.
     */
    private function assertBankAccountFieldsAreCompleteOrEmpty(): void
    {
        $values = array_map(
            fn (string $key): string => trim((string) ($this->data[$key] ?? '')),
            self::BANK_TRANSFER_KEYS,
        );

        $filled = array_filter($values, static fn (string $value): bool => $value !== '');

        if ($filled !== [] && count($filled) !== count($values)) {
            throw ValidationException::withMessages([
                'data.bank_transfer_bank_name' => 'Isi nama bank, nomor rekening, dan nama pemilik rekening semuanya, atau kosongkan ketiganya.',
            ]);
        }
    }
}
