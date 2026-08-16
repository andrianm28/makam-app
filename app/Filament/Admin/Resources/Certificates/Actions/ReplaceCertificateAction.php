<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Certificates\Actions;

use App\Domain\AgreementCertificate\Actions\ReplaceCertificate;
use App\Domain\AgreementCertificate\CertificateStatus;
use App\Domain\AgreementCertificate\Models\Certificate;
use App\Domain\OrderWorkflow\Models\Order;
use App\Filament\Admin\Resources\Certificates\CertificatesResource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * The 'Ganti' header action on `ViewCertificate` — replace an issued
 * certificate (`Actions\ReplaceCertificate`): the incumbent is marked
 * `replaced`, the NEXT version row is issued (v+1), history preserved
 * (AC5), audited `CERTIFICATE_REPLACED` + outbox `certificate.replaced.v1`.
 *
 * Reached only from an issued row (`->visible()`), issuer-gated in both
 * layers, and the subject is re-derived from the incumbent row itself — a
 * replacement can never re-target a different subject (the domain action
 * re-asserts the same check inside the transaction).
 *
 * The replacement intentionally carries no NEW vault-document upload in
 * this lane — the brief scopes the document upload to issuance; a
 * replacement document re-upload seam lands with the pre-need settlement
 * flow (P5a Lane 2).
 */
final class ReplaceCertificateAction
{
    public static function make(Certificate $certificate): Action
    {
        return Action::make('ganti')
            ->label('Ganti Sertifikat')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (): bool => $certificate->status === CertificateStatus::Issued->value)
            ->authorize(fn (): bool => self::isIssuer())
            ->requiresConfirmation()
            ->modalHeading('Ganti sertifikat ini?')
            ->modalDescription(
                'Versi baru diterbitkan dan riwayat sebelumnya dipertahankan (AC5). '
                .'Alasan opsional tercatat di audit.'
            )
            ->schema([
                Textarea::make('reason')
                    ->label('Alasan penggantian')
                    ->maxLength(1000)
                    ->helperText('Opsional; tercatat di jejak audit.'),
            ])
            ->action(
                fn (array $data) => self::run(
                    $certificate,
                    filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                ),
            );
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

    private static function run(Certificate $certificate, ?string $reason): void
    {
        $actor = app(ActorContext::class);

        if (! self::isIssuer()) {
            Notification::make()->danger()->title('Anda tidak berwenang mengganti sertifikat.')->send();

            return;
        }

        $subject = self::resolveSubject($certificate);

        if (! $subject instanceof Order) {
            Notification::make()
                ->danger()
                ->title('Subjek sertifikat tidak dapat di-resolve.')
                ->send();

            return;
        }

        try {
            $replacement = app(ReplaceCertificate::class)(
                $certificate,
                $subject,
                (string) $actor->identityReference,
                CertificatesResource::auditRoleFor($actor),
                null,
                $reason,
            );

            Notification::make()->success()->title('Sertifikat diganti.')->send();
            redirect()->route('filament.admin.resources.certificates.view', [
                'record' => $replacement->getKey(),
            ]);
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Penggantian sertifikat gagal')
                ->body($exception->getMessage())
                ->send();
        }
    }

    private static function resolveSubject(Certificate $certificate): ?Order
    {
        if ($certificate->subject_type !== Order::class) {
            return null;
        }

        $subject = Order::query()->whereKey($certificate->subject_id)->first();

        return $subject instanceof Order ? $subject : null;
    }
}
