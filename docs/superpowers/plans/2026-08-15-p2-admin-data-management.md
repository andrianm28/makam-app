# P2 — Admin Data Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete admin data management — package versions/items, product variants, vendors, site settings, feature-gate operations, and launch cities — all audited and role-gated, with public flows reading the new seams through fallbacks.

**Architecture:** Four file-disjoint lanes on existing trunk seams. Every resource follows the established Filament 5 pattern (Resource + `Pages/` + `Schemas/` + `Tables/` + `RelationManagers/`), gated by `MasterDataAdminAuthorizerContract` + `auditRoleFor`, writes in `Audit::wrap` with `AuditSource::Panel`, Domain Actions used wherever they exist (package publish/revise/define; gate recorder; price-version recorder precedent). Settings read through a new `SettingsService` (config → env → DB → default). Launch cities: new `LaunchCity` model + seed, with `BookingDraft` validation and `CemeteryPublicQuery::launchCities()` switched to the table (constant fallback). Spec: `docs/superpowers/specs/2026-08-15-admin-data-management-design.md`.

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament 5 / Livewire 4 / PostgreSQL 18 + SQLite (tests).

## Global Constraints

- All resources: `canAccess()` + `getAuthorizationResponse()` via `MasterDataAdminAuthorizerContract` (catches `MasterDataNotAuthorisedException` → deny), `auditRoleFor(ActorContext)` walking `[ADMIN, RESTRICTED_ADMIN, OPERATOR, FINANCE]` (CemeteryResource precedent).
- Every write in `Audit::wrap` with `AuditSource::Panel`, `actorRef: app(ActorContext::class)->identityReference`, `actorRole: <Resource>::auditRoleFor($actor)`; Domain Actions that self-audit (publish/revise/define/record-gate) are NOT double-wrapped.
- `ServicePackageVersion`: statuses ONLY `draft`/`published`; direct published insert refused; published rows immutable (model guards); publication ONLY via `PublishServicePackageVersion` (requires ≥1 item, idempotent); revision ONLY via `ReviseServicePackageVersion`; new package ONLY via `DefineServicePackage`.
- `ServicePackageItem` writes: owning version must be editable (draft) — the model's `assertBothOwningVersionsAreEditable` enforces; surface honest errors, no bypass.
- `ProductVariant` writes: owning product must `hasVariantAxes()` (model enforces).
- `VendorListing` saving asserts `AvailabilityMode::assertKnown` + `EvidenceRequirement::assertKnown` (model enforces); `VendorUser` rows are add/revoke (revoked_at), never deleted.
- Gate transitions ONLY via `GateActivationRecorder::record(...)` (evidence + reason mandatory → `MissingActivationEvidenceException`); gate page actions admin-only + under `ReauthenticationGuard` (fail closed → MFA challenge redirect, P1 pattern).
- `SettingsService` fallback order: `config("site.$key")` → `env($key)` → DB row → `$default`. Secrets stay env-only; provisioning refs (payment merchant/badan-usaha, marketplace badan-usaha) are non-secret identifiers (FIN-DEC) and may live in DB.
- Settings keys (constants in `SiteSetting`): `service_hours`, `support_phone`, `support_whatsapp`, `support_email`, `marketplace_badan_usaha_ref`, `payment_merchant_ref`, `payment_badan_usaha_ref`.
- Launch cities: seed the five canonical (`JAKARTA`, `BOGOR`, `DEPOK`, `TANGERANG`, `BEKASI` — AGENTS.md baseline); admin CRUD allowed (product-owner-approved deviation, recorded in the spec); delete blocked when referenced by booking drafts/orders; `BookingDraft` validation + `CemeteryPublicQuery::launchCities()` fall back to the canonical constants when the table has no active rows (seed guarantees it).
- Indonesian UI copy; `declare(strict_types=1)` + `final` classes; no new event names (only existing outbox events from the domain Actions); no Create/Delete on append-only models.
- Gates: `composer lint`, `composer analyse`, `php artisan test` (SQLite) per lane; CI (incl. PostgreSQL 18 service) gates every merge.
- Worktree execution: branch per lane from `docs/design-system-and-planning`; ledger at `.superpowers/sdd/2026-08-15-p1-admin-order-management/` is P1's — this phase gets its own workspace via the sdd scripts.

---

## Task 1: SiteSetting model + SettingsService + SiteSettingsResource (Lane A)

**Files:**
- Create: `database/migrations/<timestamp>_create_site_settings_table.php`
- Create: `app/Platform/SiteSettings/Models/SiteSetting.php`
- Create: `app/Platform/SiteSettings/SettingsService.php`
- Create: `app/Platform/SiteSettings/SiteSettingsAuditActions.php`
- Create: `app/Platform/SiteSettings/Providers/SiteSettingsServiceProvider.php` (bind `SettingsService` as singleton)
- Modify: `bootstrap/providers.php` (register the provider, alphabetical)
- Create: `app/Filament/Admin/Resources/SiteSettings/SiteSettingsResource.php`
- Create: `app/Filament/Admin/Resources/SiteSettings/Pages/EditSiteSettings.php`
- Create: `app/Filament/Admin/Resources/SiteSettings/Schemas/SiteSettingsForm.php`
- Modify: `app/Platform/Payment/GuardPaymentSession.php` (condition 6 reads settings), `app/Platform/Payment/Actions/OpenPaymentSession.php` (merchantRef), `app/Livewire/Public/Support/HelpCentre.php` (service hours via settings)
- Test: `tests/Feature/SiteSettings/SettingsServiceTest.php`, `tests/Feature/Filament/SiteSettingsResourceTest.php`

**Interfaces:**
- Consumes: `MasterDataAdminAuthorizerContract`, `Audit` (`Audit::wrap`, `AuditSubject`, `AuditOutcome`, `AuditSource::Panel`), `ActorContext`, `config('payment.merchant_ref')`, `config('marketplace.badan_usaha_ref')`, `App\Support\ContactInfo::BUSINESS_HOURS` (fallback for `service_hours`).
- Produces:
  - `SiteSetting` model (table `site_settings`): fillable `['key','value','updated_by_ref']`, `$timestamps = true`; `public const string KEY_SERVICE_HOURS = 'service_hours';` + the other six keys; `public static function valueFor(string $key): ?string`.
  - `SettingsService::setting(string $key, mixed $default = null): mixed` — resolution: `config("site.$key")` → `env($key)` → `SiteSetting::valueFor($key)` → `$default`. (env($key) uses the UPPERCASE key: `env(Str::upper(Str::snake($key)))` — so `service_hours` → `SERVICE_HOURS`; document in the class docblock.)
  - `SiteSettingsAuditActions::UPDATED = 'SITE_SETTING_UPDATED'`.
  - `SiteSettingsResource` — single-record edit (getRecord() returns the one settings row; form fields with per-key validation; save = validate + upsert each key + one `Audit::wrap` per key change).

- [ ] **Step 1: Write the failing SettingsService test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\SiteSettings;

use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_when_nothing_configured(): void
    {
        $this->assertSame('09.00-17.00', app(SettingsService::class)->setting('service_hours', '09.00-17.00'));
    }

    public function test_env_is_consulted_before_db(): void
    {
        putenv('SERVICE_HOURS=07.00-18.00');
        try {
            SiteSetting::query()->create(['key' => 'service_hours', 'value' => '08.00-20.00']);
            $this->assertSame('07.00-18.00', app(SettingsService::class)->setting('service_hours', 'fallback'));
        } finally {
            putenv('SERVICE_HOURS');
        }
    }

    public function test_db_value_used_when_no_env(): void
    {
        putenv('SERVICE_HOURS');
        SiteSetting::query()->create(['key' => 'service_hours', 'value' => '08.00-20.00']);
        $this->assertSame('08.00-20.00', app(SettingsService::class)->setting('service_hours', 'fallback'));
    }

    public function test_config_overrides_env(): void
    {
        putenv('SERVICE_HOURS=07.00-18.00');
        config(['site.service_hours' => '06.00-19.00']);
        try {
            $this->assertSame('06.00-19.00', app(SettingsService::class)->setting('service_hours', 'fallback'));
        } finally {
            putenv('SERVICE_HOURS');
            config(['site.service_hours' => null]);
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `APP_BASE_PATH=<worktree> composer dump-autoload --no-scripts` then `php artisan test tests/Feature/SiteSettings/SettingsServiceTest.php` → FAIL (class not found).

- [ ] **Step 3: Implement model, service, audit constants, provider**

Migration (use `Schema::create('site_settings', ...)`: `string key` unique, `text value`, `string updated_by_ref` nullable, timestamps):

`app/Platform/SiteSettings/Models/SiteSetting.php`:

```php
<?php

declare(strict_types=1);

namespace App\Platform\SiteSettings\Models;

use Illuminate\Database\Eloquent\Model;

final class SiteSetting extends Model
{
    public const string KEY_SERVICE_HOURS = 'service_hours';
    public const string KEY_SUPPORT_PHONE = 'support_phone';
    public const string KEY_SUPPORT_WHATSAPP = 'support_whatsapp';
    public const string KEY_SUPPORT_EMAIL = 'support_email';
    public const string KEY_MARKETPLACE_BADAN_USAHA_REF = 'marketplace_badan_usaha_ref';
    public const string KEY_PAYMENT_MERCHANT_REF = 'payment_merchant_ref';
    public const string KEY_PAYMENT_BADAN_USAHA_REF = 'payment_badan_usaha_ref';

    public const array KNOWN_KEYS = [
        self::KEY_SERVICE_HOURS,
        self::KEY_SUPPORT_PHONE,
        self::KEY_SUPPORT_WHATSAPP,
        self::KEY_SUPPORT_EMAIL,
        self::KEY_MARKETPLACE_BADAN_USAHA_REF,
        self::KEY_PAYMENT_MERCHANT_REF,
        self::KEY_PAYMENT_BADAN_USAHA_REF,
    ];

    protected $table = 'site_settings';

    protected $fillable = ['key', 'value', 'updated_by_ref'];

    public static function valueFor(string $key): ?string
    {
        $row = self::query()->where('key', $key)->first();

        return $row?->value;
    }
}
```

`app/Platform/SiteSettings/SettingsService.php` (singleton — one query cache per request):

```php
<?php

declare(strict_types=1);

namespace App\Platform\SiteSettings;

use App\Platform\SiteSettings\Models\SiteSetting;
use Illuminate\Support\Str;

final class SettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function setting(string $key, mixed $default = null): mixed
    {
        $configured = config("site.{$key}");

        if ($configured !== null) {
            return $configured;
        }

        $envKey = Str::upper(Str::snake($key));
        $envValue = env($envKey);

        if ($envValue !== null && $envValue !== '') {
            return $envValue;
        }

        $this->values ??= SiteSetting::query()->pluck('value', 'key')->all();

        return array_key_exists($key, $this->values) && $this->values[$key] !== ''
            ? $this->values[$key]
            : $default;
    }
}
```

`app/Platform/SiteSettings/SiteSettingsAuditActions.php`: `public const string UPDATED = 'SITE_SETTING_UPDATED';`

`app/Platform/SiteSettings/Providers/SiteSettingsServiceProvider.php` (FaqServiceProvider pattern): `$this->app->singleton(SettingsService::class);` + register in `bootstrap/providers.php` alphabetically.

- [ ] **Step 4: Run the service test** → PASS (4 tests). Then commit: `feat(site-settings): SiteSetting model + SettingsService with config/env/db fallback`.

- [ ] **Step 5: Write the failing resource test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\SiteSettings\SiteSettingsResource;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

final class SiteSettingsResourceTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_back_office_roles_can_access(): void
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);
            $this->assertTrue(SiteSettingsResource::canAccess(), "role {$role}");
        }
    }

    public function test_vendor_role_cannot_access(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::VENDOR);
        $this->actingAs($user);
        $this->assertFalse(SiteSettingsResource::canAccess());
    }
}
```

- [ ] **Step 6: Run to verify it fails** → FAIL (class not found).

- [ ] **Step 7: Implement the resource + form + edit page**

`SiteSettingsResource` — `$model = SiteSetting::class`, navigation label 'Pengaturan Situs', icon `Heroicon::OutlinedCog6Tooth`. Gate identical to BookingOrderResource (MasterData authorizer try/catch). `getPages(): ['edit' => EditSiteSettings::route('/')]`. `public static function getRecord(): ?Model` (Filament single-record pattern — the resource's record = the settings singleton; implement `getRecord()` returning `SiteSetting::firstOrNew([])` or the first row) — if Filament's single-record API fights the append-only style, the page instead renders a Livewire-managed form that upserts keys via the domain seam; keep the page honest and simple: `EditSiteSettings` extends `Page` with a form of the seven fields, `save()` validates per key and upserts + audits. Choose the Page approach (no Eloquent resource plumbing for a non-entity settings singleton).

`Pages/EditSiteSettings.php` (Page with form):

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SiteSettings\Pages;

use App\Filament\Admin\Resources\SiteSettings\SiteSettingsResource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SiteSettingsAuditActions;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

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
        return $schema->components([
            \Filament\Forms\Components\Section::make('Jam layanan')->schema([
                \Filament\Forms\Components\TextInput::make('data.service_hours')
                    ->label('Jam layanan')
                    ->placeholder('Senin–Jumat 08.00–17.00 WIB')
                    ->maxLength(120),
            ]),
            \Filament\Forms\Components\Section::make('Kontak dukungan')->schema([
                \Filament\Forms\Components\TextInput::make('data.support_phone')->label('Telepon')->maxLength(40),
                \Filament\Forms\Components\TextInput::make('data.support_whatsapp')->label('WhatsApp')->maxLength(40),
                \Filament\Forms\Components\TextInput::make('data.support_email')->label('Email')->email()->maxLength(120),
            ]),
            \Filament\Forms\Components\Section::make('Entitas & pemrosesan pembayaran')->schema([
                \Filament\Forms\Components\TextInput::make('data.marketplace_badan_usaha_ref')->label('Ref badan usaha marketplace')->maxLength(120),
                \Filament\Forms\Components\TextInput::make('data.payment_merchant_ref')->label('Ref merchant pembayaran')->maxLength(120),
                \Filament\Forms\Components\TextInput::make('data.payment_badan_usaha_ref')->label('Ref badan usaha pembayaran')->maxLength(120),
            ])->description('Identitas non-rahasia (FIN-DEC). Kredensial tetap di lingkungan (env).'),
        ]);
    }

    public function save(): void
    {
        $actor = app(ActorContext::class);
        $actorRef = $actor->identityReference;
        $actorRole = SiteSettingsResource::auditRoleFor($actor);
        $changed = [];

        DB::transaction(function () use (&$changed, $actorRef): void {
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
        });

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

        Notification::make()->success()->title('Pengaturan disimpan.')->send();
    }
}
```

Blade view `resources/views/filament/admin/resources/site-settings/edit-site-settings.blade.php`: `<x-filament-panels::page>` + `{{ $this->form }}` + a Save button (`wire:click="save"`). Note: the Page form binds `data.*` keys — verify Filament 5's statePath default for a Page form is `data` (if the page form uses a different default statePath, adjust `->statePath('data')` on the form schema or use the real one).

- [ ] **Step 8: Wire the consumers**

`GuardPaymentSession::conditionSix()`: replace `config('payment.merchant_ref', '')` with `app(\App\Platform\SiteSettings\SettingsService::class)->setting(SiteSetting::KEY_PAYMENT_MERCHANT_REF, (string) config('payment.merchant_ref', ''))` and same for `payment_badan_usaha_ref` (the service's config-first fallback keeps the current env behavior identical).
`OpenPaymentSession` merchantRef (`config('payment.merchant_ref', '')`): same settings read.
`HelpCentre.php` `businessHours`: `app(SettingsService::class)->setting(SiteSetting::KEY_SERVICE_HOURS, ContactInfo::BUSINESS_HOURS)`.

- [ ] **Step 9: Run resource test + full gates + commit**

`php artisan test tests/Feature/Filament/SiteSettingsResourceTest.php` → PASS. `composer lint` + `composer analyse`. Commit: `feat(filament): site settings resource wired to settings service`.

---

## Task 2: FeatureGateAdmin page (Lane A)

**Files:**
- Create: `app/Filament/Admin/Pages/FeatureGateAdmin.php`
- Create: `resources/views/filament/admin/pages/feature-gate-admin.blade.php`
- Test: `tests/Feature/Filament/FeatureGateAdminTest.php`

**Interfaces:**
- Consumes: `FeatureGate` model (rows), `GateActivationRecorder::record(string $gateId, int|string $actorReference, string $toState, string $evidenceReference, string $reason, string $actorRole = 'admin', AuditSource $auditSource = AuditSource::Panel): GateActivation`, `MasterDataAdminAuthorizerContract`, `ReauthenticationGuard` + `ReauthenticationRequiredException`, `RequireRecentAuthentication::REASON_SESSION_KEY`, `filament.admin.pages.mfa-challenge` route.
- Produces: `FeatureGateAdmin` page — slug `feature-gates`, nav label 'Gerbang Fitur' (hidden nav optional), list of `FeatureGate::query()->with('activations')->get()` rows (gate_id, capability, type, owner, state badge, evidence_reference, effective_at), per-row open/close Action with required evidence textarea + reason textarea.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\FeatureGateAdmin;
use App\Models\User;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FeatureGate\Models\GateActivation;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

final class FeatureGateAdminTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_vendor_role_cannot_access(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::VENDOR);
        $this->actingAs($user);
        $this->assertFalse(FeatureGateAdmin::canAccess());
    }

    public function test_admin_can_see_gates(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->assertTrue(FeatureGateAdmin::canAccess());
        FeatureGate::query()->create(['gate_id' => 'G-DATA-01', 'capability' => 'data', 'type' => 'feature', 'owner' => 'admin', 'state' => 'closed']);
        $page = new FeatureGateAdmin();
        $this->assertSame(1, $page->gates()->count());
    }
}
```

- [ ] **Step 2: Run to verify it fails** → FAIL (class not found).

- [ ] **Step 3: Implement the page**

`app/Filament/Admin/Pages/FeatureGateAdmin.php` — `Page` with: `canAccess()` (MasterData authorizer — 4 back-office roles VIEW; transitions admin-only), `gates(): Collection` (FeatureGate::with('activations')->orderBy('gate_id')->get()), `transitionGate(string $gateId, string $toState, string $evidenceReference, string $reason): void`:

```php
public function transitionGate(string $gateId, string $toState, string $evidenceReference, string $reason): void
{
    $actor = app(ActorContext::class);

    if (! in_array(ActorRole::ADMIN, $actor->roles, true)) {
        Notification::make()->danger()->title('Hanya admin yang dapat mengubah gerbang fitur.')->send();

        return;
    }

    try {
        app(ReauthenticationGuard::class)->assertFresh($actor);
    } catch (ReauthenticationRequiredException) {
        session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, 'feature_gate');
        session()->put('url.intended', route('filament.admin.pages.feature-gates'));
        Notification::make()->warning()->title('Perlu verifikasi ulang')->send();
        redirect()->route('filament.admin.pages.mfa-challenge');

        return;
    }

    try {
        app(GateActivationRecorder::class)->record(
            gateId: $gateId,
            actorReference: $actor->identityReference ?? 0,
            toState: $toState,
            evidenceReference: $evidenceReference,
            reason: $reason,
            actorRole: FeatureGateAdmin::auditRoleFor($actor),
            auditSource: AuditSource::Panel,
        );
        Notification::make()->success()->title('Gerbang diperbarui.')->send();
    } catch (\Throwable $exception) {
        Notification::make()->danger()->title('Gagal memperbarui gerbang')->body($exception->getMessage())->send();
    }
}
```

`auditRoleFor(ActorContext): string` — same walk as other resources. Blade: table of gates + per-row two buttons (Buka/Tutup) wired to a small Livewire modal (evidence + reason inputs, `wire:click="transitionGate(...)"` with `wire:model` fields `evidence`, `reason` — declare public `string $evidence = ''; string $reason = '';` on the page, cleared after transition).

- [ ] **Step 4: Run test + gates + commit** — `php artisan test tests/Feature/Filament/FeatureGateAdminTest.php` → PASS; lint + analyse. Commit: `feat(filament): feature gate admin page with evidence-gated transitions`.

---

## Task 3: LaunchCity model + seed + domain seam (Lane B)

**Files:**
- Create: `database/migrations/<timestamp>_create_launch_cities_table.php`
- Create: `database/seeders/LaunchCitySeeder.php` (or a migration-based seed — follow the repo's seeding convention; the canonical five MUST land with the table)
- Create: `app/Domain/CemeteryDirectory/Models/LaunchCity.php`
- Create: `app/Domain/CemeteryDirectory/LaunchCityQuery.php`
- Modify: `app/Domain/Booking/Models/BookingDraft.php` (booted saving hook city validation), `app/Domain/CemeteryDirectory/CemeteryPublicQuery.php` (launchCities)
- Test: `tests/Feature/CemeteryDirectory/LaunchCityTest.php`, `tests/Feature/Booking/LaunchCityValidationTest.php`

**Interfaces:**
- Consumes: `LaunchCityCode` constants (canonical codes), `BookingDraft::booted()` saving hook, `CemeteryPublicQuery::launchCities()` (returns `list<array{code,label}>`).
- Produces:
  - `LaunchCity` model (table `launch_cities`): fillable `['code','label','is_active','sort_order']`, casts is_active→boolean, sort_order→integer, `$fillable` with unique code; `booted()` saving asserts `LaunchCityCode::assertKnown` OR accepts new codes? — DECISION: `LaunchCityCode::assertKnown` stays as the VALIDATION of new codes (admin extension allowed but codes still must be uppercase non-blank — validate `Str::upper(trim($code))` and uniqueness; the assertKnown gate applies to the PUBLIC flow fallback only). Model docblock documents this.
  - `LaunchCityQuery::activeCities(): list<array{code: string, label: string}>` — active rows ordered by sort_order; `::isKnown(string $code): bool` — exists in table (active or not) OR in `LaunchCityCode::KNOWN_CODES` (fallback).
  - `BookingDraft` saving hook: `if ($draft->city_code !== null) { if (! LaunchCityQuery::isKnown($draft->city_code)) { throw new InvalidArgumentException("Unknown launch city code [{$draft->city_code}]."); } }`
  - `CemeteryPublicQuery::launchCities()`: `return LaunchCityQuery::activeCities() !== [] ? LaunchCityQuery::activeCities() : array_map(... LaunchCityCode::KNOWN_CODES ...)` (existing label derivation).

- [ ] **Step 1: Write the failing domain test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\CemeteryDirectory;

use App\Domain\CemeteryDirectory\Models\LaunchCity;
use App\Domain\CemeteryDirectory\LaunchCityQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LaunchCityTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_cities_ordered_by_sort_order(): void
    {
        LaunchCity::query()->create(['code' => 'BEKASI', 'label' => 'Bekasi', 'sort_order' => 5]);
        LaunchCity::query()->create(['code' => 'JAKARTA', 'label' => 'Jakarta', 'sort_order' => 1]);
        LaunchCity::query()->create(['code' => 'BOGOR', 'label' => 'Bogor', 'sort_order' => 2, 'is_active' => false]);

        $cities = LaunchCityQuery::activeCities();

        $this->assertSame(['JAKARTA', 'BEKASI'], array_column($cities, 'code'));
    }

    public function test_is_known_reads_table_then_constants(): void
    {
        LaunchCity::query()->create(['code' => 'SUKABUMI', 'label' => 'Sukabumi']);

        $this->assertTrue(LaunchCityQuery::isKnown('SUKABUMI'));
        $this->assertTrue(LaunchCityQuery::isKnown('TANGERANG'));
        $this->assertFalse(LaunchCityQuery::isKnown('NONEXISTENT'));
    }
}
```

- [ ] **Step 2: Run to verify it fails** → FAIL.

- [ ] **Step 3: Implement model + query + migration + seed**

Migration: `launch_cities` — `string code` unique, `string label`, `boolean is_active` default true, `unsignedInteger sort_order` default 0, timestamps.
`LaunchCity` model per the Produces block. `LaunchCityQuery`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\Models\LaunchCity;

final class LaunchCityQuery
{
    /** @return list<array{code: string, label: string}> */
    public static function activeCities(): array
    {
        return LaunchCity::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['code', 'label'])
            ->map(fn (LaunchCity $city): array => ['code' => $city->code, 'label' => $city->label])
            ->all();
    }

    public static function isKnown(string $code): bool
    {
        return LaunchCity::query()->where('code', $code)->exists()
            || in_array($code, LaunchCityCode::KNOWN_CODES, true);
    }
}
```

Seed: create rows for the five canonical codes with `Str::title(mb_strtolower($code))` labels, sort_order 1..5 — follow the repo's example-data seeding convention (check `database/seeders/DatabaseSeeder.php` call chain; add the seeder there and/or a `--class`-runnable seeder; ALSO ensure the migration-time presence via `database/seeders/LaunchCitySeeder.php` called from DatabaseSeeder).

- [ ] **Step 4: Switch the public seams**

`BookingDraft::booted()` — replace `LaunchCityCode::assertKnown($draft->city_code);` with the `LaunchCityQuery::isKnown` check + `InvalidArgumentException` (keep `BookingServiceType` line unchanged). `CemeteryPublicQuery::launchCities()` — `activeCities()` with `LaunchCityCode` fallback per the Produces block.

- [ ] **Step 5: Run domain + booking tests + gates + commit**

`php artisan test tests/Feature/CemeteryDirectory/LaunchCityTest.php tests/Feature/Booking/LaunchCityValidationTest.php` (add a validation test: draft with `city_code = 'SUKABUMI'` saves when a LaunchCity row exists; draft with `city_code = 'NONEXISTENT'` throws) → PASS; lint + analyse. Commit: `feat(city-directory): launch cities table-backed with canonical seed`.

---

## Task 4: LaunchCityResource (Lane B)

**Files:**
- Create: `app/Filament/Admin/Resources/LaunchCities/LaunchCityResource.php`
- Create: `app/Filament/Admin/Resources/LaunchCities/Tables/LaunchCitiesTable.php`
- Create: `app/Filament/Admin/Resources/LaunchCities/Schemas/LaunchCityForm.php`
- Create: `app/Filament/Admin/Resources/LaunchCities/Pages/ListLaunchCities.php`
- Create: `app/Filament/Admin/Resources/LaunchCities/Pages/CreateLaunchCity.php`
- Create: `app/Filament/Admin/Resources/LaunchCities/Pages/EditLaunchCity.php`
- Test: `tests/Feature/Filament/LaunchCityResourceTest.php`

**Interfaces:**
- Consumes: `LaunchCity`, `LaunchCityQuery`, `MasterDataAdminAuthorizerContract`, `Audit` (audit actions `LAUNCH_CITY_CREATED/UPDATED/DELETED/REORDERED` — new `LaunchCityAuditActions` constants class).
- Produces: resource (gate + `auditRoleFor`), table (code, label, active badge, sort_order, reorder up/down actions using the swap pattern), form (code uppercase + unique + not-blank; label; active toggle; sort order), delete blocked when a booking draft references the code (query `booking_drafts.where('city_code', $code)` — if any → honest denial notification).

- [ ] **Step 1: Write the failing resource test** — access matrix (4 roles ✓, vendor ✗) + create/update/delete audit rows + reorder swap + delete-blocked-with-draft copy. Pattern: `tests/Feature/Filament/BookingOrderResourceAccessTest.php` + `PackagesRelationManager`'s `Audit::wrap` shape.

- [ ] **Step 2: Run to verify it fails** → FAIL.

- [ ] **Step 3: Implement resource, table, form, pages**

Follow `CemeteryResource` + `FaqArticles` exactly (the plan's Task 4 in P1 is the reference for structure). Writes via `Audit::wrap` with the new audit actions; delete action: check `\App\Domain\Booking\Models\BookingDraft::query()->where('city_code', $code)->exists()` → denial notification 'Kota masih digunakan oleh pemesanan yang tersimpan.'; reorder = swap sort_order of the two adjacent rows (FaqArticles moveUp/moveDown precedent).

- [ ] **Step 4: Run test + gates + commit** — PASS; lint + analyse. Commit: `feat(filament): launch city resource with audited CRUD and reorder`.

---

## Task 5: ServicePackageResource (Lane C)

**Files:**
- Create: `app/Filament/Admin/Resources/ServicePackages/ServicePackageResource.php`
- Create: `app/Filament/Admin/Resources/ServicePackages/Tables/ServicePackagesTable.php`
- Create: `app/Filament/Admin/Resources/ServicePackages/Schemas/ServicePackageForm.php`
- Create: `app/Filament/Admin/Resources/ServicePackages/Pages/ListServicePackages.php`
- Create: `app/Filament/Admin/Resources/ServicePackages/Pages/ViewServicePackage.php`
- Create: `app/Filament/Admin/Resources/ServicePackages/RelationManagers/VersionsRelationManager.php`
- Create: `app/Filament/Admin/Resources/ServicePackages/RelationManagers/VersionItemsRelationManager.php`
- Test: `tests/Feature/Filament/ServicePackageResourceTest.php`

**Interfaces:**
- Consumes: `ServicePackage`, `ServicePackageVersion`, `ServicePackageItem`, `ServicePackageVersionStatus`, `ServicePackageItemType`, `FulfillmentOwner`, `DefineServicePackage::__invoke(string $code, string $name, array $items, int|string $actorReference, ?string $description = null, string $actorRole = 'admin', AuditSource $auditSource = AuditSource::Panel, ?string $reason = null): ServicePackage`, `PublishServicePackageVersion::__invoke(ServicePackageVersion $version, int|string $actorReference, string $actorRole = 'admin', AuditSource $auditSource = AuditSource::Panel, ?string $reason = null): ServicePackageVersion`, `ReviseServicePackageVersion::__invoke(ServicePackage $package, int|string $actorReference, string $actorRole = 'admin', AuditSource $auditSource = AuditSource::Panel, ?string $reason = null): ServicePackageVersion`.
- Produces: resource (list: code, name, cemetery packages count?, is_active; view page with the two relation managers). Create routes through `DefineServicePackage` (form: code, name, description, is_active + first-version items — the items array shape from the reference: `list<array{service_definition_id, item_type, quantity, unit, fulfillment_owner, ...}>`; simplest honest create: define the package with zero items via the action? NO — DefineServicePackage throws on `items === []` — so the Create form includes a repeatable items schema; fall back to creating items via the VersionItems relation manager after creation if the repeatable schema fights Filament — document the choice). Versions relation manager: table (version_number, status badge, published_at, items count) + header actions: 'Revisi' (ReviseServicePackageVersion — disabled/hidden when draft exists or never published), per-draft-row 'Terbitkan' (PublishServicePackageVersion — hidden for published). VersionItems relation manager: items of the SELECTED draft version — list (service definition, item_type badge, quantity, unit, fulfillment_owner) + create/edit routed through plain model writes INSIDE `Audit::wrap` (the model's editable-version guard enforces draft-only; published versions surface `PublishedServicePackageVersionIsImmutableException` as an honest notification).

- [ ] **Step 1: Write the failing resource test** — access matrix; CreatePackage via the action (code+items) lands a DRAFT v1 with items; publish via `PublishServicePackageVersion` (happy path + zero-items refusal); revise creates v2 draft copying items; published version's items are immutable (direct item write throws); audit rows exist for the three actions (the actions self-audit — assert `audit_events` rows with the catalogued action names).

- [ ] **Step 2: Run to verify it fails** → FAIL.

- [ ] **Step 3: Implement resource + relation managers** — mirror `PackagesRelationManager` (instance `form(Schema)` + `protected function makeTable()`, `canViewForRecord` override, `->authorize()` on actions, action `->using()` calling the domain actions). `ServicePackagesTable`: code, name, active, versions count, current published version badge. All labels Indonesian.

- [ ] **Step 4: Run test + gates + commit** — PASS; lint + analyse. Commit: `feat(filament): service package resource with publish/revise via domain actions`.

---

## Task 6: ProductResource VariantsRelationManager (Lane C)

**Files:**
- Create: `app/Filament/Admin/Resources/ProductResource/RelationManagers/VariantsRelationManager.php`
- Create: `app/Domain/Marketplace/ProductVariantAuditActions.php`
- Test: `tests/Feature/Filament/ProductVariantRelationManagerTest.php`

**Interfaces:**
- Consumes: `Product`, `ProductVariant` (booted guard: owning product must `hasVariantAxes()`), `MasterDataAdminAuthorizerContract`, `Audit`.
- Produces: `VariantsRelationManager` (relationship `variants`, title 'Varian'), table (size/material/color/calligraphy_style, sort_order, preview image), Create/Edit actions with `Audit::wrap` + `ProductVariantAuditActions::CREATED/UPDATED`. Form fields per the model's fillable (all optional except sort_order default; the model's saving guard rejects non-GRAVESTONE products with the honest error).

- [ ] **Step 1: Write the failing test** — access gate; variant create on a GRAVESTONE product succeeds with audit row; variant create on a non-variant product (e.g. `FLOWER_*` code — check `ProductCode::requiresVariants` values) throws the model's `InvalidArgumentException`.

- [ ] **Step 2: Run to verify it fails** → FAIL.

- [ ] **Step 3: Implement** — `PackagesRelationManager` pattern; the Create/Edit `->using()` wraps `Audit::wrap` (action `PRODUCT_VARIANT_CREATED`/`_UPDATED`).

- [ ] **Step 4: Run test + gates + commit** — PASS; lint + analyse. Commit: `feat(filament): product variant relation manager with audit`.

---

## Task 7: VendorResource (Lane D)

**Files:**
- Create: `app/Filament/Admin/Resources/Vendors/VendorResource.php`
- Create: `app/Filament/Admin/Resources/Vendors/Tables/VendorsTable.php`
- Create: `app/Filament/Admin/Resources/Vendors/Schemas/VendorForm.php`
- Create: `app/Filament/Admin/Resources/Vendors/Pages/ListVendors.php`
- Create: `app/Filament/Admin/Resources/Vendors/Pages/CreateVendor.php`
- Create: `app/Filament/Admin/Resources/Vendors/Pages/EditVendor.php`
- Create: `app/Filament/Admin/Resources/Vendors/RelationManagers/MembersRelationManager.php`
- Create: `app/Filament/Admin/Resources/Vendors/RelationManagers/ListingsRelationManager.php`
- Create: `app/Filament/Admin/Resources/Vendors/RelationManagers/AvailabilityRelationManager.php`
- Create: `app/Domain/Marketplace/VendorAuditActions.php`
- Test: `tests/Feature/Filament/VendorResourceTest.php`

**Interfaces:**
- Consumes: `Vendor` (fillable name/is_active; relations members(), listings(), availability() — VERIFY the availability relation name on Vendor during implementation and use the real one), `VendorUser`, `VendorListing` (saving asserts AvailabilityMode/EvidenceRequirement known — surface honest errors), `VendorAvailability` (available_date, capacity, is_blocked), `MasterDataAdminAuthorizerContract`, `Audit`.
- Produces: resource (Create/Edit/List; table: name, active badge, listings count; delete blocked while listings or members exist — restrict with honest copy), Members relation manager (add = VendorUser::create(vendor_id, actor_identifier); revoke = set revoked_at — list shows active members; no hard delete), Listings relation manager (CRUD all fields + product select via relationship; availability/evidence selects from the KNOWN arrays with Indonesian labels), Availability relation manager (CRUD available_date/capacity/is_blocked — table by date). All writes `Audit::wrap` + `VendorAuditActions::VENDOR_CREATED/UPDATED/MEMBER_ADDED/MEMBER_REVOKED/LISTING_CREATED/LISTING_UPDATED/AVAILABILITY_CREATED/AVAILABILITY_UPDATED`.

- [ ] **Step 1: Write the failing resource test** — access matrix; vendor create/update audit; member add + revoke (revoked_at set, no delete); listing create with real AvailabilityMode/EvidenceRequirement values; availability create; delete blocked with members.

- [ ] **Step 2: Run to verify it fails** → FAIL.

- [ ] **Step 3: Implement resource + relation managers** — `PackagesRelationManager` pattern; `VendorResource::auditRoleFor` same walk.

- [ ] **Step 4: Run test + gates + commit** — PASS; lint + analyse. Commit: `feat(filament): vendor resource with members, listings, availability`.

---

## Task 8: Docs + browser UAT + whole-branch review (post-merge)

**Files:**
- Modify: `docs/product/screen-inventory.md` (add ADM-070 Pengaturan Situs, ADM-080 Gerbang Fitur, ADM-090 Kota Layanan, ADM-100 Paket Layanan, ADM-110 Varian Produk, ADM-120 Vendor), `docs/domain/traceability-matrix.md` (mark the P2 rows Covered with real test file names — only rows that exist in tests).
- Test: Playwright harness additions (run on dev after deploy).

- [ ] **Step 1: Update screen inventory + traceability** (find the canonical docs; in-place edits; commit `docs: screen inventory and traceability for P2 admin data management`).
- [ ] **Step 2: Deploy to dev** (digest → compose update → migrate → health check).
- [ ] **Step 3: Browser UAT on dev** (admin login → MFA → each new resource list/create/edit smoke: settings save + HelpCentre service-hours reflection; gate page open with evidence + re-auth prompt; city add → public wizard lists it; package create → publish → revise; vendor create + member; product variant on a gravestone product).
- [ ] **Step 4: Whole-branch review** (most capable model, full phase diff, ledger minors triage, bounded fix wave + scoped re-review per the P1 rhythm) then final merge + deploy.

---

## Self-review notes

- **Spec coverage:** §4.1 → Task 5; §4.2 → Task 6; §4.3 → Task 7; §4.4 → Task 1; §4.5 → Task 2; §4.6 → Tasks 3+4; §5/§6 → per-task error handling; §7 → per-task tests + Task 8; §8 → Task 8.
- **Type consistency:** `SettingsService::setting` key names = `SiteSetting::KEY_*` constants everywhere; `LaunchCityQuery::activeCities/isKnown` used by both BookingDraft and CemeteryPublicQuery; the three package actions' signatures copied verbatim from the reference; `GateActivationRecorder::record` named args match the reference.
- **Known drift risks to resolve at implementation time:** Filament 5 Page-form statePath for EditSiteSettings (`data.*` keys); the Create form's repeatable-items schema vs DefineServicePackage's items shape (fallback documented); Vendor's availability relation name; `ProductCode::requiresVariants` codes list; the seeding convention (seeder class + DatabaseSeeder chain vs migration-based seed); whether `bootstrap/providers.php` entries are alphabetical with comments.
