<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Certificates\Pages;

use App\Filament\Admin\Resources\Certificates\Actions\CreateCertificateAction;
use App\Filament\Admin\Resources\Certificates\CertificatesResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for `CertificatesResource` — the certificate register, plus the
 * header 'Terbitkan' action (the CreateAction: subject select + vault
 * document upload → `IssueCertificate`). The action is issuer-gated in both
 * layers inside its factory (`->authorize()` + the in-closure re-check).
 */
final class ListCertificates extends ListRecords
{
    protected static string $resource = CertificatesResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateCertificateAction::make(),
        ];
    }
}
