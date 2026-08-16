<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Certificates\Pages;

use App\Domain\AgreementCertificate\Models\Certificate;
use App\Filament\Admin\Resources\Certificates\Actions\ReplaceCertificateAction;
use App\Filament\Admin\Resources\Certificates\Actions\RevokeCertificateAction;
use App\Filament\Admin\Resources\Certificates\CertificatesResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * View page for `CertificatesResource` — the certificate's status detail
 * (infolist) plus the two per-state header actions: 'Cabut' (revoke) and
 * 'Ganti' (replace), each only visible on an issued row and each
 * issuer-gated in both layers inside its factory. This page never switches
 * on the status itself — the action factories own their visibility.
 */
final class ViewCertificate extends ViewRecord
{
    protected static string $resource = CertificatesResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var Certificate $certificate */
        $certificate = $this->getRecord();

        return [
            RevokeCertificateAction::make($certificate),
            ReplaceCertificateAction::make($certificate),
        ];
    }
}
