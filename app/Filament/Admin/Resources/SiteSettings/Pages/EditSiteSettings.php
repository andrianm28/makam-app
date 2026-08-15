<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SiteSettings\Pages;

use App\Filament\Admin\Resources\SiteSettings\Schemas\SiteSettingsForm;
use App\Filament\Admin\Resources\SiteSettings\SiteSettingsResource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SiteSettingsAuditActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

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

    protected static ?string $slug = 'site-settings';

    protected string $view = 'filament.admin.resources.site-settings.edit-site-settings';

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

    public function save(): void
    {
        $actor = app(ActorContext::class);
        $actorRef = $actor->identityReference;
        $actorRole = SiteSettingsResource::auditRoleFor($actor);
        $changed = [];

        DB::transaction(function () use (&$changed, $actorRef, $actorRole): void {
            foreach (SiteSetting::KNOWN_KEYS as $key) {
                $value = trim((string) ($this->data[$key] ?? ''));
                $current = SiteSetting::valueFor($key);

                if ($current === $value) {
                    continue;
                }

                SiteSetting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $value, 'updated_by_ref' => $actorRef === null ? null : (string) $actorRef],
                );
                $changed[] = $key;
            }

            // Review fix (round 1): the audit record is written INSIDE the
            // same transaction as the upserts so the SITE_SETTING_UPDATED row
            // commits atomically with the state change (Audit::record's own
            // doc block — "call this from inside an existing transaction ...
            // to get AC4's 'same transaction as the state change' guarantee").
            // The brief-literal placement after `DB::transaction` committed
            // state first and could orphan a settings change without its
            // audit record.
            if ($changed !== []) {
                Audit::record(
                    action: SiteSettingsAuditActions::UPDATED,
                    subject: new AuditSubject('site_settings', implode(',', $changed)),
                    outcome: AuditOutcome::Allowed,
                    actorRef: $actorRef,
                    actorRole: $actorRole,
                    source: AuditSource::Panel,
                );
            }
        });

        Notification::make()->success()->title('Pengaturan disimpan.')->send();
    }
}
