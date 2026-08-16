<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Agreements\Actions;

use App\Domain\AgreementCertificate\Actions\SupersedeAgreement;
use App\Domain\AgreementCertificate\AgreementStatus;
use App\Domain\AgreementCertificate\Models\Agreement;
use App\Filament\Admin\Resources\Agreements\AgreementsResource;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * The 'Supersesi' header action on `ViewAgreement` — AC5's agreement half:
 * the incumbent (accepted or active) is marked `superseded`, the NEXT
 * version row is created back in `draft` for a fresh AC2 acceptance, and
 * the earlier rows are preserved untouched. Audited `AGREEMENT_SUPERSEDED`.
 *
 * Visible only on an accepted or active row (a draft cannot supersede, and
 * an already-superseded row is history). The new draft version is
 * pre-populated from the incumbent's AC4 display fields.
 */
final class SupersedeAgreementAction
{
    public static function make(Agreement $agreement): Action
    {
        return Action::make('supersesi')
            ->label('Supersesi')
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->color('warning')
            ->visible(fn (): bool => in_array(
                $agreement->status,
                [AgreementStatus::Accepted->value, AgreementStatus::Active->value],
                true,
            ))
            ->requiresConfirmation()
            ->modalHeading('Buat versi baru perjanjian?')
            ->modalDescription(
                'Versi ini disupersesi dan versi baru mulai sebagai draf; '
                .'riwayat sebelumnya dipertahankan (AC5). Versi baru harus diterima kembali (AC2).'
            )
            ->action(fn () => self::run($agreement));
    }

    private static function run(Agreement $agreement): void
    {
        $actor = app(ActorContext::class);

        try {
            $next = app(SupersedeAgreement::class)(
                $agreement,
                (string) $actor->identityReference,
                AgreementsResource::auditRoleFor($actor),
            );

            Notification::make()->success()->title('Versi baru perjanjian dibuat.')->send();
            redirect()->route('filament.admin.resources.agreements.view', [
                'record' => $next->getKey(),
            ]);
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Supersesi perjanjian gagal')
                ->body($exception->getMessage())
                ->send();
        }
    }
}
