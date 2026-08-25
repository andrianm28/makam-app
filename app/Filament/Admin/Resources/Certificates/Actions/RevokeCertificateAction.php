<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Certificates\Actions;

use App\Domain\AgreementCertificate\Actions\RevokeCertificate;
use App\Domain\AgreementCertificate\CertificateStatus;
use App\Domain\AgreementCertificate\Models\Certificate;
use App\Filament\Admin\Resources\Certificates\CertificatesResource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * The 'Cabut' header action on `ViewCertificate` — revoke an issued
 * certificate (`Actions\RevokeCertificate`): issued → revoked, audited
 * `CERTIFICATE_REVOKED`. A non-blank reason is required — enforced by the
 * action's own form rule AND by `RevokeCertificate` (the platform's
 * mandatory-reason control for this module).
 *
 * Same two-layer issuer gate as `CreateCertificateAction`: `->visible()`
 * draws the button only on an issued row, `->authorize()` admits only
 * issuers (admin/restricted_admin), and `run()` re-checks the same
 * predicate before any write.
 */
final class RevokeCertificateAction
{
    public static function make(Certificate $certificate): Action
    {
        return Action::make('cabut')
            ->label('Cabut Sertifikat')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (): bool => $certificate->status === CertificateStatus::Issued->value)
            ->authorize(fn (): bool => self::isIssuer())
            ->requiresConfirmation()
            ->modalHeading('Cabut sertifikat ini?')
            ->modalDescription(
                'Sertifikat yang dicabut tidak lagi berlaku. '
                .'Alasan wajib diisi dan tercatat di audit.'
            )
            ->schema([
                Textarea::make('reason')
                    ->label('Alasan pencabutan')
                    ->required()
                    ->maxLength(1000)
                    ->helperText('Tercatat di jejak audit.'),
            ])
            ->action(fn (array $data) => self::run($certificate, (string) $data['reason']));
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

    private static function run(Certificate $certificate, string $reason): void
    {
        $actor = app(ActorContext::class);

        if (! self::isIssuer()) {
            Notification::make()->danger()->title('Anda tidak berwenang mencabut sertifikat.')->send();

            return;
        }

        try {
            app(RevokeCertificate::class)(
                $certificate,
                (string) $actor->identityReference,
                CertificatesResource::auditRoleFor($actor),
                $reason,
            );

            Notification::make()->success()->title('Sertifikat dicabut.')->send();
            redirect()->route('filament.admin.resources.sertifikat.view', ['record' => $certificate->getKey()]);
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Pencabutan sertifikat gagal')
                ->body($exception->getMessage())
                ->send();
        }
    }
}
