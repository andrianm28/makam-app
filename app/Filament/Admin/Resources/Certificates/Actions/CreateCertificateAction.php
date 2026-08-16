<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Certificates\Actions;

use App\Domain\AgreementCertificate\Actions\IssueCertificate;
use App\Domain\AgreementCertificate\CertificateEligibilityPolicy;
use App\Domain\AgreementCertificate\CertificateType;
use App\Domain\AgreementCertificate\Exceptions\CertificateEligibilityNotMetException;
use App\Domain\AgreementCertificate\Exceptions\CertificateIssuerNotAuthorisedException;
use App\Domain\AgreementCertificate\Models\Certificate;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Filament\Admin\Resources\Certificates\CertificatesResource;
use App\Platform\DocumentVault\Actions\PromoteDocument;
use App\Platform\DocumentVault\Actions\ScanDocument;
use App\Platform\DocumentVault\Actions\UploadDocument;
use App\Platform\DocumentVault\DocumentKind;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * The 'Terbitkan Sertifikat' header action on `ListCertificates` — the
 * CreateAction that issues the FIRST certificate version for a subject.
 *
 * ---------------------------------------------------------------------------
 * Two-layer issuer gate, same shape as the P1 action factories
 * ---------------------------------------------------------------------------
 * `->authorize(fn (): bool => self::isIssuer())` is the RENDER/mount gate
 * (`admin` / `restricted_admin` only — operator and finance may VIEW the
 * resource but never see or run this action). `run()` re-checks the same
 * predicate as its first act, because "the button was not rendered" is not
 * a security property — the same two-layer pattern `TransitionOrderAction`
 * and `ReservePlotAction` document, matching the domain-level
 * `CertificateIssuerAuthorizer` gate the Actions enforce independently.
 *
 * ---------------------------------------------------------------------------
 * The modal: subject select + real vault document upload
 * ---------------------------------------------------------------------------
 * The subject select lists ELIGIBLE subjects — paid orders that do not yet
 * hold an issued certificate row — whose option VALUE is the polymorphic
 * owner as `{FQCN}|{id}` (the no-morph-map convention: `Order::class`
 * verbatim, never a map alias), so Lane 2 can extend the surface to the
 * pre-need case without changing the seam.
 *
 * The document is a REAL vault upload through the `UploadDocument` seam
 * with `DocumentKind::Certificate`. Because `IssueCertificate` refuses any
 * document that is not `DocumentState::Accepted`, and the combined
 * dev/development host has no always-on media worker (AGENTS.md:
 * "Development and batch workers run on demand"), this MANUAL issuance
 * drives the deterministic quarantine → scan → promote pipeline
 * synchronously (`ScanDocument` then `PromoteDocument`) before issuing — a
 * judgement call, documented here rather than assumed: the invariants
 * (quarantine-first, clean-scan-before-promotion, accepted-before-issuance)
 * are unchanged and enforced by the same platform Actions; only the
 * synchronous invocation differs from the async job path. The uploaded
 * ScanDocumentJob/outbox rows still land through `UploadDocument`'s own
 * machinery, so no platform bookkeeping is skipped.
 *
 * Eligibility is pre-checked through `CertificateEligibilityPolicy`
 * (cheap, avoids creating a vault document for an ineligible subject) and
 * re-checked authoritatively by `IssueCertificate` inside its transaction.
 *
 * Every failure surfaces as an honest notification, never a 500 — state is
 * only ever written by the domain Actions.
 */
final class CreateCertificateAction
{
    /**
     * The closed set of subjects the creation surface may target — the
     * FQCN is taken from the select VALUE, so it is only ever one of these.
     *
     * @var list<class-string>
     */
    private const array SUBJECT_TYPES = [Order::class];

    public static function make(): Action
    {
        return Action::make('terbitkan')
            ->label('Terbitkan Sertifikat')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->color('success')
            ->authorize(fn (): bool => self::isIssuer())
            ->schema(self::schema())
            ->action(fn (array $data) => self::run($data));
    }

    /**
     * @return array<Select|FileUpload>
     */
    private static function schema(): array
    {
        return [
            Select::make('subject')
                ->label('Subjek sertifikat')
                ->options(fn (): array => self::subjectOptions())
                ->helperText('Pesanan yang telah dibayar dan belum memiliki sertifikat terbit.')
                ->required(),

            FileUpload::make('document_file')
                ->label('Dokumen sertifikat (PDF)')
                ->disk('local')
                ->directory('certificate-uploads')
                ->acceptedFileTypes(['application/pdf'])
                ->maxSize(10240)
                ->required()
                ->helperText('PDF maksimal 10 MB. Dokumen melewati pemindaian keamanan (karantina) sebelum sertifikat diterbitkan.'),
        ];
    }

    public static function isIssuer(): bool
    {
        if (! CertificatesResource::canAccess()) {
            return false;
        }

        $actor = app(ActorContext::class);

        return $actor->hasRole(ActorRole::ADMIN)
            || $actor->hasRole(ActorRole::RESTRICTED_ADMIN);
    }

    /**
     * The eligible-subject options: paid orders that do not yet hold an
     * issued `ORDER_SETTLEMENT` certificate — the sanctioned re-issue path
     * for a subject that already carries one is 'Ganti' (replace), not a
     * second issuance through this action.
     *
     * @return array<string, string>
     */
    private static function subjectOptions(): array
    {
        return self::eligibleOrders()
            ->mapWithKeys(fn (Order $order): array => [
                Order::class.'|'.$order->getKey() => $order->reference,
            ])
            ->all();
    }

    /**
     * @return Collection<int, Order>
     */
    private static function eligibleOrders(): Collection
    {
        return Order::query()
            ->where('status', OrderStatus::DIBAYAR->value)
            ->whereNotIn(
                'id',
                Certificate::query()
                    ->where('type', CertificateType::OrderSettlement->value)
                    ->pluck('subject_id'),
            )
            ->orderBy('reference')
            ->get();
    }

    private static function run(array $data): void
    {
        $actor = app(ActorContext::class);

        if (! self::isIssuer()) {
            self::deny('Anda tidak berwenang menerbitkan sertifikat.');

            return;
        }

        $subject = self::resolveSubject((string) ($data['subject'] ?? ''));

        if (! $subject instanceof Order) {
            self::deny('Subjek tidak ditemukan atau tidak dikenali.');

            return;
        }

        if (! app(CertificateEligibilityPolicy::class)->eligibleFor(CertificateType::OrderSettlement->value, $subject)) {
            self::deny('Syarat sertifikat belum terpenuhi.');

            return;
        }

        try {
            $document = self::uploadAndAcceptDocument($subject, (string) ($data['document_file'] ?? ''));

            $certificate = app(IssueCertificate::class)(
                CertificateType::OrderSettlement,
                $subject,
                (string) $actor->identityReference,
                CertificatesResource::auditRoleFor($actor),
                $document->getKey(),
            );

            Notification::make()->success()->title('Sertifikat diterbitkan.')->send();
            redirect()->route('filament.admin.resources.certificates.view', ['record' => $certificate->getKey()]);
        } catch (CertificateIssuerNotAuthorisedException $exception) {
            self::deny($exception->getMessage());
        } catch (CertificateEligibilityNotMetException $exception) {
            self::deny($exception->getMessage());
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Penerbitan sertifikat gagal')
                ->body($exception->getMessage())
                ->send();
        }
    }

    private static function resolveSubject(string $value): ?Order
    {
        if (! str_contains($value, '|')) {
            return null;
        }

        [$subjectType, $subjectId] = explode('|', $value, 2);

        if (! in_array($subjectType, self::SUBJECT_TYPES, true) || ! class_exists($subjectType)) {
            return null;
        }

        /** @var class-string<Order> $subjectType */
        $subject = $subjectType::query()->whereKey($subjectId)->first();

        return $subject instanceof Order ? $subject : null;
    }

    /**
     * Read the temporarily-stored PDF (Filament's FileUpload staging path
     * on the `local` disk), hand it to the `UploadDocument` quarantine
     * seam, then drive quarantine → scanning → accepted so the returned
     * document satisfies `IssueCertificate`'s Accepted-only check.
     */
    private static function uploadAndAcceptDocument(Order $subject, string $storedPath): Document
    {
        $storage = Storage::disk('local');
        $tempPath = null;

        try {
            if (! $storage->exists($storedPath)) {
                throw new InvalidArgumentException('Berkas sertifikat tidak ditemukan pada penyimpanan sementara.');
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'certificate-upload-');

            if ($tempPath === false) {
                throw new InvalidArgumentException('Tidak dapat membuat berkas sementara untuk dokumen sertifikat.');
            }

            file_put_contents($tempPath, $storage->get($storedPath));

            $file = new UploadedFile($tempPath, basename($storedPath), 'application/pdf', null, true);

            $document = app(UploadDocument::class)->upload(
                DocumentKind::Certificate,
                $file,
                Order::class,
                (string) $subject->getKey(),
                null,
                ['mime_declared' => 'application/pdf'],
            );

            app(ScanDocument::class)->scan($document);

            return app(PromoteDocument::class)->promote($document->fresh());
        } finally {
            if (is_string($tempPath) && $tempPath !== '' && is_file($tempPath)) {
                unlink($tempPath);
            }

            $storage->delete($storedPath);
        }
    }

    private static function deny(string $title): void
    {
        Notification::make()->danger()->title($title)->send();
    }
}
