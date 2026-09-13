<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\AgreementCertificate\Actions\AcceptAgreement;
use App\Domain\AgreementCertificate\Actions\CreateAgreement;
use App\Domain\AgreementCertificate\Actions\IssueCertificate;
use App\Domain\AgreementCertificate\AgreementStatus;
use App\Domain\AgreementCertificate\AgreementType;
use App\Domain\AgreementCertificate\CertificateStatus;
use App\Domain\AgreementCertificate\CertificateType;
use App\Domain\AgreementCertificate\Models\Agreement;
use App\Domain\AgreementCertificate\Models\Certificate;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Filament\Admin\Resources\Agreements\AgreementsResource;
use App\Filament\Admin\Resources\Agreements\Pages\ViewAgreement;
use App\Filament\Admin\Resources\Certificates\CertificatesResource;
use App\Filament\Admin\Resources\Certificates\Pages\ListCertificates;
use App\Filament\Admin\Resources\Certificates\Pages\ViewCertificate;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage;
use App\Platform\DocumentVault\Contracts\ObjectStorage;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Task 2 (P5a, Lane 1) — the admin `CertificatesResource` and
 * `AgreementsResource` surfaces.
 *
 * 1. Access matrix: the four back-office roles view both resources; a bare
 *    customer and a vendor fail closed.
 * 2. Issuance through the resource with a REAL vault upload: the Terbitkan
 *    action runs the whole `UploadDocument` seam (quarantine → scan →
 *    promote → the vault document is `Accepted`) and then the domain
 *    `IssueCertificate` — the issued row references the accepted document.
 * 3. The issuer role gate at BOTH layers: operator/finance never see the
 *    issuing actions, AND a direct wire call on the action is honestly
 *    denied with a danger notification and no write.
 * 4. Honest refusal for an ineligible subject (no certificate, no vault
 *    document).
 * 5. Revoke/replace through the resource with the audit + outbox evidence.
 * 6. Agreements: accept (AC2 binding) and supersede (AC5 history) through
 *    the resource.
 */
final class CertificateAdminTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // A throwaway object-storage root per test — the same precedent as
        // the DocumentVault feature tests: the resource's real vault upload
        // must never touch the dev `storage/app/private` tree.
        $this->storageRoot = sys_get_temp_dir().'/certificate-admin-vault-'.Str::random(12);
        mkdir($this->storageRoot, 0700, true);
        $this->app->instance(ObjectStorage::class, new LocalFilesystemObjectStorage($this->storageRoot));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);

        parent::tearDown();
    }

    private function actingUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, $role);
        $this->actingAs($user);
        // Finding SEC-03 (6 Sep 2026 audit): certificate issue/revoke/replace
        // now require a fresh re-authentication (ReauthenticationGuard),
        // same fixture FeatureGateAdminTest/RequireRecentAuthenticationMiddlewareTest
        // establish — a non-revoked actor_sessions row with a fresh
        // last_authenticated_at, which LocalUsersTableIdentityAccessAdapter::
        // resolveLastAuthenticatedAt() reads.
        $this->seedActorSession($user, CarbonImmutable::now());
        $this->forgetResolvedActorContext();

        return $user;
    }

    /**
     * `updateOrCreate`, not `create`: several tests call this twice for the
     * same user — once via `actingUserWithRole()` (fresh) and again to
     * overwrite it as stale — and `(user_id, session_id)` is uniquely
     * constrained.
     */
    private function seedActorSession(User $user, CarbonImmutable $lastAuthenticatedAt): ActorSession
    {
        return ActorSession::query()->updateOrCreate(
            ['user_id' => $user->id, 'session_id' => 'test-session-'.$user->id],
            ['guard' => 'web', 'last_authenticated_at' => $lastAuthenticatedAt, 'revoked_at' => null],
        );
    }

    private function makePaidOrder(): Order
    {
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MENUNGGU_PEMBAYARAN->value,
        ]);

        app(RecordOrderStatusChange::class)($order, OrderStatus::DIBAYAR, 'actor:system', 'system');

        return $order;
    }

    private function makePendingOrder(): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
        ]);
    }

    private function makeDraftAgreement(Order $order): Agreement
    {
        return app(CreateAgreement::class)(
            AgreementType::PreNeedAgreement,
            $order,
            'admin:1',
            'admin',
            [
                'price_guarantee' => 'Harga terkunci selama masa perjanjian.',
                'cancellation_refund' => 'Pengembalian dana sesuai ketentuan.',
                'transferability' => 'Dapat dialihkan kepada keluarga.',
                'term' => '10 tahun',
                'included_services' => 'Survei lokasi, pemeliharaan, pemakaman.',
                'responsible_entity' => 'PT Makam Indonesia',
            ],
        );
    }

    /**
     * The DocumentVault feature-test file fixture shape (UploadDocumentTest's
     * `uploadedFile()` + `minimalPdf()`): a real, readable PDF upload, built
     * through `UploadedFile::fake()` — the one constructor whose public
     * `name` property Livewire's own test `upload()` pipeline reads.
     */
    private function vaultPdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'sertifikat.pdf',
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF",
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }

    // =====================================================================
    // Access matrix — both resources
    // =====================================================================

    public function test_both_resources_fail_closed_outside_the_back_office_roles(): void
    {
        $this->assertFalse(CertificatesResource::canAccess());
        $this->assertFalse(AgreementsResource::canAccess());

        $this->actingAs(User::factory()->create());
        $this->forgetResolvedActorContext();
        $this->assertFalse(CertificatesResource::canAccess());
        $this->assertFalse(AgreementsResource::canAccess());

        $vendor = User::factory()->create();
        $this->grantRoleTo($vendor, ActorRole::VENDOR);
        $this->actingAs($vendor);

        $this->assertFalse(CertificatesResource::canAccess());
        $this->assertFalse(AgreementsResource::canAccess());
    }

    public function test_back_office_roles_access_both_resources(): void
    {
        foreach ([
            ActorRole::ADMIN,
            ActorRole::RESTRICTED_ADMIN,
            ActorRole::OPERATOR,
            ActorRole::FINANCE,
        ] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(
                CertificatesResource::canAccess(),
                "Expected role [{$role}] to access the certificates resource.",
            );
            $this->assertTrue(
                AgreementsResource::canAccess(),
                "Expected role [{$role}] to access the agreements resource.",
            );
            $this->forgetResolvedActorContext();
        }
    }

    // =====================================================================
    // Issuance via the resource with a REAL vault upload
    // =====================================================================

    public function test_an_issuer_issues_a_certificate_through_the_resource_with_a_real_vault_upload(): void
    {
        $order = $this->makePaidOrder();
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ListCertificates::class)
            ->callAction('terbitkan', data: [
                'subject' => Order::class.'|'.$order->getKey(),
                'document_file' => $this->vaultPdf(),
            ])
            ->assertNotified('Sertifikat diterbitkan.')
            ->assertHasNoActionErrors();

        $certificate = Certificate::query()->sole();
        $this->assertSame(CertificateStatus::Issued->value, $certificate->status);
        $this->assertSame(1, $certificate->version_number);
        $this->assertSame(CertificateType::OrderSettlement->value, $certificate->type);
        $this->assertSame(Order::class, $certificate->subject_type);
        $this->assertSame((string) $order->getKey(), $certificate->subject_id);

        // The real vault upload completed the full quarantine → scan →
        // promote pipeline: the referenced document is Accepted.
        $document = Document::query()->sole();
        $this->assertSame(DocumentState::Accepted, $document->state);
        $this->assertSame((string) $document->getKey(), $certificate->document_id);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'CERTIFICATE_ISSUED',
            'subject_id' => (string) $certificate->getKey(),
            'actor_role' => 'admin',
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'certificate.issued.v1',
            'aggregate_id' => (string) $certificate->getKey(),
        ]);
    }

    // =====================================================================
    // Step-up re-authentication (finding SEC-03, 6 Sep 2026 audit)
    // =====================================================================

    /**
     * `actingUserWithRole()` always seeds a FRESH session; these three
     * regression tests each override it with a stale one afterward, so the
     * role/authorize gate still passes (proving the redirect is genuinely
     * the freshness check, not a role failure) while the session itself is
     * too old.
     */
    public function test_issuing_a_certificate_with_a_stale_session_redirects_and_writes_nothing(): void
    {
        $order = $this->makePaidOrder();
        $user = $this->actingUserWithRole(ActorRole::ADMIN);
        $this->seedActorSession($user, CarbonImmutable::now()->subHour());
        $this->forgetResolvedActorContext();

        Livewire::test(ListCertificates::class)
            ->callAction('terbitkan', data: [
                'subject' => Order::class.'|'.$order->getKey(),
                'document_file' => $this->vaultPdf(),
            ])
            ->assertNotified('Perlu verifikasi ulang')
            ->assertRedirect(route('filament.admin.pages.verifikasi-ulang-kata-sandi'));

        $this->assertSame(0, Certificate::query()->count());
        $this->assertSame(0, Document::query()->count());
    }

    public function test_revoking_a_certificate_with_a_stale_session_redirects_and_writes_nothing(): void
    {
        $order = $this->makePaidOrder();
        $certificate = app(IssueCertificate::class)(
            CertificateType::OrderSettlement,
            $order,
            'admin:1',
            'admin',
            null,
        );
        $user = $this->actingUserWithRole(ActorRole::ADMIN);
        $this->seedActorSession($user, CarbonImmutable::now()->subHour());
        $this->forgetResolvedActorContext();

        Livewire::test(ViewCertificate::class, ['record' => $certificate->getKey()])
            ->callAction('cabut', data: ['reason' => 'Percobaan tanpa sesi segar.'])
            ->assertNotified('Perlu verifikasi ulang')
            ->assertRedirect(route('filament.admin.pages.verifikasi-ulang-kata-sandi'));

        $this->assertSame(CertificateStatus::Issued->value, $certificate->fresh()->status);
        $this->assertSame(0, AuditEvent::query()->where('action', 'CERTIFICATE_REVOKED')->count());
    }

    public function test_replacing_a_certificate_with_a_stale_session_redirects_and_writes_nothing(): void
    {
        $order = $this->makePaidOrder();
        $certificate = app(IssueCertificate::class)(
            CertificateType::OrderSettlement,
            $order,
            'admin:1',
            'admin',
            null,
        );
        $user = $this->actingUserWithRole(ActorRole::ADMIN);
        $this->seedActorSession($user, CarbonImmutable::now()->subHour());
        $this->forgetResolvedActorContext();

        Livewire::test(ViewCertificate::class, ['record' => $certificate->getKey()])
            ->callAction('ganti', data: ['reason' => 'Percobaan tanpa sesi segar.'])
            ->assertNotified('Perlu verifikasi ulang')
            ->assertRedirect(route('filament.admin.pages.verifikasi-ulang-kata-sandi'));

        $this->assertSame(CertificateStatus::Issued->value, $certificate->fresh()->status);
        $this->assertSame(1, Certificate::query()->count());
    }

    // =====================================================================
    // The issuer role gate — both layers
    // =====================================================================

    public function test_operator_and_finance_never_see_the_issuer_actions(): void
    {
        $order = $this->makePaidOrder();
        $certificate = app(IssueCertificate::class)(
            CertificateType::OrderSettlement,
            $order,
            'admin:1',
            'admin',
            null,
        );

        foreach ([ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);
            $this->forgetResolvedActorContext();

            Livewire::test(ListCertificates::class)
                ->assertOk()
                ->assertActionHidden('terbitkan');

            Livewire::test(ViewCertificate::class, ['record' => $certificate->getKey()])
                ->assertOk()
                ->assertActionHidden('cabut')
                ->assertActionHidden('ganti');
        }
    }

    /**
     * The wire-level refusal for a non-issuer: Filament refuses to even
     * MOUNT an unauthorized action (the `->authorize()` render gate hides
     * it), so an operator's direct wire call never reaches the modal —
     * "the button was not rendered" is the first layer, and the in-closure
     * re-check in `run()` is the second (unreachable from the outside, by
     * design). Either way: no notification surface, no write.
     */
    public function test_an_operator_wire_call_on_the_issue_action_is_refused(): void
    {
        $order = $this->makePaidOrder();
        $this->actingUserWithRole(ActorRole::OPERATOR);

        $component = Livewire::test(ListCertificates::class)
            ->assertActionHidden('terbitkan');

        $component->call('mountAction', 'terbitkan', []);

        $this->assertSame(0, count($component->instance()->mountedActions));
        $this->assertSame(0, Certificate::query()->count());
        $this->assertSame(0, Document::query()->count());
    }

    /**
     * The same refusal mid-session: the action mounts while the actor is
     * an issuer, the issuer role is then revoked (the race the in-closure
     * re-check exists for), and the wire call is refused before any write.
     */
    public function test_the_issue_action_is_refused_after_the_issuer_role_is_revoked_mid_session(): void
    {
        $order = $this->makePaidOrder();
        $admin = $this->actingUserWithRole(ActorRole::ADMIN);

        $component = Livewire::test(ListCertificates::class)
            ->call('mountAction', 'terbitkan', []);

        $this->assertSame(1, count($component->instance()->mountedActions));

        $this->revokeRoleFrom($admin, ActorRole::ADMIN);
        $this->forgetResolvedActorContext();

        $component->call('callMountedAction', []);

        $this->assertSame(0, Certificate::query()->count());
        $this->assertSame(0, Document::query()->count());
    }

    /**
     * An ineligible subject is refused honestly at the surface: the subject
     * select only offers eligible subjects, and a wire-level submit of an
     * ineligible value is refused by the options validation with an inline
     * error — no certificate, no vault document (the domain re-checks
     * eligibility inside `IssueCertificate` regardless).
     */
    public function test_issuance_refuses_an_ineligible_subject_with_an_honest_inline_error(): void
    {
        $order = $this->makePendingOrder();
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ListCertificates::class)
            ->callAction('terbitkan', data: [
                'subject' => Order::class.'|'.$order->getKey(),
                'document_file' => $this->vaultPdf(),
            ])
            ->assertHasActionErrors(['subject' => ['in']]);

        $this->assertSame(0, Certificate::query()->count());
        $this->assertSame(0, Document::query()->count());
    }

    // =====================================================================
    // Revoke + replace through the resource
    // =====================================================================

    public function test_an_issuer_revokes_through_the_resource_with_a_reason(): void
    {
        $order = $this->makePaidOrder();
        $certificate = app(IssueCertificate::class)(
            CertificateType::OrderSettlement,
            $order,
            'admin:1',
            'admin',
            null,
        );
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewCertificate::class, ['record' => $certificate->getKey()])
            ->callAction('cabut', data: ['reason' => 'Kesalahan data penerbitan.'])
            ->assertHasNoActionErrors()
            ->assertNotified('Sertifikat dicabut.')
            ->assertActionHidden('cabut')
            ->assertActionHidden('ganti');

        $this->assertSame(CertificateStatus::Revoked->value, $certificate->fresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'CERTIFICATE_REVOKED',
            'subject_id' => (string) $certificate->getKey(),
            'reason' => 'Kesalahan data penerbitan.',
        ]);
    }

    public function test_revoke_requires_a_reason(): void
    {
        $order = $this->makePaidOrder();
        $certificate = app(IssueCertificate::class)(
            CertificateType::OrderSettlement,
            $order,
            'admin:1',
            'admin',
            null,
        );
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewCertificate::class, ['record' => $certificate->getKey()])
            ->callAction('cabut', data: ['reason' => '   '])
            ->assertHasActionErrors(['reason' => ['required']]);

        $this->assertSame(CertificateStatus::Issued->value, $certificate->fresh()->status);
    }

    public function test_an_issuer_replaces_through_the_resource_preserving_history(): void
    {
        $order = $this->makePaidOrder();
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ListCertificates::class)
            ->callAction('terbitkan', data: [
                'subject' => Order::class.'|'.$order->getKey(),
                'document_file' => $this->vaultPdf(),
            ])
            ->assertHasNoActionErrors();

        $first = Certificate::query()->sole();

        Livewire::test(ViewCertificate::class, ['record' => $first->getKey()])
            ->callAction('ganti', data: ['reason' => 'Dokumen diperbarui.'])
            ->assertHasNoActionErrors();

        $this->assertSame(CertificateStatus::Replaced->value, $first->fresh()->status);

        $replacement = Certificate::query()->orderByDesc('version_number')->first();
        $this->assertSame(2, $replacement->version_number);
        $this->assertSame(CertificateStatus::Issued->value, $replacement->status);
        $this->assertSame(2, Certificate::query()->count());

        $this->assertDatabaseHas('audit_events', [
            'action' => 'CERTIFICATE_REPLACED',
            'subject_id' => (string) $replacement->getKey(),
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'certificate.replaced.v1',
            'aggregate_id' => (string) $replacement->getKey(),
        ]);
    }

    // =====================================================================
    // Agreements — accept (AC2) + supersede (AC5)
    // =====================================================================

    public function test_an_agreement_can_be_accepted_through_the_resource_binding_the_exact_version(): void
    {
        $order = $this->makePaidOrder();
        $agreement = $this->makeDraftAgreement($order);
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewAgreement::class, ['record' => $agreement->getKey()])
            ->callAction('terima', data: ['quote_id' => 'Q-2026-0001'])
            ->assertHasNoActionErrors()
            ->assertNotified('Perjanjian diterima.')
            ->assertActionHidden('terima');

        $fresh = $agreement->fresh();
        $this->assertSame(AgreementStatus::Accepted->value, $fresh->status);
        $this->assertNotNull($fresh->accepted_by_ref);
        $this->assertSame('Q-2026-0001', $fresh->accepted_quote_id);
        $this->assertSame((string) $agreement->getKey(), $fresh->accepted_agreement_version_id);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'AGREEMENT_ACCEPTED',
            'subject_id' => (string) $agreement->getKey(),
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'agreement.accepted.v1',
            'aggregate_id' => (string) $agreement->getKey(),
        ]);
    }

    public function test_an_agreement_can_be_superseded_through_the_resource_preserving_history(): void
    {
        $order = $this->makePaidOrder();
        $draft = $this->makeDraftAgreement($order);
        $agreement = app(AcceptAgreement::class)(
            $draft,
            'admin:1',
            'Q-2026-0001',
            (string) $draft->getKey(),
        );
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewAgreement::class, ['record' => $agreement->getKey()])
            ->callAction('supersesi')
            ->assertHasNoActionErrors()
            ->assertNotified('Versi baru perjanjian dibuat.');

        $this->assertSame(AgreementStatus::Superseded->value, $agreement->fresh()->status);

        $next = Agreement::query()->orderByDesc('version_number')->first();
        $this->assertSame(2, $next->version_number);
        $this->assertSame(AgreementStatus::Draft->value, $next->status);
        $this->assertSame(2, Agreement::query()->count());

        $this->assertDatabaseHas('audit_events', [
            'action' => 'AGREEMENT_SUPERSEDED',
            'subject_id' => (string) $agreement->getKey(),
        ]);
    }
}
